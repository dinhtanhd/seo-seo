@extends('admin.layout')
@section('title', 'Giao diện phim')
@section('content')
<div class="pagehead">
    <div><div class="eyebrow">FOLDER / HTML / CSS / GIAO DIỆN PHIM</div><h1>Kho giao diện phim</h1><p>Bộ mới gồm home/list/detail/play/search; bộ bốn trang cũ giữ phiên bản riêng. Chọn folder và đường dẫn tại cấu hình website.</p></div>
    @if(auth()->user()->is_global_admin)<div class="actions"><a class="button" href="/admin/themes/create">Tạo giao diện</a><a class="button secondary" href="/admin/deletions?kind=theme">Xóa &amp; khôi phục hàng loạt</a></div>@endif
</div>
<p class="note">友情链接 tự lấy tối đa 10 domain chính của các website đang hoạt động trong Trạm SEO. Tên website là chữ liên kết; không lấy chính website đang mở hoặc các website đã dừng. Giao diện có vùng này sẽ dùng đúng vị trí hiện có.</p>
<form method="get" class="toolbar">
    <div><label>Tìm giao diện<input name="q" value="{{ request('q') }}" placeholder="Tên hoặc mã giao diện"></label></div>
    <div class="narrow"><label>Trạng thái<select name="status"><option value="" @selected($status === '')>Tất cả</option>@foreach(['active'=>'Đang dùng','archived'=>'Đã archive'] as $value=>$label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></label></div>
    <button class="secondary">Lọc kho</button>
</form>
<section class="card"><details><summary>Folder trên máy chủ · templates/</summary><p class="muted">Các folder được đọc và kiểm tra từ filesystem. Nhập vào kho tạo phiên bản database; chưa tự gán hoặc xuất bản website.</p><div class="tablewrap"><table><thead><tr><th>Folder / bộ giao diện</th><th>Cấu trúc</th><th>Kiểm tra / thao tác</th></tr></thead><tbody>@forelse($folders as $folder)<tr><td><strong>{{ $folder['name'] }}</strong><p class="code">templates/{{ $folder['slug'] }}/</p>@if($folder['installed_theme_id'])<a href="/admin/themes/{{ $folder['installed_theme_id'] }}">Bản trong kho #{{ $folder['installed_theme_id'] }}</a>@else<span class="muted">Chưa nhập vào kho</span>@endif</td><td>@if($folder['status']==='valid')<details><summary>{{ count($folder['files']) }} files · schema {{ $folder['schema_version'] }}</summary><ul class="theme-file-tree">@foreach($folder['files'] as $file)<li class="code">{{ $file }}</li>@endforeach</ul></details><small>{{ $folder['asset_count'] }} ảnh/font trong gói</small>@else<p class="muted">Chưa đọc được gói hợp lệ.</p>@endif</td><td><span class="badge {{ $folder['status']==='valid' ? 'active' : 'failed' }}">{{ $folder['status']==='valid' ? 'Hợp lệ' : 'Cần sửa' }}</span>@if($folder['error'])<p class="errornotice">{{ $folder['error'] }}</p>@endif @if(auth()->user()->is_global_admin && $folder['status']==='valid')<form method="post" action="/admin/themes/folders/{{ $folder['slug'] }}/import">@csrf @if($folder['installed_theme_id'])<input type="hidden" name="theme_id" value="{{ $folder['installed_theme_id'] }}"><input type="hidden" name="row_version" value="{{ $folder['installed_row_version'] }}">@endif<button class="secondary">{{ $folder['installed_theme_id'] ? 'Nhập thành phiên bản mới' : 'Nhập folder vào kho' }}</button></form>@endif</td></tr>@empty<tr><td colspan="3" class="empty">Chưa có folder trong templates/. Có thể tạo giao diện hoặc nhập ZIP vào database.</td></tr>@endforelse</tbody></table></div></details></section>
<div class="theme-grid">
    @forelse($themes as $theme)
        <article class="card theme-card">
            @if($site)<div class="theme-thumbnail-wrap"><iframe class="theme-thumbnail" title="{{ $theme->name }}" src="/admin/themes/{{ $theme->id }}/preview?site_id={{ $site->id }}&view=home" sandbox="" referrerpolicy="no-referrer" loading="lazy" tabindex="-1"></iframe></div>@else<div class="theme-swatch"><span>电影</span><div><i></i><i></i><i></i><i></i></div></div>@endif
            <div class="actions"><span class="badge {{ $theme->status }}">{{ $theme->status }}</span><span class="muted">v{{ $theme->version_no ?? 0 }}</span></div>
            <h2><a href="/admin/themes/{{ $theme->id }}">{{ $theme->name }}</a></h2>
            <p class="muted">{{ $theme->description ?? '' }}</p>
            <p class="code">templates/{{ $theme->slug }}/ · #{{ $theme->id }}</p>
            <div class="actions"><a class="button secondary" href="/admin/themes/{{ $theme->id }}">Mở & xem trước</a><a href="/admin/themes/{{ $theme->id }}/export">Xuất ZIP</a>@if(auth()->user()->is_global_admin && ($theme->deleted_at ?? null) === null)<a class="button danger" href="/admin/deletions/theme/{{ $theme->id }}">Xóa</a>@endif</div>
        </article>
    @empty
        <section class="card empty">Chưa có giao diện khớp bộ lọc.</section>
    @endforelse
</div>
<div class="pagination">{{ $themes->appends(request()->only('q') + ['status' => $status])->links() }}</div>
@if(auth()->user()->is_global_admin)
<section class="card"><h2>Nhập folder giao diện ZIP</h2><form action="/admin/themes/import" method="post" enctype="multipart/form-data">@csrf<label>Gói ZIP<input type="file" name="file" accept=".zip,application/zip" required></label><p class="muted">Gói mới có html/ gồm năm trang, css/style.css, ảnh/font và manifest.json hoặc info.ini; vẫn đọc được gói legacy bốn trang. Server kiểm tra đường dẫn và dung lượng trước khi lưu phiên bản. Gói chưa tự gán cho website.</p><button>Kiểm tra & nhập giao diện</button></form></section>
@endif
@endsection
