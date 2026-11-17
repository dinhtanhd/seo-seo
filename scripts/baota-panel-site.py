#!/usr/bin/env python3
"""Validate and attach Trạm SEO routing to an existing BaoTa website."""

import argparse
from dataclasses import dataclass
from glob import glob
from pathlib import Path
import re


RUNTIME = '/etc/tram-seo/nginx/panel-runtime.conf'
MAPS_TAG = '# TRAM SEO MAPS'
RUNTIME_TAG = '# TRAM SEO ROUTING'
SAFE_PATH = re.compile(r'/[A-Za-z0-9_./-]+\Z')
TOKEN = re.compile(r'''\s+|\#[^\r\n]*|[{};]|(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\\.|\$\{[^{}\s]+\}|[^\s;{}#"'\\])+''')


@dataclass
class Directive:
    words: tuple
    start: int
    end: int
    body_start: int = -1
    children: tuple = ()

    @property
    def name(self):
        return self.words[0]


def parse(content):
    """Read directive boundaries without interpreting regex, comments or quoted values."""
    parents = []
    directives = []
    words = []
    position = 0
    for token in TOKEN.finditer(content):
        if token.start() != position:
            raise ValueError('Không đọc được cú pháp file Nginx của website.')
        position = token.end()
        value = token.group()
        if value.isspace() or value.startswith('#'):
            continue
        if value == '}':
            if words or not parents:
                raise ValueError('File Nginx của website có block không hợp lệ.')
            parent, outer = parents.pop()
            parent.children = tuple(directives)
            parent.end = token.end()
            directives = outer
        elif value in (';', '{'):
            if not words:
                raise ValueError('File Nginx của website có dòng không hợp lệ.')
            directive = Directive(tuple(word.group() for word in words), words[0].start(), token.end())
            directives.append(directive)
            words = []
            if value == '{':
                directive.body_start = token.end()
                parents.append((directive, directives))
                directives = []
        else:
            words.append(token)
    if parents or words or position != len(content):
        raise ValueError('File Nginx của website chưa đóng đủ block hoặc directive.')
    return directives


def unquote(word):
    if len(word) >= 2 and word[0] in ('"', "'") and word[-1] == word[0]:
        return word[1:-1]
    return word


def one_server(directives):
    servers = [directive for directive in directives if directive.name == 'server']
    if len(servers) != 1 or servers[0].words != ('server',) or servers[0].body_start < 0:
        raise ValueError('Cần vhost BaoTa có đúng một block server cho website này.')
    return servers[0]


def expected_site(directives, root, domain, hosts):
    server = one_server(directives)
    roots = [child for child in server.children if child.name == 'root']
    if len(roots) != 1 or len(roots[0].words) != 2:
        raise ValueError('Website cần có đúng một thư mục chạy cố định.')
    document_root = unquote(roots[0].words[1])
    if '/server/stop' in document_root:
        raise ValueError('Website BaoTa đang dừng. Bấm Start (启动) trong BaoTa trước khi chạy bộ cài.')
    if not SAFE_PATH.fullmatch(document_root) or Path(document_root).resolve() != (root / 'application/public').resolve():
        raise ValueError('BaoTa → Website → 网站目录 → 运行目录: chọn /application/public trong thư mục cài.')
    configured_hosts = {unquote(word).lower() for child in server.children if child.name == 'server_name' for word in child.words[1:]}
    if not ({domain} | set(hosts)).issubset(configured_hosts):
        raise ValueError('Domain chưa được gắn vào website này. Thêm trong BaoTa → 域名管理 trước.')
    return server


def managed(path, root, domain, hosts, php_root):
    if not path.is_file() or path.is_symlink():
        raise ValueError('Chưa thấy cấu hình Website BaoTa. Tạo PHP Website trong panel trước.')
    content = path.read_text()
    if '#PHP-INFO-START' not in content or '#REWRITE-START' not in content:
        raise ValueError('Vhost hiện tại không có khối cấu hình PHP/rewrite của BaoTa. Tạo hoặc khôi phục vhost trong BaoTa trước.')
    directives = parse(content)
    server = expected_site(directives, root, domain, hosts)
    php_version = php_root.name
    for child in server.children:
        if child.name == 'location' and tuple(unquote(word) for word in child.words[1:]) in (('/',), ('^~', '/')):
            raise ValueError('Website có route gốc tùy chỉnh hoặc proxy. Chọn một website riêng cho Trạm SEO trong BaoTa.')
        if child.name in ('fastcgi_buffer_size', 'fastcgi_buffers', 'fastcgi_busy_buffers_size'):
            raise ValueError('Website đã đặt FastCGI buffer riêng. Dùng mặc định BaoTa ở cấp website trước.')
        if child.name != 'include' or len(child.words) != 2:
            continue
        target = unquote(child.words[1])
        php = re.fullmatch(r'enable-php-(\d+)(?:-wpfastcgi)?\.conf', Path(target).name)
        if php and php.group(1) not in ('0', '00', php_version) and php_version.isdecimal():
            raise ValueError('Phiên bản PHP của Website trong BaoTa khác PHP đã chọn trong bộ cài.')
        if target.startswith('/www/server/panel/vhost/rewrite/'):
            rewrite = Path(target)
            if rewrite.is_file() and parse(rewrite.read_text()):
                raise ValueError('Website đang dùng luật 伪静态 riêng. Dọn luật xung đột trong BaoTa trước khi gắn Trạm SEO.')
        if target.startswith('/www/server/panel/vhost/proxy/') and glob(target):
            raise ValueError('Website đang bật reverse proxy trong BaoTa. Chọn một website riêng cho Trạm SEO.')
    tls = any(child.name == 'listen' and 'ssl' in child.words[1:] for child in server.children)
    return content, directives, server, 'https' if tls else 'http'


def locations(server):
    return {tuple(unquote(word) for word in child.words[1:]) for child in server.children if child.name == 'location'}


def attach(content, directives, server, generated, root, domain, hosts):
    generated_directives = parse(generated)
    source = expected_site(generated_directives, root, domain, hosts)
    cache = root / 'infra/nginx/public-html-cache.conf'
    if not any(child.words == ('include', str(cache)) for child in generated_directives):
        raise ValueError('Vhost Trạm SEO đã sinh thiếu cấu hình cache.')
    if any(child.name == 'include' and len(child.words) == 2 and unquote(child.words[1]) == str(cache) for child in directives):
        if content.count(MAPS_TAG) != 1:
            raise ValueError('Vhost đã chứa một file map cache Trạm SEO khác. Kiểm tra trong BaoTa.')
        maps_line = ''
    else:
        if MAPS_TAG in content:
            raise ValueError('Khối map Trạm SEO trong vhost đã bị sửa. Kiểm tra trong BaoTa.')
        maps_line = f'{MAPS_TAG}\ninclude {cache};\n\n'
    used = [child for child in server.children if child.words == ('include', RUNTIME)]
    if len(used) > 1 or (bool(used) != (content.count(RUNTIME_TAG) == 1)):
        raise ValueError('Khối Trạm SEO hiện tại trong vhost đã bị sửa hoặc trùng. Kiểm tra trong BaoTa.')
    preserve_settings = {child.name for child in server.children}
    preserve_headers = {unquote(child.words[1]).lower() for child in server.children if child.name == 'add_header' and len(child.words) > 1}
    inherited_locations = locations(server)
    has_php_guard = False
    selected = []
    for child in source.children:
        if child.name not in ('charset', 'set', 'client_max_body_size', 'fastcgi_buffer_size', 'fastcgi_buffers',
                              'fastcgi_busy_buffers_size', 'add_header', 'location', 'include'):
            continue
        if child.name == 'location':
            route = tuple(unquote(word) for word in child.words[1:])
            if route == ('^~', '/.well-known/acme-challenge/'):
                continue  # Preserve how BaoTa supplies files for certificate validation.
            if route in inherited_locations:
                raise ValueError('Website BaoTa có route tùy chỉnh trùng với Trạm SEO.')
        if child.name in ('charset', 'client_max_body_size') and child.name in preserve_settings:
            continue
        if child.name == 'add_header' and unquote(child.words[1]).lower() in preserve_headers:
            continue
        fragment = generated[child.start:child.end]
        if child.words[1:] == ('~*', r'\.php$') and child.name == 'location':
            # Cover PATH_INFO as well, before the generic PHP handler BaoTa includes later.
            if ('~*', r'\.php(?:/|$)') in inherited_locations:
                raise ValueError('Website BaoTa đã có bộ xử lý PHP tùy chỉnh.')
            fragment = fragment.replace(r'\.php$', r'\.php(?:/|$)', 1)
            has_php_guard = True
        selected.append(fragment)
    if not has_php_guard:
        raise ValueError('Cấu hình Trạm SEO thiếu bộ xử lý PHP an toàn.')
    runtime = '# Trạm SEO. Cấu hình này được sinh tự động.\n' + '\n\n'.join(selected) + '\n'
    if not used:
        content = content[:server.body_start] + f'\n    {RUNTIME_TAG}\n    include {RUNTIME};\n' + content[server.body_start:]
    return maps_line + content, runtime


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=('check', 'render'))
    parser.add_argument('--vhost', type=Path, required=True)
    parser.add_argument('--root', type=Path, required=True)
    parser.add_argument('--domain', required=True)
    parser.add_argument('--public-hosts', default='')
    parser.add_argument('--php-root', type=Path, default=Path('/www/server/php/84'))
    parser.add_argument('--generated', type=Path)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--runtime-output', type=Path)
    args = parser.parse_args()
    root = args.root.resolve()
    if not SAFE_PATH.fullmatch(str(root)):
        parser.error('Đường dẫn cài đặt có ký tự đặc biệt.')
    hosts = [host for host in args.public_hosts.split(',') if host]
    try:
        content, directives, server, scheme = managed(args.vhost, root, args.domain, hosts, args.php_root)
        if args.mode == 'check':
            print(scheme)
            return
        if not (args.generated and args.output and args.runtime_output):
            parser.error('Cần --generated, --output và --runtime-output khi render.')
        merged, runtime = attach(content, directives, server, args.generated.read_text(), root, args.domain, hosts)
        args.output.write_text(merged)
        args.runtime_output.write_text(runtime)
    except (OSError, ValueError) as error:
        parser.exit(1, f'Lỗi Website BaoTa: {error}\n')


if __name__ == '__main__':
    main()
