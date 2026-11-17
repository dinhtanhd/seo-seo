@extends('admin.layout')
@section('title', 'Kết quả xóa / khôi phục hàng loạt')
@section('content')
@php
    $statusLabels = ['queued' => 'Đang chờ', 'pending' => 'Đang chờ', 'running' => 'Đang xử lý', 'processing' => 'Đang xử lý', 'completed' => 'Đã xử lý', 'deleted' => 'Đã chuyển vào thùng rác', 'restored' => 'Đã khôi phục', 'blocked' => 'Bị chặn', 'conflict' => 'Dữ liệu đã thay đổi', 'failed' => 'Lỗi', 'partial' => 'Có mục cần kiểm tra'];
    $isRestore = $operation->action === 'restore';
@endphp
<div class="pagehead"><div><div class="eyebrow">XÓA &amp; THÙNG RÁC / HÀNG LOẠT</div><h1>{{ $isRestore ? 'Kết quả khôi phục hàng loạt' : 'Kết quả xóa hàng loạt' }}</h1><p>{{ number_format($operation->total) }} mục · {{ $statusLabels[$operation->status] ?? $operation->status }}</p></div><div class="actions"><a class="button secondary" href="/admin/deletions/operations/{{ $operation->id }}">Tải lại trạng thái</a><a class="button secondary" href="/admin/deletions">Xóa &amp; thùng rác</a></div></div>
<section class="card"><p>Mỗi mục được kiểm tra và xử lý riêng. Các dòng đang chờ chưa được xác nhận; mục bị chặn được giữ nguyên cùng lý do bên dưới.</p>@if(($canRetry ?? false) === true)<form method="post" action="/admin/deletions/operations/{{ $operation->id }}/retry">@csrf<button class="secondary">Thử lại các mục lỗi</button></form><p class="muted">Dòng đã xử lý giữ nguyên. Mục đang sử dụng hoặc dữ liệu vừa thay đổi cần được kiểm tra trước khi tạo yêu cầu mới.</p>@endif<details><summary>Thông tin yêu cầu</summary><p class="code">{{ $operation->id }}</p></details></section>
<section class="card"><h2>Kết quả từng mục</h2><div class="tablewrap"><table><thead><tr><th>Mục dữ liệu</th><th>Trạng thái</th><th>Kết quả / nguyên nhân</th><th>Thao tác</th></tr></thead><tbody>
    @forelse($results as $result)
        <tr><td><strong>{{ $result->label ?? ucfirst($result->kind).' #'.$result->item_id }}</strong><div><small>{{ $kinds[$result->kind] ?? $result->kind }} · #{{ $result->item_id }}</small></div></td><td><span class="badge {{ in_array($result->status, ['completed', 'deleted', 'restored'], true) ? 'active' : (in_array($result->status, ['blocked', 'conflict', 'failed'], true) ? 'error' : 'pending') }}">{{ $statusLabels[$result->status] ?? $result->status }}</span></td><td>
        @if(!empty($result->error_message))<p>{{ $result->error_message }}</p>@elseif(in_array($result->status, ['completed', 'deleted', 'restored'], true))<p>{{ $isRestore ? 'Đã khôi phục mục này.' : 'Đã chuyển mục này vào thùng rác.' }}</p>@elseif(in_array($result->status, ['queued', 'pending', 'running', 'processing'], true))<p class="muted">Đang chờ hệ thống xử lý.</p>@else<p>Chưa xử lý được. Mở chi tiết để kiểm tra nơi đang sử dụng.</p>@endif
        @if(!empty($result->error_code))<small class="code">{{ $result->error_code }}</small>@endif
        </td><td><a href="/admin/deletions/{{ $result->kind }}/{{ $result->item_id }}">Kiểm tra chi tiết →</a></td></tr>
    @empty<tr><td colspan="4" class="empty">Chưa có kết quả để hiển thị. Tải lại trạng thái sau khi hàng đợi xử lý.</td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $results->links() }}</div></section>
@endsection
