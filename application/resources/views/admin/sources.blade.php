@extends('admin.layout')
@section('title', 'Nguồn nội dung')
@section('content')
@php
    $adapterLabels = ['maccms'=>'API MacCMS','shandian'=>'API Shandian','json'=>'File JSON','csv'=>'File CSV','html'=>'HTML','fixture'=>'Fixture / demo'];
    $statusLabels = ['active'=>'Đang hoạt động','paused'=>'Tạm dừng','archived'=>'Lưu trữ'];
@endphp
<div class="pagehead"><div><div class="eyebrow">CÀI ĐẶT DÙNG CHUNG</div><h1>Nguồn nội dung</h1><p>Quản lý kết nối nguồn. Gắn nguồn và ghép thể loại trong cấu hình từng website.</p></div><div class="actions">@if($canManageSources)<a class="button" href="/admin/sources/create">Thêm nguồn</a><a class="button secondary" href="/admin/movies">Kho phim</a>@endif<a class="button secondary" href="/admin/sources/history">Lịch sử nhận dữ liệu</a></div></div>
@unless($canManageSources)<p class="note">Nguồn dùng chung · bạn có quyền xem danh mục. Quản trị toàn hệ thống chỉnh kết nối; gắn nguồn và chọn thể loại tại website được phép quản lý.</p>@endunless
<section class="card"><form method="get" action="/admin/sources" class="toolbar">
<div><label>Tìm nguồn<input name="q" value="{{ $filters['q'] }}" placeholder="Tên hoặc ID nguồn"></label></div>
<div class="narrow"><label>Adapter<select name="kind"><option value="">Tất cả adapter</option>@foreach($adapterLabels as $kind=>$label)<option value="{{ $kind }}" @selected($filters['kind']===$kind)>{{ $label }}</option>@endforeach</select></label></div>
<div class="narrow"><label>Trạng thái<select name="status"><option value="">Tất cả trạng thái</option>@foreach($statusLabels as $state=>$label)<option value="{{ $state }}" @selected($filters['status']===$state)>{{ $label }}</option>@endforeach</select></label></div>
@if($canManageSources)<div class="narrow"><label>Hiển thị<select name="deleted"><option value="active" @selected($filters['deleted']==='active')>Chưa xóa</option><option value="trash" @selected($filters['deleted']==='trash')>Thùng rác</option><option value="all" @selected($filters['deleted']==='all')>Tất cả</option></select></label></div>@endif
<button class="secondary">Lọc danh sách</button></form><h2>{{ $sources->count() }} nguồn</h2>
<div class="tablewrap"><table class="source-directory"><thead><tr><th>Nguồn / adapter</th><th>Domain đang liên kết</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
@forelse($sources as $source)
@php($deleted = $source->deleted_at !== null)
<tr><td><strong>{{ $source->name }}</strong><small class="site-directory-meta">Connector #{{ $source->id }} · {{ $adapterLabels[$source->kind] ?? $source->kind }}</small>
@if($canManageSources && in_array($source->kind,['maccms','shandian','html'],true))@php($endpoint = json_decode($source->config_json,true)['endpoint'] ?? '')<small class="source-endpoint code">{{ is_string($endpoint) ? explode('?',$endpoint,2)[0] : '' }}</small>@endif</td>
<td><div class="source-binding-list">@forelse($sourceBindings[$source->id] ?? [] as $binding)<a href="/admin/sites/{{ $binding->site_id }}/edit?tab=sources">{{ $siteLabels->label($binding->site_id) }}</a>@empty<span class="muted">Chưa gắn website</span>@endforelse</div></td>
<td><span class="badge {{ $deleted ? 'archived' : $source->status }}">{{ $deleted ? 'Đã xóa' : ($statusLabels[$source->status] ?? $source->status) }}</span>
@if($canManageSources && in_array($source->kind,['maccms','shandian','html'],true))
<small class="site-directory-meta">{{ App\Domain\Ingestion\SourceSchedule::label(App\Domain\Ingestion\SourceSchedule::forSource($source)) }}</small>
<small class="site-directory-meta">{{ (int) $source->latest_max_pages }} trang mới nhất</small>
@if($deleted || $source->status !== 'active')<small class="site-directory-meta">Nguồn đang dừng; lịch chưa chạy.</small>@else<a href="/admin/sources/{{ $source->id }}/edit#schedule">Lịch cập nhật</a>@endif
@endif</td>
<td x-data><div class="crud-row-actions">@if($canManageSources && !$deleted)<a class="button secondary" aria-label="Sửa nguồn {{ $source->name }}" href="/admin/sources/{{ $source->id }}/edit">Sửa</a>@endif
@unless($deleted)<a class="button secondary" href="/admin/sources/{{ $source->id }}">{{ ($sourceCanOperate[$source->id] ?? false) ? 'Crawl / nhập' : 'Xem nguồn' }}</a>@endunless
@if($canManageSources)@if($deleted)<form method="post" action="/admin/sources/{{ $source->id }}/restore">@csrf<input type="hidden" name="row_version" value="{{ $source->row_version }}"><button class="secondary">Khôi phục</button></form>@else<button class="danger" type="button" aria-label="Xóa nguồn {{ $source->name }}" @click="$refs.remove.showModal()">Xóa</button>@endif @endif</div>
@if($canManageSources && !$deleted)<dialog class="crud-modal" x-ref="remove" aria-labelledby="source-remove-{{ $source->id }}"><div class="crud-dialog-head"><h2 id="source-remove-{{ $source->id }}">Xóa nguồn nội dung</h2><button type="button" class="secondary" @click="$refs.remove.close()" aria-label="Đóng">×</button></div><div class="crud-dialog-body"><p>Chuyển <strong>{{ $source->name }}</strong> vào thùng rác và ngừng nhận nội dung mới.</p><p>Phim, liên kết website và lịch sử được giữ nguyên. Có thể khôi phục nguồn ở trạng thái tạm dừng.</p><form method="post" action="/admin/sources/{{ $source->id }}/delete">@csrf<input type="hidden" name="row_version" value="{{ $source->row_version }}"><div class="crud-dialog-actions"><button class="danger">Chuyển vào thùng rác</button><button type="button" class="secondary" @click="$refs.remove.close()">Hủy</button></div></form></div></dialog>@endif</td></tr>
@empty<tr><td colspan="4" class="empty">Không có nguồn khớp bộ lọc.</td></tr>@endforelse
</tbody></table></div></section>
@if($canManageSources)<p class="muted">Xóa nguồn chuyển vào thùng rác, giữ dữ liệu đã nhập. Khôi phục không tự bật lại crawl hoặc tự áp dụng.</p>@endif
@endsection
