@extends('admin.layout')
@section('title', 'Kết quả xóa / khôi phục website')
@section('content')
@php
    $isRestore = $operation->action === 'restore';
    $operationLabels = ['preview' => 'Chờ xác nhận', 'queued' => 'Đang chờ', 'running' => 'Đang xử lý', 'completed' => 'Đã xử lý', 'partial' => 'Có website cần kiểm tra', 'failed' => 'Có lỗi'];
    $itemLabels = ['pending' => 'Đang chờ', 'queued' => 'Đang chờ', 'running' => 'Đang xử lý', 'completed' => $isRestore ? 'Đã khôi phục' : 'Đã xóa', 'deleted' => 'Đã xóa', 'restored' => 'Đã khôi phục', 'conflict' => 'Xung đột', 'failed' => 'Lỗi'];
@endphp
<div class="pagehead">
    <div><div class="eyebrow">KẾT QUẢ WEBSITE &amp; DOMAIN</div><h1>{{ $isRestore ? 'Kết quả khôi phục website' : 'Kết quả xóa website' }}</h1><p>{{ number_format($operation->total) }} website · {{ $operationLabels[$operation->status] ?? $operation->status }}</p></div>
    <div class="actions"><a class="button secondary" href="/admin/sites/delete-operations/{{ $operation->id }}">Tải lại trạng thái</a><a class="button secondary" href="/admin/sites/deleted">Website đã xóa</a><a class="button secondary" href="/admin/sites">Website &amp; domain</a></div>
</div>
<section class="card">
    <h2>Kết quả theo trạng thái</h2>
    <div class="formrow">
        @foreach(['pending' => 'Đang chờ', 'running' => 'Đang xử lý', 'completed' => ($isRestore ? 'Đã khôi phục' : 'Đã xóa'), 'conflict' => 'Xung đột', 'failed' => 'Lỗi'] as $state => $label)
            <div><span class="muted">{{ $label }}</span><h2>{{ isset($counts[$state]) && is_numeric($counts[$state]) ? number_format((int) $counts[$state]) : '—' }}</h2></div>
        @endforeach
    </div>
    <p>Mỗi website được kiểm tra và xử lý riêng. Các dòng đang chờ chưa có hiệu lực; website gặp xung đột được giữ nguyên cùng lý do bên dưới.</p>
    @if(($counts['failed'] ?? 0) > 0)
        <form method="post" action="/admin/sites/delete-operations/{{ $operation->id }}/retry">@csrf<input type="hidden" name="row_version" value="{{ $operation->row_version }}"><button class="secondary">Thử lại các website lỗi</button></form>
        <p class="muted">Chỉ thử lại dòng lỗi. Website đã xử lý được giữ nguyên; dòng xung đột cần được kiểm tra rồi tạo yêu cầu mới.</p>
    @endif
    @if($isRestore)
        <p class="note">Website khôi phục ở trạng thái tạm dừng. Kiểm tra cấu hình website trước khi bật lại. Domain đã được website khác đăng ký sẽ chặn khôi phục website liên quan.</p>
    @else
        <p class="note">Website đã xóa không còn phục vụ và domain đã được giải phóng. Trang cùng lịch sử được giữ; mở Website đã xóa để khôi phục. Kho phim, keyword, nguồn và giao diện dùng chung được giữ nguyên.</p>
    @endif
    <details><summary>Thông tin yêu cầu</summary><p class="code">{{ $operation->id }}</p></details>
</section>
<section class="card">
    <div class="sectionhead"><h2>Kết quả từng website</h2><span class="muted">{{ $items->count() }} / {{ number_format($items->total()) }} website</span></div>
    <div class="tablewrap"><table>
        <thead><tr><th>Website / domain</th><th>Trạng thái</th><th>Kết quả / nguyên nhân</th><th>Thao tác</th></tr></thead>
        <tbody>
            @forelse($items as $item)
                @php($completed = in_array($item->status, ['completed', 'deleted', 'restored'], true))
                <tr>
                    <td><strong class="code">{{ $item->domain ?: 'Website #'.$item->site_id }}</strong><div><small>#{{ $item->site_id }} · {{ $item->name }}</small></div>
                        @foreach($item->domains as $domain)<div class="code">{{ data_get($domain, 'host_ascii') }}</div>@endforeach
                    </td>
                    <td><span class="badge {{ $completed ? 'active' : (in_array($item->status, ['conflict', 'failed'], true) ? 'error' : 'pending') }}">{{ $itemLabels[$item->status] ?? $item->status }}</span></td>
                    <td>
                        @if($completed)
                            <p>{{ $isRestore ? 'Đã khôi phục website ở trạng thái tạm dừng.' : 'Đã xóa website và giải phóng các domain đã liệt kê.' }}</p>
                        @elseif(!empty($item->error_message))
                            <p>{{ $item->error_message }}</p>
                        @elseif($item->status === 'conflict')
                            <p>Dữ liệu website hoặc domain đã thay đổi. Kiểm tra lại trước khi tạo yêu cầu mới.</p>
                        @elseif($item->status === 'failed')
                            <p>Chưa xử lý được website này. Kiểm tra mã lỗi và thử lại sau.</p>
                        @else
                            <p class="muted">{{ $item->status === 'running' ? 'Hệ thống đang xử lý website.' : 'Đang chờ hệ thống xử lý.' }}</p>
                        @endif
                        @if(!empty($item->error_code))<small class="code">{{ $item->error_code }}</small>@endif
                    </td>
                    <td>
                        @if($isRestore && $completed)
                            <a href="/admin/sites/{{ $item->site_id }}/edit">Kiểm tra cấu hình website →</a>
                        @else
                            <a href="{{ $isRestore || $completed ? '/admin/sites/deleted' : '/admin/sites' }}">{{ $isRestore || $completed ? 'Website đã xóa' : 'Danh sách website' }} →</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Chưa có kết quả để hiển thị.</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <div class="pagination">{{ $items->links() }}</div>
</section>
@endsection
