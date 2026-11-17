#!/usr/bin/env python3
"""Install Trạm SEO interactively on CentOS Stream with BaoTa."""

import argparse
import fcntl
import hashlib
import importlib.util
import os
from pathlib import Path
import re
import subprocess
import sys


ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / 'scripts'
CONF = Path('/etc/tram-seo')
STATE = Path('/var/lib/tram-seo')
VHOSTS = Path('/www/server/panel/vhost/nginx')
HOST = re.compile(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')


def ask(label, default=''):
    suffix = f' [{default}]' if default else ''
    return input(f'{label}{suffix}: ').strip() or default


def yes(label, default=True):
    while True:
        choice = ask(f'{label} (c/k)', 'c' if default else 'k').lower()
        if choice in {'c', 'k'}:
            return choice == 'c'
        print('Nhập c để đồng ý hoặc k để bỏ qua.')


def host(value):
    name = value.lower().rstrip('.')
    if len(name) > 253 or not all(HOST.fullmatch(part) for part in name.split('.')):
        raise ValueError('Nhập tên miền hoặc IP hợp lệ, không kèm http://, cổng hoặc đường dẫn.')
    return name


def path_prompt(label, default='', *, directory=False, optional=False):
    while True:
        value = ask(label, str(default))
        if not value and optional:
            return None
        path = Path(value)
        if path.is_absolute() and (path.is_dir() if directory else path.is_file()):
            return path.resolve()
        print('Nhập đường dẫn tuyệt đối đã có trên VPS.')


def run(*command, capture=False):
    result = subprocess.run([str(value) for value in command], check=True, text=True,
                            stdout=subprocess.PIPE if capture else None)
    return result.stdout.strip() if capture else None


def config_value(name):
    env = ROOT / 'application/.env'
    if not env.is_file():
        return ''
    spec = importlib.util.spec_from_file_location('baota_config', SCRIPTS / 'baota-config.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.env_value(env.read_text(), name) or ''


def choose_site(default_domain, php_root, public_hosts):
    domain = host(ask('Tên miền hoặc IP quản trị', default_domain))
    if not yes('Dùng website do BaoTa quản lý'):
        return domain, []
    guess = VHOSTS / f'{domain}.conf'
    print(f'Trong BaoTa → Website, chọn hoặc tạo site có domain {domain}.')
    print(f'Chọn thư mục dự án {ROOT} và thư mục chạy /application/public. Chọn PHP tương ứng {php_root}.')
    print('Nếu panel đã có site, mở cấu hình của site đó.')
    default_file = guess
    while True:
        value = ask('Đường dẫn file cấu hình Nginx của site', default_file)
        vhost = Path(value)
        try:
            run(sys.executable, SCRIPTS / 'baota-panel-site.py', 'check', '--vhost', vhost,
                '--root', ROOT, '--domain', domain, '--public-hosts', public_hosts, '--php-root', php_root,
                capture=True)
            return domain, ['--panel-vhost', str(vhost)]
        except subprocess.CalledProcessError:
            if not yes('Thử lại sau khi chỉnh website trong BaoTa', default=False):
                raise
            default_file = vhost


def choose_import():
    print('\n3/5. Dữ liệu')
    print('1. Database mới hoặc dùng lại cấu hình đã cài')
    print('2. Nhập bản sao lưu PostgreSQL của Trạm SEO')
    pending_restore = (STATE / 'restore.sha256').exists() and not (STATE / 'install-complete').exists()
    choice = ask('Chọn 1 hoặc 2', '2' if pending_restore else '1')
    if choice == '1':
        if pending_restore:
            raise ValueError('Máy đang có lượt nhập dữ liệu chưa hoàn tất. Chọn 2 và dùng lại backup trước đó.')
        return None
    if choice != '2':
        raise ValueError('Chỉ chọn 1 hoặc 2.')
    if (STATE / 'install-complete').exists():
        raise ValueError('Ứng dụng đã cài tại đây. Hãy nhập backup vào một instance mới.')
    backup = path_prompt('File PostgreSQL .dump hoặc .sql',
                         '/root/tram-move/database.dump' if Path('/root/tram-move/database.dump').is_file() else '')
    env = path_prompt('File .env gốc có APP_KEY',
                      '/root/tram-move/application.env' if Path('/root/tram-move/application.env').is_file() else '')
    storage = path_prompt('Thư mục storage gốc có thư mục app/',
                          '/root/tram-move/storage' if Path('/root/tram-move/storage/app').is_dir() else '', directory=True)
    if not (storage / 'app').is_dir():
        raise ValueError('Thư mục storage gốc cần chứa thư mục app/.')
    assets = path_prompt('Thư mục public-build đã xuất (Enter để bỏ qua)',
                         '/root/tram-move/public-build' if Path('/root/tram-move/public-build').is_dir() else '',
                         directory=True, optional=True)
    manifest = path_prompt('File preservation.json (Enter để bỏ qua)',
                           '/root/tram-move/preservation.json' if Path('/root/tram-move/preservation.json').is_file() else '',
                           optional=True)
    return backup, env, storage, assets, manifest


def digest(path):
    result = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(chunk)
    return result.hexdigest()


def import_args(backup_options, db_helper, connections, private):
    if backup_options is None:
        return []
    backup, env, storage, assets, manifest = backup_options
    with backup.open('rb') as stream:
        kind = stream.read(5)
    if kind != b'PGDMP':
        if backup.suffix.lower() != '.sql':
            raise ValueError('Cần bản pg_dump custom hoặc file .sql tạo bằng pg_dump.')
        dump = private / f'postgres-{digest(backup)}.dump'
        if not dump.is_file():
            run(*db_helper, '--connections-file', connections, '--sql-backup', backup, '--dump-output', dump)
        backup = dump
    options = ['--restore', backup, '--env-file', env, '--storage-dir', storage]
    if assets:
        options += ['--public-build-dir', assets]
    if manifest:
        options += ['--verify-data', manifest]
    return options


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Chỉ kiểm tra môi trường và website đã chọn')
    parser.add_argument('--domain', help='Điền trước domain hoặc IP quản trị')
    parser.add_argument('--php-root', help='Điền trước thư mục PHP của BaoTa')
    parser.add_argument('--pg-bin', help='Điền trước thư mục client PostgreSQL 18')
    args = parser.parse_args()
    if not sys.stdin.isatty():
        raise ValueError('Chạy bộ cài từ terminal BaoTa hoặc SSH có terminal (ssh -t).')
    if sys.platform != 'linux' or os.geteuid() != 0:
        raise ValueError('Chạy wizard bằng root trên CentOS Stream 9/10 đã có BaoTa.')
    if not VHOSTS.is_dir():
        raise ValueError('Cần BaoTa và Nginx đã cài trong Software Store.')
    os.umask(0o077)
    state_root = CONF / 'project-root'
    if state_root.is_file() and Path(state_root.read_text().strip()).resolve() != ROOT:
        raise ValueError('Máy đã có một thư mục Trạm SEO khác. Mở đúng thư mục đã cài để tiếp tục.')

    print(f'1/5. Thư mục cài đặt: {ROOT}')
    php = ask('Thư mục PHP BaoTa', args.php_root or '/www/server/php/84')
    pool = ask('Tên pool PHP (Enter nếu chỉ có một pool)')
    pg = ask('Thư mục client PostgreSQL 18', args.pg_bin or '/www/server/pgsql/bin')
    for label, value in (('PHP', php), ('PostgreSQL', pg)):
        if not re.fullmatch(r'/[a-zA-Z0-9_./-]+', value):
            raise ValueError(f'Đường dẫn {label} cần tuyệt đối và không có khoảng trắng.')
    deps = ['bash', SCRIPTS / 'baota-wizard-deps', '--php-root', php, '--pg-bin', pg]
    native = ['bash', SCRIPTS / 'install-baota', '--php-root', php, '--pg-bin', pg]
    if pool:
        deps += ['--php-pool', pool]
        native += ['--php-pool', pool]

    print('\n2/5. Website BaoTa')
    public_hosts = ','.join(host(item.strip()) for item in ask('Domain public khác đã thêm vào BaoTa (phân cách bằng dấu phẩy, Enter để bỏ qua)').split(',') if item.strip())
    domain, panel = choose_site(args.domain or config_value('ADMIN_HOST'), php, public_hosts)
    native += ['--domain', domain, '--public-hosts', public_hosts, *panel]
    if args.check:
        run(*deps, '--check')
        run(*native, '--check')
        return

    backup = choose_import()
    if not yes('Bắt đầu kiểm tra và cài đặt', default=False):
        return
    with open('/run/tram-seo-wizard.lock', 'a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise ValueError('Một lượt wizard đang chạy.') from None
        if state_root.is_file() and Path(state_root.read_text().strip()).resolve() != ROOT:
            raise ValueError('Thư mục Trạm SEO đã thay đổi trong khi nhập cấu hình.')
        run(*deps)
        run(*native, '--check')
        private = STATE / 'wizard'
        private.mkdir(parents=True, exist_ok=True, mode=0o700)
        private.chmod(0o700)
        connections = CONF / 'connections.env'
        if not connections.is_file():
            connections = private / 'connections.env'
        db_helper = [sys.executable, SCRIPTS / 'baota-wizard-db.py', '--pg-bin', pg, '--php-root', php]
        if connections.is_file():
            run(*db_helper, '--connections-file', connections)
        else:
            run(*db_helper, '--output', connections)

        print('\n4/5. Cài ứng dụng và kiểm tra dịch vụ')
        completed = (STATE / 'install-complete').exists()
        run(*native, '--connections-file', connections, *import_args(backup, db_helper, connections, private))

        print('\n5/5. Tài khoản quản trị')
        if yes('Tạo tài khoản quản trị mới', default=backup is None and not completed):
            os.chdir(ROOT / 'application')
            run('runuser', '-u', 'www', '--', Path(php) / 'bin/php', 'artisan', 'tram:admin-create', '--global')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, EOFError, KeyboardInterrupt, subprocess.CalledProcessError) as error:
        if isinstance(error, subprocess.CalledProcessError):
            print('Bước trên chưa thành công. Sửa lỗi rồi chạy lại bash install.sh --wizard.', file=sys.stderr)
        elif isinstance(error, (EOFError, KeyboardInterrupt)):
            print('\nĐã dừng bộ cài.', file=sys.stderr)
        else:
            print(f'Lỗi: {error}', file=sys.stderr)
        sys.exit(1)
