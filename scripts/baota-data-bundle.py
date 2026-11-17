#!/usr/bin/env python3
"""Pack, verify and install a Trạm SEO backup encrypted for an age recipient."""

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import posixpath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
from urllib.parse import urlsplit


ROOT = Path(__file__).resolve().parents[1]
FORMAT = 'tram-seo-data-age-v1'
INDEX = 'data-bundle.json'
CHUNK_SIZE = 48 * 1024 * 1024
IO_SIZE = 1024 * 1024
AGE_HEADER = b'age-encryption.org/v1\n'
TOP_FILES = {'database.dump', 'application.env', 'preservation.json', 'manifest.json', 'SHA256SUMS'}
HASH = re.compile(r'[0-9a-f]{64}')
COMMIT = re.compile(r'[0-9a-f]{40}')


def digest(path):
    result = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(IO_SIZE), b''):
            result.update(block)
    return result.hexdigest()


def relative(name):
    if not isinstance(name, str) or any(ord(char) < 32 or char == '\\' for char in name):
        raise ValueError('Gói chứa tên file không hợp lệ.')
    if any(part in ('', '.', '..') for part in name.split('/')):
        raise ValueError('Gói chứa đường dẫn không an toàn.')
    parts = PurePosixPath(name).parts
    if parts[0] == 'storage':
        if len(parts) == 1 or (parts[1] == 'app' and parts[:3] != ('storage', 'app', 'public-html')):
            return name
    if parts[0] == 'public-build' or (len(parts) == 1 and name in TOP_FILES):
        return name
    raise ValueError('Gói chứa file ngoài các mục của bản export.')


def validate_link(name, link):
    if not isinstance(link, str) or not link or link.startswith('/') or any(ord(c) < 32 or c == '\\' for c in link):
        raise ValueError('Gói có liên kết không hợp lệ.')
    target = relative(posixpath.normpath(posixpath.join(posixpath.dirname(name), link)))
    if not ((name.startswith('storage/app/') and target.startswith('storage/app/')) or
            (name.startswith('public-build/') and target.startswith('public-build/'))):
        raise ValueError('Gói có liên kết ra ngoài thư mục dữ liệu.')


def verify_resolved_link(source, name, path, link):
    validate_link(name, link)
    try:
        resolved = path.resolve()
    except RuntimeError as error:
        raise ValueError('Gói chứa vòng lặp liên kết.') from error
    tree = 'storage/app' if name.startswith('storage/app/') else 'public-build'
    if source / tree not in resolved.parents:
        raise ValueError('Liên kết đã resolve ra ngoài thư mục dữ liệu.')


def export_paths(source):
    return {relative(path.relative_to(source).as_posix()): path for path in source.rglob('*')}


def verify_export(source):
    source = source.resolve()
    paths = export_paths(source)
    for name in TOP_FILES:
        path = paths.get(name)
        if path is None or path.is_symlink() or not path.is_file():
            raise ValueError(f'Thiếu file export bắt buộc: {name}.')
    if not (source / 'storage/app').is_dir() or (source / 'storage/app').is_symlink():
        raise ValueError('Bản export thiếu storage/app.')
    with paths['database.dump'].open('rb') as stream:
        if stream.read(5) != b'PGDMP':
            raise ValueError('Bản export cần PostgreSQL custom dump.')
    manifest = json.loads(paths['manifest.json'].read_text())
    if manifest['format'] != 'tram-seo-baota-migration-v1':
        raise ValueError('Định dạng bản export không được hỗ trợ.')
    items = {}
    sums = {f"{digest(paths['manifest.json'])}  manifest.json"}
    for entry in manifest['files']:
        name = relative(entry['path'])
        if name in items or name in ('manifest.json', 'SHA256SUMS') or name not in paths:
            raise ValueError('Manifest export có file trùng hoặc thiếu.')
        items[name] = entry
        path = paths[name]
        if path.is_symlink():
            link = os.readlink(path)
            verify_resolved_link(source, name, path, link)
            if entry.get('symlink') != link:
                raise ValueError('Liên kết không khớp manifest export.')
        elif not path.is_file() or entry.get('bytes') != path.stat().st_size or entry.get('sha256') != digest(path):
            raise ValueError('Nội dung file không khớp manifest export.')
        else:
            sums.add(f"{entry['sha256']}  {name}")
    actual = {name for name, path in paths.items() if path.is_symlink() or not path.is_dir()}
    if actual != set(items) | {'manifest.json', 'SHA256SUMS'}:
        raise ValueError('Bản export có file ngoài manifest.')
    lines = paths['SHA256SUMS'].read_text().splitlines()
    if len(lines) != len(sums) or set(lines) != sums:
        raise ValueError('File checksum không khớp bản export.')
    return paths


def require_age():
    if not shutil.which('age'):
        raise ValueError('Cần công cụ age để xử lý gói mã hóa. Cài age từ Homebrew hoặc EPEL tin cậy.')


def safe_destination(path):
    path = path.expanduser()
    if path.exists() or path.is_symlink() or not path.parent.is_dir():
        raise ValueError('Chọn đường dẫn mới trong thư mục cha đã tồn tại.')
    resolved = path.resolve()
    for forbidden in (ROOT / 'application/public', ROOT / 'application/storage'):
        if resolved == forbidden or forbidden in resolved.parents:
            raise ValueError('Giữ bản export ngoài public và storage đang phục vụ.')
    return resolved


def publish(stage, output):
    # Reserve the name exclusively. Stage and output share a filesystem.
    output.mkdir(mode=0o700)
    try:
        stage.replace(output)
    except BaseException:
        output.rmdir()
        raise


def chunk_name(number):
    return f'bundle.age.part{number:06d}'


def pack(source, output, recipient):
    require_age()
    if not re.fullmatch(r'age1[023456789acdefghjklmnpqrstuvwxyz]{58}', recipient):
        raise ValueError('Cần public recipient age X25519, không truyền private identity.')
    output = safe_destination(output)
    source = source.resolve(strict=True)
    if not source.is_dir() or source == output or source in output.parents:
        raise ValueError('Thư mục gói mã hóa phải nằm ngoài thư mục export nguồn.')
    paths = verify_export(source)
    with tempfile.TemporaryDirectory(dir=output.parent, prefix='.tram-data-pack-') as stage_dir, \
            tempfile.TemporaryFile(dir=output.parent) as encrypted:
        stage = Path(stage_dir) / 'sealed'
        stage.mkdir(mode=0o700)
        process = subprocess.Popen(['age', '--encrypt', '--recipient', recipient], stdin=subprocess.PIPE,
                                   stdout=encrypted, stderr=subprocess.DEVNULL)
        try:
            with process.stdin, tarfile.open(fileobj=process.stdin, mode='w|gz') as archive:
                archive.dereference = True
                for name, path in sorted(paths.items()):
                    if path.is_symlink():
                        link = os.readlink(path)
                        validate_link(name, link)
                        info = tarfile.TarInfo(name)
                        info.type = tarfile.SYMTYPE
                        info.linkname = link
                        archive.addfile(info)
                    else:
                        archive.add(path, arcname=name, recursive=False)
            if process.wait():
                raise ValueError('age không mã hóa được bản export.')
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
        encrypted.seek(0)
        chunks = []
        while block := encrypted.read(CHUNK_SIZE):
            name = chunk_name(len(chunks))
            (stage / name).write_bytes(block)
            chunks.append({'name': name, 'bytes': len(block), 'sha256': hashlib.sha256(block).hexdigest()})
        if not chunks:
            raise ValueError('Gói age rỗng.')
        (stage / INDEX).write_text(json.dumps({'format': FORMAT, 'chunks': chunks}, indent=2) + '\n')
        publish(stage, output)
    print(f'Đã tạo gói mã hóa tại {output} ({len(chunks)} phần); chỉ đưa nội dung thư mục này lên Git.')


def index(data):
    if len(data) > 2 * IO_SIZE:
        raise ValueError('Manifest gói mã hóa quá lớn.')
    manifest = json.loads(data)
    chunks = manifest['chunks']
    if manifest['format'] != FORMAT or not isinstance(chunks, list) or not chunks:
        raise ValueError('Định dạng manifest gói mã hóa không hợp lệ.')
    for number, chunk in enumerate(chunks):
        if (chunk['name'] != chunk_name(number) or type(chunk['bytes']) is not int or not 0 < chunk['bytes'] <= CHUNK_SIZE
                or not HASH.fullmatch(chunk['sha256'])):
            raise ValueError('Bản kê phần mã hóa không hợp lệ.')
    return chunks


def join(bundle, destination):
    manifest = bundle / INDEX
    if manifest.is_symlink() or not manifest.is_file() or manifest.stat().st_size > 2 * IO_SIZE:
        raise ValueError('Thiếu manifest gói mã hóa hợp lệ.')
    chunks = index(manifest.read_bytes())
    expected = {INDEX, *(chunk['name'] for chunk in chunks)}
    if {path.name for path in bundle.iterdir()} - {'.git'} != expected:
        raise ValueError('Thư mục Git chứa file ngoài gói mã hóa.')
    with destination.open('xb') as output:
        for chunk in chunks:
            path = bundle / chunk['name']
            if path.is_symlink() or not path.is_file() or path.stat().st_size != chunk['bytes']:
                raise ValueError('Thiếu phần mã hóa hoặc kích thước không khớp.')
            buffer = path.read_bytes()
            if hashlib.sha256(buffer).hexdigest() != chunk['sha256']:
                raise ValueError('Checksum phần mã hóa không khớp.')
            if output.tell() == 0 and not buffer.startswith(AGE_HEADER):
                raise ValueError('Gói không có định dạng age.')
            output.write(buffer)


def git_fetch(repo, commit, stage):
    if not COMMIT.fullmatch(commit):
        raise ValueError('Dùng SHA commit Git đủ 40 ký tự cho gói dữ liệu.')
    parsed = urlsplit(repo)
    if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.query
            or parsed.fragment or not re.fullmatch(r'/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', parsed.path)):
        raise ValueError('Dùng URL Git HTTPS của repository, không có mật khẩu/token trong URL.')
    gitdir = stage / 'git'
    bundle = stage / 'bundle'
    gitdir.mkdir()
    bundle.mkdir()
    env = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
    env['GIT_TERMINAL_PROMPT'] = '0'
    git = ['git', '-C', str(gitdir), '-c', 'protocol.file.allow=never', '-c', 'protocol.ext.allow=never']

    def invoke(*flags, out=subprocess.PIPE):
        result = subprocess.run([*git, *flags], stderr=subprocess.DEVNULL, stdout=out, env=env)
        if result.returncode:
            raise ValueError('Không đọc được commit gói dữ liệu qua Git. Kiểm tra quyền truy cập và SHA.')
        return result.stdout

    invoke('init', '--bare')
    invoke('fetch', '--depth=1', '--no-tags', '--', repo, commit)
    if invoke('rev-parse', 'FETCH_HEAD').decode().strip() != commit:
        raise ValueError('SHA đã tải không khớp SHA đã chọn.')
    if int(invoke('cat-file', '-s', f'{commit}:{INDEX}')) > 2 * IO_SIZE:
        raise ValueError('Manifest Git quá lớn.')
    content = invoke('show', f'{commit}:{INDEX}')
    chunks = index(content)
    expected = {INDEX, *(chunk['name'] for chunk in chunks)}
    actual = set()
    for entry in invoke('ls-tree', '-z', commit).split(b'\0'):
        if not entry:
            continue
        record, name = entry.decode('ascii').split('\t', 1)
        if not record.startswith('100644 blob '):
            raise ValueError('Nhánh dữ liệu Git chỉ được chứa file mã hóa thường.')
        actual.add(name)
    if actual != expected:
        raise ValueError('Nhánh dữ liệu Git chứa file ngoài manifest.')
    (bundle / INDEX).write_bytes(content)
    for chunk in chunks:
        name = chunk['name']
        blob = f'{commit}:{name}'
        if int(invoke('cat-file', '-s', blob)) != chunk['bytes']:
            raise ValueError('Kích thước phần mã hóa không khớp manifest.')
        with (bundle / name).open('xb') as stream:
            invoke('show', blob, out=stream)
    return bundle


def unpack(encrypted, identity, result):
    process = subprocess.Popen(['age', '--decrypt', '--identity', str(identity), str(encrypted)],
                               stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    links = []
    seen = set()
    try:
        with process.stdout, tarfile.open(fileobj=process.stdout, mode='r|gz') as archive:
            for member in archive:
                name = relative(member.name)
                if name in seen or (name in TOP_FILES and not member.isfile()):
                    raise ValueError('Archive chứa file trùng hoặc mục bắt buộc không hợp lệ.')
                seen.add(name)
                target = result / name
                target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                if member.isdir():
                    target.mkdir(exist_ok=True, mode=0o700)
                elif member.isfile():
                    with archive.extractfile(member) as stream, target.open('xb') as output:
                        shutil.copyfileobj(stream, output, IO_SIZE)
                elif member.issym():
                    validate_link(name, member.linkname)
                    links.append((target, member.linkname))
                else:
                    raise ValueError('Archive chứa loại file không được hỗ trợ.')
            # Read the stream to EOF so age authenticates every ciphertext block.
            while process.stdout.read(IO_SIZE):
                pass
        if process.wait():
            raise ValueError('Không giải mã hoặc xác thực được gói bằng identity đã chọn.')
    finally:
        if process.poll() is None:
            process.kill()
            process.wait()
    for target, link in links:
        target.symlink_to(link)


def restore(args):
    require_age()
    identity = args.identity_file.expanduser().absolute()
    if not identity.is_file() or identity.is_symlink() or identity.stat().st_mode & 0o077:
        raise ValueError('Identity age phải là file riêng có quyền 600 hoặc 400.')
    output = safe_destination(args.output)
    with tempfile.TemporaryDirectory(dir=output.parent, prefix='.tram-data-restore-') as stage_dir:
        stage = Path(stage_dir)
        bundle = git_fetch(args.repo, args.commit, stage) if args.repo else args.bundle.resolve(strict=True)
        encrypted = stage / 'bundle.age'
        join(bundle, encrypted)
        result = stage / 'verified'
        result.mkdir(mode=0o700)
        unpack(encrypted, identity, result)
        verify_export(result)
        publish(result, output)
    print(f'Đã giải mã và kiểm tra bản export tại {output}. Chỉ giữ thư mục này ngoài web root.')
    return output


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='action', required=True)
    pack_args = commands.add_parser('pack', help='Mã hóa bản export thành cây Git riêng')
    pack_args.add_argument('--source', type=Path, required=True)
    pack_args.add_argument('--output', type=Path, required=True)
    pack_args.add_argument('--recipient', required=True)
    for action, help in (('restore', 'Tải/giải mã và kiểm tra bản export'), ('install', 'Giải mã rồi cài trên VPS mới, tạo lại các trang')):
        command = commands.add_parser(action, help=help)
        origin = command.add_mutually_exclusive_group(required=True)
        origin.add_argument('--bundle', type=Path, help='Thư mục chứa gói mã hóa đã tải')
        origin.add_argument('--repo', help='URL repository Git của gói dữ liệu')
        command.add_argument('--commit', help='SHA chính xác của commit dữ liệu, bắt buộc với --repo')
        command.add_argument('--identity-file', type=Path, required=True)
        command.add_argument('--output', type=Path, required=True, help='Thư mục riêng mới, ngoài document root')
    argv = sys.argv[1:]
    native = []
    if '--' in argv:
        split = argv.index('--')
        argv, native = argv[:split], argv[split + 1:]
    args = parser.parse_args(argv)
    os.umask(0o077)
    try:
        if args.action == 'pack':
            if native:
                raise ValueError('Tham số sau -- chỉ dùng với install.')
            pack(args.source, args.output, args.recipient)
            return 0
        if bool(args.repo) != bool(args.commit):
            raise ValueError('--repo phải đi cùng --commit.')
        if native and args.action != 'install':
            raise ValueError('Tham số sau -- chỉ dùng với install.')
        if args.action == 'install':
            if not native:
                raise ValueError('Truyền tham số cài BaoTa sau --, ví dụ -- --domain admin.example.com.')
            if set(native) & {'--restore', '--env-file', '--storage-dir', '--verify-data', '--public-build-dir', '--fresh-pages', '--wizard'}:
                raise ValueError('Lệnh này tự chọn bản import và bật tạo lại trang; không truyền cờ dữ liệu khác.')
        output = restore(args)
        if args.action == 'install':
            flags = ['--restore', output / 'database.dump', '--env-file', output / 'application.env',
                     '--storage-dir', output / 'storage', '--verify-data', output / 'preservation.json', '--fresh-pages']
            if (output / 'public-build').is_dir():
                flags += ['--public-build-dir', output / 'public-build']
            return subprocess.run(['bash', ROOT / 'install.sh', *native, *flags]).returncode
    except (KeyError, TypeError, OSError, subprocess.SubprocessError, ValueError, tarfile.TarError) as error:
        print('Lỗi gói dữ liệu: ' + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
