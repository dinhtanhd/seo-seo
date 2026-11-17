#!/usr/bin/env python3
"""Stream PostgreSQL fingerprints for BaoTa transfer without loading catalog rows into RAM."""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import uuid

ROOT = Path(__file__).resolve().parents[1]
TABLES = (
    'users', 'sites', 'site_domains', 'site_memberships', 'categories',
    'source_connectors', 'source_site_bindings', 'source_type_catalogs',
    'content_items', 'content_revisions', 'pages', 'page_redirects',
    'fanmulu_url_registry', 'page_publications', 'snapshot_fragments',
    'keyword_pools', 'keyword_groups', 'keywords', 'keyword_versions',
    'keyword_bindings', 'site_keyword_limits', 'page_keyword_assignments',
    'allocation_state', 'allocation_batch_reservations', 'keyword_capacity_claims',
    'templates', 'template_versions', 'rules', 'rule_versions',
    'movie_themes', 'movie_theme_versions', 'theme_assets',
    'template_variable_sets', 'template_variable_set_versions',
    'template_variables', 'template_variable_versions',
    'page_template_variable_values', 'template_variable_decks',
)
REQUIRED = frozenset((
    'users', 'sites', 'site_domains', 'content_items', 'content_revisions',
    'pages', 'fanmulu_url_registry', 'page_publications', 'keywords',
    'page_keyword_assignments', 'page_template_variable_values',
))
HEX = re.compile(rb'[0-9a-f]{64}\n')


def identifier(value):
    return '"' + value.replace('"', '""') + '"'


def metadata_sql(table):
    if table not in TABLES:
        raise ValueError('Bảng ngoài phạm vi đối chiếu.')
    return f"""SELECT json_build_object(
 'columns', (SELECT json_agg(json_build_object('name', a.attname,
 'type', format_type(a.atttypid,a.atttypmod), 'category', t.typcategory)
 ORDER BY a.attnum) FROM pg_attribute a JOIN pg_type t ON t.oid=a.atttypid
 WHERE a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped),
 'key', COALESCE((SELECT json_agg(a.attname ORDER BY k.ordinality)
 FROM pg_index i CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY k(attnum,ordinality)
 JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=k.attnum
 WHERE i.indrelid=c.oid AND i.indisprimary), '[]'::json))::text
 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE n.nspname='public' AND c.relname='{table}' AND c.relkind IN ('r','p');"""


def digest_sql(table, metadata):
    if table not in TABLES:
        raise ValueError('Bảng ngoài phạm vi đối chiếu.')
    columns = {column['name']: column for column in metadata['columns']}
    order = []
    for key in metadata['key']:
        column = columns[key]
        expression = 't.' + identifier(key)
        if column['category'] == 'S':
            expression += ' COLLATE "C"'
        order.append(expression)
    digest = "encode(sha256(convert_to(to_jsonb(t)::text,'UTF8')),'hex')"
    # A digest sort is a bounded-memory PostgreSQL fallback for a table without a PK.
    ordering = ', '.join(order) if order else digest + ' COLLATE "C"'
    return f'COPY (SELECT {digest} FROM public.{identifier(table)} t ORDER BY {ordering}) TO STDOUT;'


def digest_lines(lines):
    checksum = hashlib.sha256()
    count = 0
    for line in lines:
        if not HEX.fullmatch(line):
            raise ValueError('Dòng fingerprint PostgreSQL không hợp lệ; không xuất nội dung dữ liệu.')
        checksum.update(line)
        count += 1
    return {'count': count, 'sha256': checksum.hexdigest()}


class PsqlSession:
    """One read-only repeatable-read snapshot, streaming framed query results."""
    def __init__(self, command, env=None):
        self.process = subprocess.Popen(command, cwd=ROOT, env=env,
                                        stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                        stderr=subprocess.DEVNULL)
        self.write("BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;\n"
                   "SET LOCAL timezone='UTC'; SET LOCAL datestyle='ISO, YMD';\n"
                   "SET LOCAL intervalstyle='postgres'; SET LOCAL extra_float_digits=3;\n"
                   "SET LOCAL bytea_output='hex'; SET LOCAL work_mem='16MB';\n")

    def write(self, sql):
        try:
            self.process.stdin.write(sql.encode('utf-8'))
            self.process.stdin.flush()
        except (OSError, BrokenPipeError):
            raise ValueError('Không gửi được truy vấn fingerprint tới PostgreSQL.') from None

    def lines(self, sql):
        marker = ('TRAM_END_' + uuid.uuid4().hex).encode()
        self.write(sql + '\n\\echo ' + marker.decode() + '\n')
        while True:
            line = self.process.stdout.readline(1024 * 1024 + 1)
            if not line:
                raise ValueError('PostgreSQL không hoàn thành fingerprint; kiểm tra kết nối/quyền/schema.')
            if len(line) > 1024 * 1024:
                raise ValueError('Metadata PostgreSQL vượt giới hạn; không xuất nội dung dữ liệu.')
            if line.rstrip(b'\r\n') == marker:
                return
            yield line

    def metadata(self, table):
        rows = list(self.lines(metadata_sql(table)))
        if not rows:
            return None
        if len(rows) != 1:
            raise ValueError('Metadata bảng PostgreSQL không duy nhất.')
        try:
            return json.loads(rows[0])
        except (ValueError, UnicodeError):
            raise ValueError('Không đọc được metadata PostgreSQL.') from None

    def close(self):
        try:
            self.write('ROLLBACK;\n\\q\n')
        except ValueError:
            pass
        try:
            self.process.stdin.close()
        except OSError:
            pass
        try:
            self.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            self.process.kill()
            self.process.wait()
        self.process.stdout.close()


def snapshot(session, progress=False):
    tables = {}
    for table in TABLES:
        metadata = session.metadata(table)
        if metadata is None:
            if table in REQUIRED:
                raise ValueError('Thiếu bảng bắt buộc: ' + table)
            tables[table] = {'present': False}
            continue
        if progress:
            print('Đối chiếu: ' + table, file=sys.stderr, flush=True)
        tables[table] = {'present': True, 'schema': metadata,
                         **digest_lines(session.lines(digest_sql(table, metadata)))}
    return {'format': 'tram-baota-preservation-v1',
            'algorithm': 'sha256-of-ordered-sha256-row-jsonb-lines',
            'created_at': datetime.now(timezone.utc).isoformat(),
            'tables': tables}


def compare(before, after):
    if before.get('format') != 'tram-baota-preservation-v1' or before.get('algorithm') != after['algorithm']:
        raise ValueError('Định dạng manifest bảo toàn dữ liệu chưa được hỗ trợ.')
    if set(before.get('tables', {})) != set(TABLES):
        raise ValueError('Manifest không đủ đúng danh sách bảng bảo toàn dữ liệu.')
    mismatches = [table for table in TABLES if before['tables'][table] != after['tables'][table]]
    if mismatches:
        raise ValueError('Dữ liệu khôi phục chưa khớp: ' + ', '.join(mismatches))
    return sum(item.get('count', 0) for item in after['tables'].values())


@contextmanager
def native_connection(path, pg_bin):
    helper = ROOT / 'scripts/baota-config.py'
    spec = importlib.util.spec_from_file_location('baota_config', helper)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    connection = module.read_connections(Path(path))
    env = os.environ.copy()
    for key in ('PGPASSWORD', 'PGSERVICE', 'PGSERVICEFILE', 'PGOPTIONS'):
        env.pop(key, None)
    mapping = {'PGHOST': 'DB_HOST', 'PGPORT': 'DB_PORT', 'PGDATABASE': 'DB_DATABASE',
               'PGUSER': 'DB_USERNAME', 'PGSSLMODE': 'DB_SSLMODE'}
    env.update({name: connection[value] for name, value in mapping.items()})
    env.update(PGCONNECT_TIMEOUT='10', PGCLIENTENCODING='UTF8')
    with tempfile.TemporaryDirectory(prefix='tram-pgpass-') as directory:
        password_file = Path(directory) / 'pgpass'
        fields = [connection[name] for name in ('DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD')]
        if fields[0].startswith('/'):
            fields[0] = '*'
        escape = lambda value: value.replace('\\', '\\\\').replace(':', '\\:')
        descriptor = os.open(password_file, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, 'w') as stream:
            stream.write(':'.join(escape(value) for value in fields) + '\n')
        env['PGPASSFILE'] = str(password_file)
        yield [str(Path(pg_bin) / 'psql'), '-X', '-w', '-qAt', '-v', 'ON_ERROR_STOP=1'], env


def docker_command(container_id=None, database=None):
    if container_id:
        if not re.fullmatch(r'[0-9a-f]{12,64}', container_id):
            raise ValueError('Container ID PostgreSQL không hợp lệ.')
        command = ['docker', 'exec', '-i', container_id]
    else:
        command = ['docker', 'compose', '--project-name', 'tram-seo', '--project-directory', str(ROOT),
                   '-f', str(ROOT / 'compose.yaml')]
        if os.environ.get('TRAM_PRODUCTION') == '1':
            command += ['-f', str(ROOT / 'infra/compose.production.yaml')]
        command += ['exec', '-T', 'postgres']
    if database and not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,62}', database):
        raise ValueError('Tên database PostgreSQL không hợp lệ.')
    return command + ['sh', '-ec',
                      'exec psql -X -w -qAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "${1:-$POSTGRES_DB}"',
                      'sh', database or '']


def save_new(path, manifest):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w') as stream:
        json.dump(manifest, stream, ensure_ascii=False, indent=2)
        stream.write('\n')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest='action', required=True)
    take = subparsers.add_parser('snapshot', help='Chỉ chạy trong cửa sổ export đã dừng các tiến trình ghi.')
    take.add_argument('--docker', action='store_true', required=True)
    take.add_argument('--container-id')
    take.add_argument('--database')
    take.add_argument('--output', required=True, type=Path)
    verify = subparsers.add_parser('verify', help='Đối chiếu DB đích trước migration và khởi động job.')
    verify.add_argument('--connections-file', required=True, type=Path)
    verify.add_argument('--pg-bin', required=True)
    verify.add_argument('--manifest', required=True, type=Path)
    args = parser.parse_args()
    session = None
    try:
        if args.action == 'snapshot':
            if args.output.exists():
                raise ValueError('File manifest đã tồn tại; không ghi đè.')
            session = PsqlSession(docker_command(args.container_id, args.database))
            manifest = snapshot(session, progress=True)
            save_new(args.output, manifest)
            print('Đã ghi manifest bảo toàn dữ liệu (không chứa nội dung hoặc mật khẩu).')
        else:
            before = json.loads(args.manifest.read_text())
            with native_connection(args.connections_file, args.pg_bin) as (command, env):
                session = PsqlSession(command, env)
                after = snapshot(session, progress=True)
                count = compare(before, after)
                session.close()
                session = None
            print(f'PASS: {len(TABLES)} nhóm bảng, {count} bản ghi khớp manifest nguồn.')
        return 0
    except (OSError, ValueError, KeyError, TypeError) as error:
        if isinstance(error, (KeyError, TypeError)):
            print('Manifest bảo toàn dữ liệu không hợp lệ.', file=sys.stderr)
        else:
            print(str(error), file=sys.stderr)
        return 1
    finally:
        if session is not None:
            session.close()


if __name__ == '__main__':
    raise SystemExit(main())
