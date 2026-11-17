#!/usr/bin/env python3
"""Prepare a private database connection file or stage a trusted PostgreSQL SQL dump."""

import argparse
import base64
import getpass
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shlex
import shutil
import stat
import subprocess
import tempfile


CONFIG_PATH = Path(__file__).with_name('baota-config.py')
CONFIG_SPEC = importlib.util.spec_from_file_location('baota_config', CONFIG_PATH)
CONFIG = importlib.util.module_from_spec(CONFIG_SPEC)
CONFIG_SPEC.loader.exec_module(CONFIG)
IDENTIFIER = re.compile(r'[A-Za-z_][A-Za-z_0-9]{0,62}\Z')
DEFAULT_SOCKET_DIRS = ('/tmp', '/var/run/postgresql', '/run/postgresql')


def prompt(label, default):
    return input(f'{label} [{default}]: ').strip() or default


def pg_identifier(name):
    if not IDENTIFIER.fullmatch(name):
        raise ValueError('Tên database và user chỉ gồm chữ, số, gạch dưới và bắt đầu bằng chữ hoặc gạch dưới.')
    return '"' + name + '"'


def pg_env(settings=None):
    # Inherited PGOPTIONS, service files and credentials must never select the target.
    env = {key: value for key, value in os.environ.items() if not key.startswith('PG')}
    env.update(PGCONNECT_TIMEOUT='5', PGPASSFILE='/dev/null')
    if settings is not None:
        env.update(PGHOST=settings['DB_HOST'], PGPORT=settings['DB_PORT'],
                   PGDATABASE=settings['DB_DATABASE'], PGUSER=settings['DB_USERNAME'],
                   PGSSLMODE=settings['DB_SSLMODE'])
    return env


def run(command, env=None, data=None, stdin=None, stdout=subprocess.PIPE, timeout=20):
    return subprocess.run(
        command, env=env, input=data, stdin=stdin, stdout=stdout,
        stderr=subprocess.PIPE, timeout=timeout, cwd='/', check=False,
    )


def resolve_pg_bin(provided):
    candidates = ([provided] if provided is not None else
                  [Path(p) for p in ('/www/server/pgsql/bin', '/www/server/pgsql/18/bin', '/usr/pgsql-18/bin', '/usr/bin')])
    for candidate in candidates:
        if not candidate.is_absolute() or not all(os.access(candidate / name, os.X_OK) for name in ('psql', 'pg_dump', 'pg_restore')):
            continue
        version = run([str(candidate / 'pg_dump'), '--version'], env=pg_env())
        if version.returncode == 0 and re.search(rb'\(PostgreSQL\) 18\.', version.stdout):
            return candidate
    raise ValueError('Không tìm thấy bộ công cụ PostgreSQL 18. Chỉ định đường dẫn bằng --pg-bin.')


def peer_admin(pg_bin, port, socket_dir):
    # Check both version and peer role, without asking for or exposing the panel's password.
    for directory in ((socket_dir,) if socket_dir else DEFAULT_SOCKET_DIRS):
        if not Path(directory).is_absolute():
            raise ValueError('Thư mục socket PostgreSQL cần là đường dẫn tuyệt đối.')
        command = ['runuser', '-u', 'postgres', '--', str(pg_bin / 'psql'), '-XwAtq',
                   '-v', 'ON_ERROR_STOP=1', '-h', directory, '-p', port, '-U', 'postgres', '-d', 'postgres']
        version = run(command + ['-c', "SELECT current_setting('server_version_num')"], env=pg_env())
        if version.returncode == 0 and re.fullmatch(rb'18[0-9]{4}', version.stdout.strip()):
            return command
    raise ValueError('Không kết nối được PostgreSQL 18 qua user hệ thống postgres. Nếu socket ở đường dẫn khác, dùng --socket-dir. Với database đã tạo trong BaoTa, có thể chọn dùng database có sẵn.')


def admin_sql(command, sql):
    result = run(command, env=pg_env(), data=(sql + '\n').encode())
    if result.returncode:
        raise ValueError('PostgreSQL từ chối thao tác tạo/xóa database hoặc user. Kiểm tra quyền của user hệ thống postgres.')
    return result.stdout.strip()


def scram_verifier(password):
    iterations = 4096
    salt = secrets.token_bytes(16)
    salted = hashlib.pbkdf2_hmac('sha256', password.encode(), salt, iterations)
    stored = hashlib.sha256(hmac.digest(salted, b'Client Key', 'sha256')).digest()
    server = hmac.digest(salted, b'Server Key', 'sha256')
    encoded_salt, encoded_stored, encoded_server = (base64.b64encode(value).decode() for value in (salt, stored, server))
    return f'SCRAM-SHA-256${iterations}:{encoded_salt}${encoded_stored}:{encoded_server}'


def create_database(admin, database, username, password):
    database_sql, username_sql = pg_identifier(database), pg_identifier(username)
    exists = admin_sql(admin, f"SELECT EXISTS (SELECT FROM pg_database WHERE datname='{database}') OR EXISTS (SELECT FROM pg_roles WHERE rolname='{username}');")
    if exists != b'f':
        raise ValueError('Database hoặc user đã tồn tại. Chọn kết nối có sẵn hoặc tên khác.')
    # PostgreSQL accepts the finished verifier. The cleartext password stays out of SQL and logs.
    verifier = scram_verifier(password)
    admin_sql(admin, f"CREATE ROLE {username_sql} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS PASSWORD '{verifier}';")
    try:
        admin_sql(admin, f'CREATE DATABASE {database_sql} OWNER {username_sql};')
    except (OSError, ValueError, KeyboardInterrupt, subprocess.SubprocessError):
        admin_sql(admin, f'DROP ROLE {username_sql};')
        raise


def drop_database(admin, database, username):
    admin_sql(admin, f'DROP DATABASE {pg_identifier(database)} WITH (FORCE);')
    admin_sql(admin, f'DROP ROLE {pg_identifier(username)};')


def pgpass(path, settings):
    host = settings['DB_HOST']
    fields = ['*' if host.startswith('/') else host, settings['DB_PORT'],
              settings['DB_DATABASE'], settings['DB_USERNAME'], settings['DB_PASSWORD']]
    path.write_text(':'.join(field.replace('\\', '\\\\').replace(':', '\\:') for field in fields) + '\n')
    path.chmod(0o600)


def postgres_runtime(pg_bin, settings, env):
    command = [str(pg_bin / 'psql'), '-XwAtq', '-v', 'ON_ERROR_STOP=1']
    checks = (
        "SELECT current_setting('server_version_num'), current_database(), current_user, "
        "pg_has_role(current_user, 'pg_execute_server_program', 'member'), "
        "pg_has_role(current_user, 'pg_read_server_files', 'member'), "
        "pg_has_role(current_user, 'pg_write_server_files', 'member'), "
        "rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls "
        "FROM pg_catalog.pg_roles WHERE rolname=current_user"
    )
    result = run(command + ['-c', checks], env=env)
    valid = result.returncode == 0 and re.fullmatch(rb'18[0-9]{4}\|[^|]+\|[^|]+(?:\|f){8}', result.stdout.strip())
    if not valid:
        raise ValueError('Cần đăng nhập được PostgreSQL 18 bằng user database riêng, không có quyền quản trị máy chủ.')
    database, username = result.stdout.decode().strip().split('|')[1:3]
    if (database, username) != (settings['DB_DATABASE'], settings['DB_USERNAME']):
        raise ValueError('Database/user hiệu lực khác cấu hình đã chọn.')
    return command


def redis_password(host, port):
    if host not in ('127.0.0.1', 'localhost', '::1') or port != '6379':
        return None
    path = Path('/www/server/redis/redis.conf')
    if not path.is_file():
        return None
    values = []
    for line in path.read_text().splitlines():
        if re.match(r'\s*requirepass\s+', line, re.I):
            try:
                parts = shlex.split(line, comments=True)
            except ValueError:
                return None
            if len(parts) == 2:
                values.append(parts[1])
    return values[0] if len(values) == 1 and values[0] else None


def redis_runtime(php_root, settings):
    command = [str(php_root / 'bin/php'), '-r', '''
try {
    $c = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $host = $c['REDIS_HOST'];
    $redis = new Redis;
    if (!$redis->connect($host, str_starts_with($host, '/') ? 0 : (int)$c['REDIS_PORT'], 5)) exit(1);
    if ($c['REDIS_PASSWORD'] !== null && !$redis->auth($c['REDIS_USERNAME'] === null
        ? $c['REDIS_PASSWORD'] : [$c['REDIS_USERNAME'], $c['REDIS_PASSWORD']])) exit(1);
    exit($redis->ping() ? 0 : 1);
} catch (Throwable) { exit(1); }
''']
    parameters = {key: value for key, value in settings.items() if key.startswith('REDIS_')}
    return run(command, data=json.dumps(parameters).encode()).returncode == 0


def connections_text(settings):
    return ''.join(f'{key}={CONFIG.dotenv_quote(value)}\n' for key, value in settings.items())


def validate_connections(settings, directory):
    sample = directory / 'connections.env'
    sample.write_text(connections_text(settings))
    return CONFIG.read_connections(sample)


def existing_connections(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o077:
        raise ValueError('File kết nối phải là file thường của root có mode 600 hoặc 400.')
    return CONFIG.read_connections(path)


def destination(path):
    if not path.is_absolute() or not path.parent.is_dir() or path.exists() or path.is_symlink():
        raise ValueError('Chọn đường dẫn tuyệt đối tới file mới trong một thư mục đã tồn tại.')


def choose_connection(pg_bin, php_root, output, db_name, socket_dir):
    destination(output)
    print('1. Tạo database PostgreSQL 18 và user riêng trong BaoTa')
    print('2. Dùng database PostgreSQL 18 đã có')
    choice = prompt('Chọn cách kết nối', '1')
    if choice not in ('1', '2'):
        raise ValueError('Chọn 1 hoặc 2.')
    create = choice == '1'
    host = '127.0.0.1' if create else prompt('Host PostgreSQL hoặc thư mục socket', '127.0.0.1')
    port = prompt('Cổng PostgreSQL', '5432')
    database = prompt('Tên database', db_name)
    user = prompt('User database', database)
    password = secrets.token_urlsafe(32) if create else getpass.getpass('Mật khẩu PostgreSQL: ')
    sslmode = 'prefer' if create else prompt('SSL mode PostgreSQL', 'prefer')
    redis_host = prompt('Host Redis hoặc đường dẫn socket', '127.0.0.1')
    redis_port = prompt('Cổng Redis', '6379')
    settings = {'DB_HOST': host, 'DB_PORT': port, 'DB_DATABASE': database, 'DB_USERNAME': user,
                'DB_PASSWORD': password, 'DB_SSLMODE': sslmode, 'REDIS_HOST': redis_host,
                'REDIS_PORT': redis_port, 'REDIS_USERNAME': None, 'REDIS_PASSWORD': redis_password(redis_host, redis_port)}
    with tempfile.TemporaryDirectory(prefix='tram-db-') as temporary:
        directory = Path(temporary)
        settings = validate_connections(settings, directory)
        if not redis_runtime(php_root, settings):
            settings['REDIS_USERNAME'] = input('User Redis ACL [để trống nếu không có]: ').strip() or None
            settings['REDIS_PASSWORD'] = getpass.getpass('Mật khẩu Redis: ') or None
            settings = validate_connections(settings, directory)
            if not redis_runtime(php_root, settings):
                raise ValueError('Không kết nối/xác thực được Redis với thông tin đã nhập.')
        admin = peer_admin(pg_bin, settings['DB_PORT'], socket_dir) if create else None
        passfile = directory / 'pgpass'
        pgpass(passfile, settings)
        env = pg_env(settings)
        env['PGPASSFILE'] = str(passfile)
        created = False
        try:
            if create:
                create_database(admin, database, user, password)
                created = True
            postgres_runtime(pg_bin, settings, env)
            descriptor = os.open(output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
            with os.fdopen(descriptor, 'w') as stream:
                stream.write(connections_text(settings))
        except (OSError, ValueError, KeyboardInterrupt, subprocess.SubprocessError):
            if created:
                drop_database(admin, database, user)
            raise
    print('Kết nối PostgreSQL và Redis đạt. File cấu hình nằm trong thư mục riêng đã chọn.')


def restricted_dump(source, output):
    """Keep pg_dump's SQL and COPY bytes but substitute its psql restriction key."""
    key = secrets.token_hex(32).encode()
    output.write(b'\\restrict ' + key + b'\n')
    header, started, footer, closed = False, False, False, False
    existing_key = None
    for number, line in enumerate(source, 1):
        trimmed = line.strip()
        if number <= 10 and trimmed == b'-- PostgreSQL database dump':
            header = True
        if not header and number > 10:
            break
        restriction = re.fullmatch(rb'\\restrict ([A-Za-z0-9]+)', trimmed)
        if restriction and header and not started and existing_key is None:
            existing_key = restriction[1]
            continue
        # pg_dump adds the matching closing command only after its completion marker.
        if trimmed == b'-- PostgreSQL database dump complete':
            footer = True
        if existing_key is not None and not closed and footer and trimmed == b'\\unrestrict ' + existing_key:
            closed = True
            continue
        if closed and trimmed and not trimmed.startswith(b'--'):
            raise ValueError('File SQL có lệnh bổ sung sau phần kết thúc của pg_dump.')
        if trimmed and not trimmed.startswith(b'--'):
            started = True
        output.write(line)
    if not header or not footer or (existing_key is not None and not closed):
        raise ValueError('Cần SQL hoàn chỉnh tạo bằng pg_dump -Fp --no-owner --no-privileges. Dùng file .dump cho định dạng custom.')
    output.seek(0)


def normalize_sql(pg_bin, settings, source, output, socket_dir):
    destination(output)
    if not source.is_absolute() or not source.is_file() or source.is_symlink():
        raise ValueError('Nguồn SQL phải là một file thường có đường dẫn tuyệt đối.')
    if settings['DB_HOST'] not in ('127.0.0.1', 'localhost', '::1') and not settings['DB_HOST'].startswith('/'):
        raise ValueError('Chuẩn bị file SQL cần PostgreSQL 18 cục bộ. Với database từ xa, dùng file .dump tạo bằng pg_dump -Fc.')
    os_user = shutil.which('runuser')
    if os_user is None:
        raise ValueError('Cần runuser để chạy PostgreSQL client import SQL với user hệ thống nobody.')
    nobody = pwd.getpwnam('nobody')
    admin = peer_admin(pg_bin, settings['DB_PORT'], socket_dir)
    scratch = 'tram_sql_' + secrets.token_hex(12)
    stage = dict(settings, DB_DATABASE=scratch, DB_USERNAME=scratch, DB_PASSWORD=secrets.token_urlsafe(32))
    env = pg_env(stage)
    prefix = [os_user, '-u', 'nobody', '--']
    with tempfile.TemporaryDirectory(prefix='tram-import-', dir='/var/tmp') as temporary, \
            tempfile.TemporaryFile(mode='w+b') as script:
        directory = Path(temporary)
        directory.chmod(0o711)
        password_file = directory / 'pgpass'
        pgpass(password_file, stage)
        os.chown(password_file, nobody.pw_uid, nobody.pw_gid)
        env['PGPASSFILE'] = str(password_file)
        with source.open('rb') as stream:
            restricted_dump(stream, script)
        with tempfile.NamedTemporaryFile(prefix='.' + output.name + '-', dir=output.parent) as archive:
            create_database(admin, scratch, scratch, stage['DB_PASSWORD'])
            try:
                print('Đang kiểm tra và chuyển SQL trong database tạm...', flush=True)
                psql = [str(pg_bin / 'psql'), '-XwAtq', '-v', 'ON_ERROR_STOP=1']
                imported = run(prefix + psql + ['--single-transaction', '-f', '-'], env=env, stdin=script,
                               stdout=subprocess.DEVNULL, timeout=None)
                if imported.returncode:
                    line = re.search(rb'psql:<stdin>:(\d+):', imported.stderr)
                    location = f' (gần dòng {int(line[1]) - 1})' if line else ''
                    raise ValueError(f'Không nạp được SQL{location} vào database tạm. Kiểm tra file xuất pg_dump có --no-owner --no-privileges.')
                core = run(prefix + psql + ['-c', "SELECT pg_catalog.to_regclass('public.migrations') IS NOT NULL AND pg_catalog.to_regclass('public.sites') IS NOT NULL AND pg_catalog.to_regclass('public.content_items') IS NOT NULL"], env=env)
                if core.returncode or core.stdout.strip() != b't':
                    raise ValueError('File SQL không chứa bảng dữ liệu lõi của Trạm SEO.')
                archive_result = run(prefix + [str(pg_bin / 'pg_dump'), '-wFc', '--no-owner', '--no-privileges'],
                                     env=env, stdout=archive, timeout=None)
                if archive_result.returncode:
                    raise ValueError('Không tạo được bản dump custom từ database tạm.')
            finally:
                drop_database(admin, scratch, scratch)
            os.link(archive.name, output)
    print('Đã tạo file dump custom để bộ cài nhập dữ liệu.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--pg-bin', type=Path)
    parser.add_argument('--php-root', type=Path, default=Path('/www/server/php/84'))
    parser.add_argument('--socket-dir')
    parser.add_argument('--output', type=Path, help='File kết nối mới với hướng dẫn từng bước')
    parser.add_argument('--db-name', default='tram_seo')
    parser.add_argument('--connections-file', type=Path, help='File kết nối đã lưu để kiểm tra hoặc chuyển dump')
    parser.add_argument('--sql-backup', '--restore-input', type=Path, help='File SQL từ pg_dump -Fp')
    parser.add_argument('--dump-output', '--restore-output', type=Path, help='File dump custom tạo mới')
    args = parser.parse_args()
    if args.output and (args.connections_file or args.sql_backup or args.dump_output):
        parser.error('--output dùng riêng cho bước kết nối từng bước.')
    if not args.output and not args.connections_file:
        parser.error('Cần --output hoặc --connections-file.')
    if bool(args.sql_backup) != bool(args.dump_output):
        parser.error('Chuyển SQL cần cả --sql-backup và --dump-output.')
    if os.geteuid() != 0:
        parser.error('Chạy bằng root để đọc file riêng và chuẩn bị PostgreSQL qua BaoTa.')
    os.umask(0o077)
    try:
        pg_bin = resolve_pg_bin(args.pg_bin)
        if args.output:
            choose_connection(pg_bin, args.php_root, args.output, args.db_name, args.socket_dir)
            return
        settings = existing_connections(args.connections_file)
        with tempfile.TemporaryDirectory(prefix='tram-db-') as temporary:
            passfile = Path(temporary) / 'pgpass'
            pgpass(passfile, settings)
            env = pg_env(settings)
            env['PGPASSFILE'] = str(passfile)
            postgres_runtime(pg_bin, settings, env)
        if args.sql_backup:
            normalize_sql(pg_bin, settings, args.sql_backup, args.dump_output, args.socket_dir)
        elif not redis_runtime(args.php_root, settings):
            raise ValueError('Không kết nối/xác thực được Redis với file kết nối đã chọn.')
        else:
            print('Kết nối PostgreSQL và Redis đạt.')
    except (ValueError, OSError, EOFError, KeyboardInterrupt, subprocess.TimeoutExpired) as error:
        parser.exit(1, 'Lỗi bước database: ' + (str(error) or 'đã dừng theo yêu cầu.') + '\n')


if __name__ == '__main__':
    main()
