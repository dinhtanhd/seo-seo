@extends('admin.layout')
@section('title', 'Kho phim dùng chung')
@section('content')
@php
    $mode = old('selection_mode', 'selected');
    $mode = in_array($mode, ['selected', 'ids', 'filter'], true) ? $mode : 'selected';
    $oldIds = old('movie_ids', []);
    $selectedIds = is_array($oldIds) ? array_map('strval', array_filter($oldIds, 'is_scalar')) : [];
    $idsText = old('movie_ids_text', '');
    $idsText = is_string($idsText) ? $idsText : '';
    $oldKey = old('idempotency_key', $requestKey);
    $formKey = is_string($oldKey) ? $oldKey : $requestKey;
@endphp
<div class="pagehead"><div><div class="eyebrow">NỘI DUNG DÙNG CHUNG</div><h1>Kho phim</h1><p>Quản lý dữ liệu phim một lần cho hệ thống. Phim theo từng nguồn giữ nguyên ID, không phụ thuộc vào tên miền.</p></div><div class="actions"><a class="button" href="/admin/movies/create">Thêm phim</a><a class="button secondary" href="/admin/deletions?kind=movie">Xóa &amp; khôi phục hàng loạt</a><a class="button secondary" href="/admin/sources">Nguồn nội dung</a><a class="button secondary" href="/admin/pages">Trang theo domain</a></div></div>
<section class="card">
    <form method="get" action="/admin/movies" class="toolbar">
        <label>Tên phim<input name="q" maxlength="200" value="{{ $filters['q'] }}" placeholder="Tên phim cần tìm"></label>
        <label>Nguồn<select name="source_id"><option value="">Tất cả nguồn</option>@foreach($sources as $source)<option value="{{ $source->id }}" @selected((string) $filters['source_id'] === (string) $source->id)>{{ $source->name }}{{ $source->deleted_at ? ' · đã lưu trữ' : '' }}</option>@endforeach</select></label>
        <label>ID thể loại nguồn<input name="category_external_id" maxlength="255" value="{{ $filters['category_external_id'] }}" placeholder="Ví dụ: 1, 6…"></label>
        <label>Năm<input name="year" maxlength="20" value="{{ $filters['year'] }}" placeholder="2026"></label>
        <label>Trong kho<select name="library_status"><option value="active" @selected($filters['status'] === 'active')>Đang sử dụng</option><option value="archived" @selected($filters['status'] === 'archived')>Đã lưu trữ</option><option value="all" @selected($filters['status'] === 'all')>Tất cả</option></select></label>
        <label>Cập nhật từ nguồn<select name="manual_update_locked"><option value="">Tất cả</option><option value="0" @selected((string) $filters['manual_update_locked'] === '0')>Cho phép crawl cập nhật</option><option value="1" @selected((string) $filters['manual_update_locked'] === '1')>Đã khóa để giữ bản sửa tay</option></select></label>
        <button>Lọc phim</button><a class="button secondary" href="/admin/movies">Xóa bộ lọc</a>
    </form>
    <p class="muted">{{ number_format($movies->total()) }} phim khớp bộ lọc · 50 phim mỗi trang. Thể loại nguồn và danh mục của từng website được quản lý riêng.</p>
</section>
<form method="post" action="/admin/movies/bulk" x-data="{ mode: @js($mode) }">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ $formKey }}">
    @foreach($filters as $field => $value)<input type="hidden" name="{{ $field === 'status' ? 'library_status' : $field }}" value="{{ $value }}">@endforeach
    <section class="card">
        <h2>Thao tác phim hàng loạt</h2>
        <div class="formrow">
            <label>Chọn phim<select name="selection_mode" x-model="mode"><option value="selected" @selected($mode === 'selected')>Các ô đã chọn trong danh sách</option><option value="ids" @selected($mode === 'ids')>Dán danh sách ID kho phim</option><option value="filter" @selected($mode === 'filter')>Tất cả phim khớp bộ lọc hiện tại</option></select></label>
            <label>Thao tác<select name="action"><option value="refresh" @selected(old('action', 'refresh') === 'refresh')>Cập nhật từ nguồn</option><option value="archive" @selected(old('action') === 'archive')>Lưu trữ trong kho</option><option value="restore" @selected(old('action') === 'restore')>Khôi phục trong kho</option><option value="unlock_crawl" @selected(old('action') === 'unlock_crawl')>Mở khóa cập nhật từ crawl</option></select></label>
        </div>
        <label x-show="mode === 'ids'">ID phim trong kho<textarea name="movie_ids_text" rows="5" maxlength="1048576" placeholder="123&#10;456">{{ $idsText }}</textarea><small>Dùng ID kho hiển thị dưới tên phim, không dùng ID phim bên API. Phân cách bằng xuống dòng, dấu phẩy hoặc khoảng trắng.</small></label>
        <label class="checkboxline" x-show="mode === 'filter'"><input type="checkbox" name="confirm_all" value="1" @checked(old('confirm_all') === '1')>Tôi xác nhận chọn tất cả {{ number_format($movies->total()) }} phim khớp bộ lọc, kể cả các trang danh sách khác.</label>
        <p class="note">Tối đa 10.000 phim mỗi lượt. Lưu trữ trong kho giữ các URL đang phục vụ và keyword đã gán; phim ngừng được dùng mới. Khôi phục kho không tự khôi phục các trang website đã lưu trữ.</p>
        <p class="muted">Dữ liệu hợp lệ nhận từ nguồn tự cập nhật các website đang dùng và làm mới cache, giữ URL và keyword. Phim khóa sửa tay cần mở khóa crawl trước; lượt cập nhật nguồn có hàng đợi và báo kết quả từng phim.</p>
        <div class="actions"><button>Tạo yêu cầu xử lý phim</button><button type="button" class="secondary" @click="$refs.movieTable.querySelectorAll('input[type=checkbox]').forEach(el => el.checked = true); mode = 'selected'">Chọn cả trang này</button><button type="button" class="secondary" @click="$refs.movieTable.querySelectorAll('input[type=checkbox]').forEach(el => el.checked = false)">Bỏ chọn</button></div>
    </section>
    <section class="card">
        <div class="tablewrap"><table x-ref="movieTable">
            <thead><tr><th>Chọn</th><th>Phim / nguồn</th><th>Thông tin</th><th>Trạng thái kho</th><th>Thao tác</th></tr></thead>
            <tbody>@forelse($movies as $movie)
                <tr>
                    <td><input type="checkbox" name="movie_ids[]" value="{{ $movie->id }}" aria-label="Chọn phim {{ $movie->title }}" @checked(in_array((string) $movie->id, $selectedIds, true)) @change="mode = 'selected'"></td>
                    <td><a href="/admin/movies/{{ $movie->id }}/edit"><strong>{{ $movie->title ?: 'Phim chưa có tiêu đề' }}</strong></a><div><small>ID kho #{{ $movie->id }} · {{ $movie->source_name }}</small></div><small class="code">ID nguồn: {{ $movie->external_id }}</small></td>
                    <td>{{ $movie->genre ?: 'Chưa có thể loại' }}<div><small>{{ $movie->area ?: 'Chưa có khu vực' }} · {{ $movie->year ?: 'Chưa có năm' }} · {{ $movie->language ?: 'Chưa có ngôn ngữ' }}</small></div><small>ID thể loại nguồn: {{ $movie->category_external_id ?: '—' }}</small></td>
                    <td><span class="badge {{ $movie->library_status === 'archived' ? 'archived' : 'active' }}">{{ $movie->library_status === 'archived' ? 'Đã lưu trữ' : 'Đang sử dụng' }}</span><div><small>{{ $movie->manual_update_locked ? 'Khóa crawl · giữ bản sửa tay' : 'Cho phép crawl cập nhật' }}</small></div></td>
                    <td><div class="actions"><a href="/admin/movies/{{ $movie->id }}/edit">Sửa thông tin & tập phim →</a>@if(($movie->deleted_at ?? null) === null)<a class="button danger" href="/admin/deletions/movie/{{ $movie->id }}">Xóa</a>@endif</div></td>
                </tr>
            @empty<tr><td colspan="5" class="empty">Chưa có phim khớp bộ lọc. Thêm phim hoặc nhận dữ liệu tại Nguồn nội dung.</td></tr>@endforelse</tbody>
        </table></div>
        <div class="pagination">{{ $movies->links() }}</div>
        <p class="muted">Các ô chọn chỉ áp dụng cho trang danh sách hiện tại. Dùng danh sách ID hoặc tất cả theo bộ lọc để xử lý nhiều trang.</p>
    </section>
</form>
@if($recentOperations->isNotEmpty())
    @php
        $actionLabels = ['archive' => 'Lưu trữ', 'restore' => 'Khôi phục', 'unlock_crawl' => 'Mở khóa crawl', 'refresh' => 'Cập nhật nguồn'];
        $operationLabels = ['queued' => 'Đang chờ', 'processing' => 'Đang xử lý', 'completed' => 'Đã xử lý', 'partial' => 'Có phim cần kiểm tra', 'failed' => 'Có lỗi'];
    @endphp
    <section class="card"><h2>Yêu cầu gần đây của bạn</h2><div class="tablewrap"><table><thead><tr><th>Thao tác</th><th>Số phim</th><th>Trạng thái</th><th>Thời gian</th></tr></thead><tbody>@foreach($recentOperations as $operation)<tr><td><a href="/admin/movies/operations/{{ $operation->id }}">{{ $actionLabels[$operation->action] ?? $operation->action }} →</a></td><td>{{ number_format($operation->total) }}</td><td>{{ $operationLabels[$operation->status] ?? $operation->status }}</td><td>{{ $operation->created_at }}</td></tr>@endforeach</tbody></table></div></section>
@endif
@endsection
