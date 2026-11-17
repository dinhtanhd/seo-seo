@extends('admin.layout')
@section('title', 'Xóa & thùng rác')
@section('content')
@php
    $selectedIds = old('ids', []);
    $selectedIds = is_array($selectedIds) ? array_map('strval', array_filter($selectedIds, 'is_scalar')) : [];
    $idsText = old('ids_text', '');
    $idsText = is_string($idsText) ? $idsText : '';
    $requestKey = $requestKey ?? (string) Illuminate\Support\Str::uuid();
    $oldKey = old('idempotency_key', $requestKey);
    $formKey = is_string($oldKey) ? $oldKey : $requestKey;
    $visibleVersions = [];
    foreach ($items as $entry) { $visibleVersions[(string) $entry->id] = (int) $entry->row_version; }
    $isTrash = ($filters['status'] ?? 'active') === 'trash';
@endphp
<div class="pagehead"><div><div class="eyebrow">QUẢN LÝ DỮ LIỆU</div><h1>Xóa &amp; thùng rác</h1><p>Kiểm tra các nơi đang sử dụng trước khi xóa. Mục đã xóa được giữ trong thùng rác để có thể khôi phục.</p></div><a class="button secondary" href="/admin">Tổng quan</a></div>
<section class="card">
    <form method="get" action="/admin/deletions" class="toolbar">
        <label>Loại dữ liệu<select name="kind">@foreach($kinds as $value => $label)<option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Hiển thị<select name="status"><option value="active" @selected(!$isTrash)>Chưa xóa</option><option value="trash" @selected($isTrash)>Thùng rác</option></select></label>
        <label>Tìm tên hoặc ID<input name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label><button>Lọc danh sách</button>
    </form>
    <p class="muted">{{ number_format($items->total()) }} mục · 50 mục mỗi trang. Lưu trữ nghiệp vụ và xóa vào thùng rác là hai thao tác riêng.</p>
</section>
<form method="post" action="/admin/deletions/bulk">
    @csrf<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="idempotency_key" value="{{ $formKey }}"><input type="hidden" name="versions_json" value="{{ json_encode((object) $visibleVersions, JSON_THROW_ON_ERROR) }}">
    <section class="card">
        <h2>{{ $isTrash ? 'Khôi phục nhiều mục' : 'Xóa nhiều mục' }}</h2>
        <p>Chọn các ô trong danh sách hoặc dán ID bên dưới. Chỉ dùng một cách chọn cho mỗi yêu cầu, tối đa 10.000 mục.</p>
        <label>Danh sách ID<textarea name="ids_text" rows="4" maxlength="220000" placeholder="123&#10;456">{{ $idsText }}</textarea><small>Để trống khi dùng các ô chọn. Có thể phân cách ID bằng xuống dòng, dấu phẩy hoặc khoảng trắng.</small></label>
        <p class="muted">Hệ thống kiểm tra từng mục và báo rõ mục đã xử lý, mục bị chặn hoặc gặp lỗi. Mục đang được sử dụng sẽ được giữ lại.</p>
        <button class="{{ $isTrash ? 'secondary' : 'danger' }}" name="action" value="{{ $isTrash ? 'restore' : 'delete' }}">{{ $isTrash ? 'Yêu cầu khôi phục các mục đã chọn' : 'Kiểm tra & xóa các mục đã chọn' }}</button>
    </section>
    <section class="card"><div class="tablewrap"><table><thead><tr><th>Chọn</th><th>Mục dữ liệu</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
        @forelse($items as $entry)
            <tr><td><input type="checkbox" name="ids[]" value="{{ $entry->id }}" aria-label="Chọn {{ $entry->label }}" @checked(in_array((string) $entry->id, $selectedIds, true))></td><td><strong>{{ $entry->label }}</strong><div><small>#{{ $entry->id }}</small></div></td><td>{{ ($entry->deleted_at ?? null) !== null ? 'Trong thùng rác' : 'Chưa xóa' }}</td><td><a href="/admin/deletions/{{ $kind }}/{{ $entry->id }}">{{ ($entry->deleted_at ?? null) !== null ? 'Kiểm tra & khôi phục' : 'Kiểm tra trước khi xóa' }} →</a></td></tr>
        @empty<tr><td colspan="4" class="empty">Không có mục khớp bộ lọc.</td></tr>@endforelse
    </tbody></table></div><div class="pagination">{{ $items->links() }}</div><p class="muted">Các ô chọn chỉ áp dụng cho trang danh sách hiện tại. Dùng danh sách ID để xử lý nhiều trang.</p></section>
</form>
@php
    $recentDeletionOperations = collect($recentOperations ?? [])->take(5);
@endphp
@if($recentDeletionOperations->isNotEmpty())
    @php
        $operationLabels = ['queued' => 'Đang chờ', 'pending' => 'Đang chờ', 'running' => 'Đang xử lý', 'processing' => 'Đang xử lý', 'completed' => 'Đã xử lý', 'partial' => 'Có mục cần kiểm tra', 'failed' => 'Có lỗi'];
        $actionLabels = ['delete' => 'Xóa vào thùng rác', 'restore' => 'Khôi phục'];
    @endphp
    <section class="card"><h2>Yêu cầu gần đây · {{ $kinds[$kind] ?? $kind }}</h2><div class="tablewrap"><table><thead><tr><th>Yêu cầu</th><th>Số mục</th><th>Trạng thái</th></tr></thead><tbody>
        @foreach($recentDeletionOperations as $recentOperation)
            <tr><td><a href="/admin/deletions/operations/{{ $recentOperation->id }}">{{ $actionLabels[$recentOperation->action] ?? $recentOperation->action }} →</a><small class="site-directory-meta code">{{ $recentOperation->id }}</small></td><td>{{ number_format($recentOperation->total) }}</td><td>{{ $operationLabels[$recentOperation->status] ?? $recentOperation->status }}</td></tr>
        @endforeach
    </tbody></table></div></section>
@endif
@endsection
