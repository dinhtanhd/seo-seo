#!/usr/bin/env python3
"""Render native Trạm SEO services and merge runtime settings without replacing APP_KEY."""
import argparse
import base64
import os
from pathlib import Path
import re
import subprocess
import tempfile


def write(path, text, mode=0o640):
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, filename = tempfile.mkstemp(prefix='.' + path.name + '.', dir=path.parent)
    temporary = Path(filename)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            os.fchmod(stream.fileno(), mode)
            stream.write(text)
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def parse_value(raw):
    """Read one literal dotenv value; never expand variables or execute input."""
    raw = raw.strip()
    if not raw:
        return ''
    if raw[0] not in ('"', "'"):
        value = raw.split('#', 1)[0].strip()
        if re.search(r'[\s\'"\x00-\x1f\x7f]', value):
            raise ValueError('Giá trị có khoảng trắng cần đặt trong dấu nháy.')
        return None if value.lower() in ('null', '(null)') else value
    quote = raw[0]
    chars = []
    index = 1
    while index < len(raw):
        char = raw[index]
        if char == quote:
            remainder = raw[index + 1:].strip()
            if remainder and not remainder.startswith('#'):
                raise ValueError('Có nội dung sau dấu nháy đóng.')
            value = ''.join(chars)
            if re.search(r'[\x00-\x1f\x7f]', value):
                raise ValueError('Giá trị không được chứa ký tự điều khiển.')
            return value
        if quote == '"' and char == '\\':
            index += 1
            if index >= len(raw) or raw[index] not in ('\\', '"', '$'):
                raise ValueError('Escape trong dấu nháy kép chỉ hỗ trợ backslash, dấu nháy kép và dollar.')
            char = raw[index]
        chars.append(char)
        index += 1
    raise ValueError('Thiếu dấu nháy đóng.')


def dotenv_quote(value, literal=False):
    if value is None:
        return 'null'
    # Laravel's env() interprets reserved words and strips surrounding quotes.
    if literal and (value.lower() in ('true', '(true)', 'false', '(false)',
                                     'empty', '(empty)', 'null', '(null)')
                    or (len(value) >= 2 and value[0] == value[-1] == '"')):
        value = '"' + value + '"'
    return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$') + '"'


def env_value(content, name):
    matches = re.findall(r'^' + re.escape(name) + r'[ \t]*=[ \t]*(.*)$', content, re.M)
    if not matches:
        return ''
    return parse_value(matches[-1]) or ''


def read_connections(path):
    required = {'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
                'REDIS_HOST', 'REDIS_PORT'}
    defaults = {'DB_SSLMODE': 'prefer', 'REDIS_USERNAME': None, 'REDIS_PASSWORD': None}
    result = {}
    for number, line in enumerate(path.read_text().splitlines(), 1):
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        match = re.fullmatch(r'[ \t]*([A-Z_]+)[ \t]*=(.*)', line)
        if not match or match[1] not in required | defaults.keys():
            raise ValueError(f'connections.env dòng {number}: chỉ chấp nhận các biến kết nối DB/Redis đã quy định.')
        name = match[1]
        if name in result:
            raise ValueError(f'connections.env có biến {name} trùng lặp.')
        try:
            result[name] = parse_value(match[2])
        except ValueError as error:
            raise ValueError(f'connections.env dòng {number}: {error}') from None
    missing = sorted(name for name in required if not result.get(name))
    if missing:
        raise ValueError('connections.env thiếu giá trị: ' + ', '.join(missing))
    for name, value in defaults.items():
        result.setdefault(name, value)
    for name in ('DB_DATABASE', 'DB_USERNAME'):
        if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,62}', result[name]):
            raise ValueError(f'{name} cần chữ/số/gạch dưới, bắt đầu bằng chữ hoặc gạch dưới, tối đa 63 ký tự.')
    for name in ('DB_PORT', 'REDIS_PORT'):
        value = result[name]
        if not re.fullmatch(r'[0-9]{1,5}', value) or not 1 <= int(value) <= 65535:
            raise ValueError(f'{name} cần nằm trong 1–65535.')
        result[name] = str(int(value))
    for name in ('DB_HOST', 'REDIS_HOST'):
        if not re.fullmatch(r'[A-Za-z0-9_.:/\[\]-]+', result[name]):
            raise ValueError(f'{name} cần là hostname, IP hoặc đường dẫn Unix socket hợp lệ.')
    if result['DB_SSLMODE'] not in ('disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'):
        raise ValueError('DB_SSLMODE không hợp lệ.')
    for name in ('REDIS_USERNAME', 'REDIS_PASSWORD'):
        if result[name] == '':
            result[name] = None
    return result


def normalize_php_upstream(value, from_fpm=False):
    if from_fpm and value.startswith('/'):
        value = 'unix:' + value
    if value.startswith('unix:') and re.fullmatch(r'unix:/[A-Za-z0-9_./-]+', value):
        return value
    if from_fpm:
        if value.isdigit():
            value = '127.0.0.1:' + value
        elif value.startswith(('0.0.0.0:', '*:')):
            value = '127.0.0.1:' + value.rsplit(':', 1)[1]
        elif value.startswith('[::]:'):
            value = '[::1]:' + value[5:]
    match = re.fullmatch(r'(127\.0\.0\.1|localhost|\[::1\]):([0-9]{1,5})', value)
    if match and 1 <= int(match[2]) <= 65535:
        return match[1] + ':' + str(int(match[2]))
    raise ValueError('PHP upstream cần unix:/đường/dẫn/socket hoặc địa chỉ loopback kèm port 1–65535.')


def detect_fpm_listener(php_root, pool=''):
    """Let FPM resolve includes; inspect only effective pool names and listeners."""
    root = str(php_root)
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+', root):
        raise ValueError('Đường dẫn PHP cần tuyệt đối, không có khoảng trắng hoặc ký tự đặc biệt.')
    if pool and not re.fullmatch(r'[A-Za-z0-9_.-]+', pool):
        raise ValueError('Tên PHP pool không hợp lệ.')
    try:
        result = subprocess.run(
            [root + '/sbin/php-fpm', '-tt', '--fpm-config', root + '/etc/php-fpm.conf'],
            capture_output=True, text=True, timeout=30,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise ValueError('Không chạy được kiểm tra cấu hình PHP-FPM BaoTa hiện tại.') from None
    if result.returncode:
        # The dump may contain env credentials; never echo it or attach it to errors.
        raise ValueError('PHP-FPM không xác nhận cấu hình hiện tại hợp lệ; kiểm tra trong BaoTa.')
    listeners = {}
    current_pool = None
    for line in (result.stdout + '\n' + result.stderr).splitlines():
        line = re.sub(r'^.*?\b(?:NOTICE|WARNING|ERROR|DEBUG):\s*', '', line).strip()
        section = re.fullmatch(r'\[([^\[\]\r\n]+)\]', line)
        if section:
            current_pool = section[1] if section[1] != 'global' else None
        elif current_pool:
            listener = re.fullmatch(r'listen\s*=\s*(.*?)\s*', line)
            if listener:
                listeners[current_pool] = listener[1]
    if pool:
        if pool not in listeners:
            raise ValueError('Không tìm thấy pool đã chọn trong cấu hình hiệu lực PHP-FPM.')
        return normalize_php_upstream(listeners[pool], from_fpm=True)
    if len(listeners) != 1:
        raise ValueError('PHP-FPM có nhiều pool hoặc không tìm thấy listen; cần chọn --php-pool rõ ràng.')
    return normalize_php_upstream(next(iter(listeners.values())), from_fpm=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path)
    parser.add_argument('--domain')
    parser.add_argument('--public-hosts', default='')
    parser.add_argument('--php-root', default='/www/server/php/84')
    parser.add_argument('--detect-fpm', action='store_true')
    parser.add_argument('--php-pool', default='')
    parser.add_argument('--php-upstream')
    parser.add_argument('--without-auth-request', action='store_true')
    parser.add_argument('--connections-file', type=Path)
    parser.add_argument('--tls-cert', default='')
    parser.add_argument('--tls-key', default='')
    parser.add_argument('--panel-scheme', choices=('http', 'https'))
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    if args.detect_fpm:
        try:
            print(detect_fpm_listener(args.php_root, args.php_pool))
        except ValueError as error:
            parser.error(str(error))
        return
    for argument in ('root', 'domain', 'connections_file', 'output', 'php_upstream'):
        if not getattr(args, argument):
            parser.error('--' + argument.replace('_', '-') + ' là bắt buộc khi render cấu hình.')
    try:
        upstream = normalize_php_upstream(args.php_upstream)
    except ValueError as error:
        parser.error(str(error))
    root = str(args.root.resolve())
    hosts = list(dict.fromkeys([args.domain, *args.public_hosts.split(',')]))
    hosts = [host for host in hosts if host]
    for host in hosts:
        if len(host) > 253 or not all(re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', label) for label in host.split('.')):
            parser.error('Domain cần dạng ASCII/punycode, gồm chữ thường, số và dấu gạch ngang.')
    for value in [root, args.php_root, args.tls_cert, args.tls_key]:
        if value and not re.fullmatch(r'/[A-Za-z0-9_./-]+', value):
            parser.error('Đường dẫn cần là đường dẫn tuyệt đối, không có khoảng trắng hoặc ký tự đặc biệt.')
    if bool(args.tls_cert) != bool(args.tls_key):
        parser.error('Cần cả certificate và private key cho HTTPS.')
    env_file = args.root / 'application/.env'
    if not env_file.exists():
        parser.error('Cần chuẩn bị application/.env trước khi render cấu hình.')
    env = env_file.read_text()
    try:
        connections = read_connections(args.connections_file)
        app_key_file = env_value(env, 'APP_KEY_FILE')
        app_key = env_value(env, 'APP_KEY')
    except (OSError, ValueError) as error:
        parser.error(str(error))
    if app_key_file:
        key_path = Path(app_key_file)
        if not key_path.is_file():
            parser.error('APP_KEY_FILE cũ không đọc được. Khôi phục application.key vào APP_KEY và bỏ APP_KEY_FILE trước khi cài.')
        app_key = key_path.read_text().strip()
    if not app_key:
        parser.error('APP_KEY đang trống. Cần tạo key khi cài mới hoặc khôi phục key gốc khi chuyển dữ liệu.')
    if app_key.startswith('base64:'):
        try:
            key_bytes = base64.b64decode(app_key[7:], validate=True)
        except ValueError:
            parser.error('APP_KEY base64 không hợp lệ.')
        if len(key_bytes) != 32:
            parser.error('APP_KEY cần chứa 32 byte cho cipher mặc định.')
    elif len(app_key.encode()) != 32:
        parser.error('APP_KEY cần là key gốc 32 byte hoặc base64 của key đó.')
    out = args.output
    out.mkdir(parents=True, exist_ok=True)
    out.chmod(0o750)
    tls = ''
    if args.tls_cert:
        tls = f'listen 443 ssl;\n    ssl_certificate {args.tls_cert};\n    ssl_certificate_key {args.tls_key};\n    ssl_protocols TLSv1.2 TLSv1.3;'
    nginx_templates = args.root / 'infra/baota'
    if args.without_auth_request:
        # Keep requests to the disabled internal endpoints out of public routing.
        policy_locations = ('location = /__tram_public_cache_policy { return 404; }\n'
                            'location = /__tram_public_html_cache/advertising { return 404; }\n'
                            'location = /__tram_public_runtime_html_cache/advertising { return 404; }')
        auth_off = ''
        # Enter the named Laravel location directly, preserving the original URI/method.
        # Going via /index.php would retry the advertising projection and loop.
        advertising_redirects = 'error_page 419 420 = @tram_public_html_cache_miss;'
    else:
        policy_locations = (nginx_templates / 'public-html-cache-policy.conf.template').read_text().rstrip()
        auth_off = '    auth_request off;'
        advertising_redirects = ('error_page 419 = /__tram_public_html_cache/advertising;\n'
                                 '    error_page 420 = /__tram_public_runtime_html_cache/advertising;')
    replacements = {'@CACHE_POLICY_LOCATIONS@': policy_locations, '@CACHE_AUTH_OFF@': auth_off,
                    '@CACHE_ADVERTISING_REDIRECTS@': advertising_redirects,
                    '@ROOT@': root, '@DOMAIN@': args.domain, '@SERVER_NAMES@': ' '.join(hosts),
                    '@PHP_UPSTREAM@': upstream, '@DB_NAME@': connections['DB_DATABASE'], '@TLS_DIRECTIVES@': tls}
    for name in ('nginx-site.conf', 'public-html-cache-location.conf'):
        content = (nginx_templates / (name + '.template')).read_text()
        for token, value in replacements.items():
            content = content.replace(token, value)
        if re.search(r'@[A-Z_]+@', content):
            raise ValueError('Template còn placeholder chưa được thay.')
        write(out / 'nginx' / name, content)
    php = f'{args.php_root}/bin/php'
    units = {
        'worker': (php + ' artisan queue:work redis --queue=websites,publish,campaigns,baidu,default --sleep=2 --tries=3 --timeout=300 --max-time=3600', 'Environment=RUNTIME_WORKER_ROLE=publish\n'),
        'crawler': (php + ' artisan queue:work redis --queue=ingestion,imports,crawl --sleep=2 --tries=3 --timeout=300 --max-time=3600', 'Environment=RUNTIME_WORKER_ROLE=crawl\n'),
        'scheduler': (php + ' artisan schedule:work', ''),
    }
    for name, (command, extra) in units.items():
        write(out / 'systemd' / f'tram-seo-{name}.service', f'''[Unit]
Description=Tram SEO {name}
After=network.target
[Service]
Type=simple
User=www
WorkingDirectory={root}/application
{extra}ExecStart={command}
Restart=always
RestartSec=3
TimeoutStopSec=330
KillSignal=SIGTERM
KillMode=mixed
UMask=0027
LimitNOFILE=65536
[Install]
WantedBy=multi-user.target
''', 0o644)
    scheme = args.panel_scheme or ('https' if tls else 'http')
    settings = {'APP_ENV': 'production', 'APP_DEBUG': 'false', 'APP_URL': f'{scheme}://{args.domain}',
        'APP_KEY': app_key, 'DB_URL': '', 'REDIS_URL': '',
        'ADMIN_HOST': args.domain, 'ADMIN_HOSTS': args.domain, 'DB_CONNECTION': 'pgsql',
        'REDIS_CLIENT': 'phpredis', **connections,
        'QUEUE_CONNECTION': 'redis', 'REDIS_QUEUE_RETRY_AFTER': '360', 'CACHE_STORE': 'redis',
        'SESSION_SECURE_COOKIE': 'true' if scheme == 'https' else 'false', 'LOG_CHANNEL': 'stack', 'LOG_STACK': 'daily', 'LOG_LEVEL': 'warning'}
    if not re.search(r'^PUBLIC_ON_DEMAND_ENABLED[ \t]*=', env, re.M):
        settings['PUBLIC_ON_DEMAND_ENABLED'] = 'true'
    # The imported .env may refer to secrets mounted only in its old runtime.
    env = re.sub(r'^(APP_KEY_FILE|DB_PASSWORD_FILE|REDIS_PASSWORD_FILE)[ \t]*=.*\n?', '', env, flags=re.M)
    for key, value in settings.items():
        line = key + '=' + dotenv_quote(value, literal=key in ('APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'REDIS_USERNAME'))
        pattern = r'^' + re.escape(key) + r'[ \t]*=.*$'
        if re.search(pattern, env, re.M):
            env = re.sub(pattern, lambda match: line, env, flags=re.M)
        else:
            env += '\n' + line
    write(out / 'application.env', env.rstrip() + '\n', 0o600)


if __name__ == '__main__':
    main()
