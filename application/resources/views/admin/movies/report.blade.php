@extends('admin.layout')
@section('title', 'Kết quả xử lý kho phim')
@section('content')
@php
    $actionLabels = ['archive' => 'Lưu trữ phim trong kho', 'restore' => 'Khôi phục phim trong kho', 'unlock_crawl' => 'Mở khóa cập nhật từ crawl', 'refresh' => 'Cập nhật phim từ nguồn'];
    $itemLabels = ['pending' => 'Đang chờ', 'completed' => 'Đã xử lý', 'conflict' => 'Xung đột', 'failed' => 'Lỗi'];
    $operationLabels = ['queued' => 'Đang chờ xử lý', 'processing' => 'Đang xử lý', 'completed' => 'Đã xử lý danh sách', 'partial' => 'Có phim cần kiểm tra', 'failed' => 'Có lỗi cần xử lý'];
    $errorLabels = [
        'MOVIE_VERSION_CONFLICT' => 'Dữ liệu phim đã thay đổi sau khi tạo yêu cầu. Kiểm tra rồi tạo yêu cầu mới.',
        'MOVIE_LIBRARY_VERSION_CONFLICT' => 'Dữ liệu phim đã thay đổi sau khi tạo yêu cầu. Kiểm tra rồi tạo yêu cầu mới.',
        'MOVIE_UPDATE_LOCKED' => 'Mở khóa crawl trước khi cập nhật từ nguồn để tránh ghi đè bản sửa tay.',
        'MOVIE_LIBRARY_CRAWL_LOCKED' => 'Mở khóa crawl trước khi cập nhật từ nguồn để tránh ghi đè bản sửa tay.',
        'MOVIE_ARCHIVED' => 'Phim đã lưu trữ trong kho. Khôi phục phim trước khi cập nhật từ nguồn.',
        'MOVIE_LIBRARY_ARCHIVED' => 'Phim đã lưu trữ trong kho. Khôi phục phim trước khi cập nhật từ nguồn.',
        'MOVIE_LIBRARY_PERMISSION_CHANGED' => 'Quyền quản trị đã thay đổi. Kiểm tra tài khoản vận hành.',
        'MOVIE_LIBRARY_CONFIGURATION_INVALID' => 'Cấu hình hoặc dữ liệu phim không hợp lệ. Kiểm tra trước khi tạo yêu cầu mới.',
        'MOVIE_LIBRARY_UNAVAILABLE' => 'Phim không còn phù hợp để xử lý thao tác này.',
        'MOVIE_LIBRARY_PROCESSING_FAILED' => 'Chưa xử lý được phim. Có thể thử lại dòng lỗi.',
        'MOVIE_REFRESH_SOURCE_UNAVAILABLE' => 'Nguồn không hoạt động hoặc không hỗ trợ lấy phim theo ID. Kiểm tra kết nối nguồn.',
        'MOVIE_REFRESH_SOURCE_CHANGED' => 'Cấu hình nguồn đã thay đổi trong lúc lấy dữ liệu. Kiểm tra và gửi yêu cầu mới.',
        'MOVIE_REFRESH_NOT_FOUND' => 'Nguồn không còn trả phim có ID này.',
        'MOVIE_REFRESH_INVALID_RECORD' => 'Dữ liệu phim trả về từ nguồn không hợp lệ.',
    ];
@endphp
<div class="pagehead"><div><div class="eyebrow">KHO PHIM / XỬ LÝ HÀNG LOẠT</div><h1>{{ $actionLabels[$action] ?? 'Xử lý kho phim' }}</h1><p>{{ number_format($total) }} phim · {{ $operationLabels[$status] ?? $status }}</p></div><div class="actions"><a class="button secondary" href="/admin/movies/operations/{{ $id }}{{ $itemFilter !== '' ? '?status='.$itemFilter : '' }}">Tải lại trạng thái</a><a class="button" href="/admin/movies">Kho phim / tạo yêu cầu mới</a></div></div>
@if(($counts['pending'] ?? 0) > 0)<p class="note">Yêu cầu đang xử lý theo nhóm. Chỉ các dòng “Đã xử lý” mới được xác nhận; bấm Tải lại trạng thái để xem kết quả mới.</p>@endif
<section class="card">
    <div class="formrow">@foreach($itemLabels as $state => $label)<div><span class="muted">{{ $label }}</span><h2>{{ number_format($counts[$state] ?? 0) }}</h2></div>@endforeach</div>
    @if(($counts['failed'] ?? 0) > 0)<form method="post" action="/admin/movies/operations/{{ $id }}/retry">@csrf<button class="secondary">Thử lại các phim lỗi</button></form><p class="muted">Chỉ thử lại dòng lỗi. Dòng đã xử lý và xung đột được giữ nguyên.</p>@endif
    @if(($counts['conflict'] ?? 0) > 0)<p class="note">Kiểm tra các phim xung đột rồi tạo yêu cầu mới. Yêu cầu cũ giữ phiên bản dữ liệu đã chọn, không âm thầm áp dụng lên bản vừa sửa.</p>@endif
    <p class="muted">Lưu trữ trong kho giữ các URL đang phục vụ và keyword. Cập nhật từ nguồn tự áp dụng dữ liệu phim mới trên các website đang dùng và cho phép cập nhật, đồng thời làm mới cache; không cần xuất bản thủ công.</p>
    <details><summary>Thông tin yêu cầu</summary><p class="code">{{ $id }}</p><p>Tạo lúc {{ $operation->created_at }} · Cập nhật {{ $operation->updated_at }}</p></details>
</section>
<section class="card">
    <div class="sectionhead"><h2>Kết quả từng phim</h2><span class="muted">{{ $items->count() }} / {{ number_format($items->total()) }} dòng khớp bộ lọc</span></div>
    <form method="get" action="/admin/movies/operations/{{ $id }}" class="toolbar"><label>Trạng thái<select name="status"><option value="">Tất cả</option>@foreach($itemLabels as $state => $label)<option value="{{ $state }}" @selected($itemFilter === $state)>{{ $label }}</option>@endforeach</select></label><button>Lọc kết quả</button></form>
    <div class="tablewrap"><table><thead><tr><th>Phim</th><th>Trạng thái</th><th>Kết quả / lỗi</th><th>Thao tác</th></tr></thead><tbody>
        @forelse($items as $item)
            <tr><td><strong>{{ $item->title ?: 'Phim #'.$item->content_item_id }}</strong><div><small>ID kho #{{ $item->content_item_id }} · ID nguồn {{ $item->external_id }}</small></div></td><td><span class="badge {{ $item->status === 'completed' ? 'active' : (in_array($item->status, ['conflict', 'failed'], true) ? 'error' : 'pending') }}">{{ $itemLabels[$item->status] ?? $item->status }}</span><div><small>{{ $item->attempts }} lần xử lý</small></div></td><td>
            @if($item->status === 'completed')<p>Đã xử lý thao tác cho phim này.</p>@elseif($item->error_code)<p>{{ $errorLabels[$item->error_code] ?? 'Chưa xử lý được phim. Kiểm tra dữ liệu và mã lỗi bên dưới.' }}</p><small class="code">{{ $item->error_code }}</small>@else<p class="muted">Đang chờ worker xử lý.</p>@endif
            </td><td><a href="/admin/movies/{{ $item->content_item_id }}/edit">Mở thông tin phim →</a></td></tr>
        @empty<tr><td colspan="4" class="empty">Không có phim khớp bộ lọc này.</td></tr>@endforelse
    </tbody></table></div><div class="pagination">{{ $items->links() }}</div>
</section>
@endsection
