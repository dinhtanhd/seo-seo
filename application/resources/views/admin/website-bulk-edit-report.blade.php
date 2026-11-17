@extends('admin.layout')
@section('title', 'Kết quả sửa domain hàng loạt')
@section('content')
@php
    $operationLabels = ['queued' => 'Chờ xử lý', 'processing' => 'Đang xử lý', 'completed' => 'Đã xử lý', 'partial' => 'Có domain cần xử lý', 'failed' => 'Có lỗi'];
    $itemLabels = ['pending' => 'Đang chờ', 'running' => 'Đang xử lý', 'completed' => 'Đã áp dụng', 'conflict' => 'Xung đột', 'failed' => 'Lỗi'];
    $errorLabels = [
        'WEBSITE_BULK_EDIT_SITE_CHANGED' => 'Cấu hình website đã thay đổi sau khi tạo yêu cầu. Kiểm tra rồi tạo yêu cầu mới.',
        'WEBSITE_BULK_EDIT_ADVERTISING_CHANGED' => 'Lựa chọn quảng cáo đã thay đổi. Kiểm tra rồi tạo yêu cầu mới.',
        'WEBSITE_BULK_EDIT_THEME_CHANGED' => 'Giao diện có phiên bản mới hoặc đã thay đổi. Chọn lại trong yêu cầu mới.',
        'WEBSITE_BULK_EDIT_PERMISSION_CHANGED' => 'Quyền quản lý website đã thay đổi.',
        'WEBSITE_BULK_EDIT_SITE_UNAVAILABLE' => 'Website không còn hoạt động hoặc không còn phù hợp với yêu cầu.',
        'WEBSITE_BULK_EDIT_CONFIGURATION_INVALID' => 'Cấu hình không hợp lệ. Kiểm tra cấu hình website và giao diện.',
        'WEBSITE_BULK_EDIT_PROCESSING_FAILED' => 'Chưa xử lý được domain. Có thể thử lại dòng lỗi.',
        'WEBSITE_BULK_EDIT_BUSY' => 'Website đang được xử lý. Tải lại trạng thái sau.',
    ];
    $pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['running'] ?? 0);
@endphp
<div class="pagehead">
    <div><div class="eyebrow">KẾT QUẢ SỬA DOMAIN</div><h1>Sửa domain hàng loạt</h1><p>{{ number_format($total) }} website · {{ $operationLabels[$status] ?? $status }}</p></div>
    <div class="actions"><a class="button secondary" href="/admin/sites/bulk-edit/{{ $id }}{{ $itemFilter !== '' ? '?status='.$itemFilter : '' }}">Tải lại trạng thái</a><a class="button" href="/admin/sites/bulk-edit">Tạo yêu cầu mới</a><a class="button secondary" href="/admin/sites">Website &amp; domain</a></div>
</div>
@if($pending > 0)
    <p class="note">Yêu cầu đang được xử lý theo nhóm website. Các dòng “Đã áp dụng” đã có hiệu lực; domain đang chờ chưa được xác nhận. Bấm Tải lại trạng thái để xem kết quả mới.</p>
@endif
<section class="card">
    <h2>Tiến độ từng trạng thái</h2>
    <div class="formrow">@foreach($itemLabels as $state => $label)<div><span class="muted">{{ $label }}</span><h2>{{ number_format($counts[$state] ?? 0) }}</h2></div>@endforeach</div>
    @if(($counts['failed'] ?? 0) > 0)
        <form method="post" action="/admin/sites/bulk-edit/{{ $id }}/retry">@csrf<button class="secondary">Thử lại các domain lỗi</button></form>
        <p class="muted">Chỉ thử lại dòng “Lỗi”. Dòng đã áp dụng và dòng xung đột không được sửa lại bởi nút này.</p>
    @endif
    @if(($counts['conflict'] ?? 0) > 0)<p class="note">Có cấu hình đã thay đổi trong lúc chờ. Kiểm tra các domain xung đột rồi tạo yêu cầu mới để dùng cấu hình hiện tại.</p>@endif
</section>
<section class="card">
    <h2>Thay đổi đã yêu cầu</h2>
    <dl>
        <dt>Giao diện</dt><dd>{{ $themeLabel }}</dd>
        @foreach(['advertising_regular' => 'Quảng cáo phim chính', 'advertising_fanmulu' => 'Quảng cáo fanmulu'] as $field => $label)
            <dt>{{ $label }}</dt><dd>{{ array_key_exists($field, $changes) ? ($changes[$field] ? 'Bật' : 'Tắt') : 'Giữ nguyên từng website' }}</dd>
        @endforeach
        <dt>Cache</dt><dd>{{ !empty($changes['clear_cache']) || array_key_exists('theme_id', $changes) ? 'Xóa cache toàn website sau khi áp dụng; dựng lại khi có truy cập.' : 'Giữ chính sách cache theo thay đổi đã chọn.' }}</dd>
    </dl>
    <p class="muted">Đổi giao diện giữ nguyên nội dung, URL và keyword đã gán. Mã JS quảng cáo vẫn dùng cấu hình chung của hệ thống.</p>
    <details><summary>Thông tin yêu cầu</summary><p class="code">{{ $id }}</p><p>Tạo lúc {{ $created_at }} · Cập nhật {{ $updated_at }}</p></details>
</section>
<section class="card">
    <div class="sectionhead"><h2>Kết quả từng domain</h2><span class="muted">{{ $items->count() }} / {{ number_format($items->total()) }} dòng khớp bộ lọc</span></div>
    <form method="get" action="/admin/sites/bulk-edit/{{ $id }}" class="toolbar">
        <label>Trạng thái<select name="status"><option value="">Tất cả</option>@foreach($itemLabels as $state => $label)<option value="{{ $state }}" @selected($itemFilter === $state)>{{ $label }}</option>@endforeach</select></label><button>Lọc kết quả</button>
    </form>
    <div class="tablewrap"><table>
        <thead><tr><th>Domain</th><th>Trạng thái</th><th>Kết quả / nguyên nhân</th><th>Thao tác</th></tr></thead>
        <tbody>
            @forelse($items as $item)
                <tr>
                    <td><strong class="code">{{ $item->domain ?: 'Website #'.$item->site_id }}</strong><div><small>#{{ $item->site_id }} · {{ $item->attempts }} lần xử lý</small></div></td>
                    <td><span class="badge {{ $item->status === 'completed' ? 'active' : (in_array($item->status, ['failed', 'conflict'], true) ? 'error' : 'pending') }}">{{ $itemLabels[$item->status] ?? $item->status }}</span></td>
                    <td>
                        @if($item->status === 'completed')<p>Đã áp dụng thay đổi cho website này.</p>
                        @elseif($item->error_code)<p>{{ $errorLabels[$item->error_code] ?? 'Chưa áp dụng được thay đổi. Kiểm tra website và mã lỗi bên dưới.' }}</p><small class="code">{{ $item->error_code }}</small>
                        @else<p class="muted">{{ $item->status === 'running' ? 'Hệ thống đang xử lý domain.' : 'Đang chờ worker xử lý.' }}</p>@endif
                    </td>
                    <td><div class="actions"><a href="/admin/sites/{{ $item->site_id }}/edit">Cấu hình website</a><a href="/admin/sites/{{ $item->site_id }}/cache">Cache</a>@if($item->status === 'completed' && $item->base_url)<a href="{{ $item->base_url }}" target="_blank" rel="noopener">Mở website ↗</a>@endif</div></td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Chưa có dòng nào khớp bộ lọc.</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <div class="pagination">{{ $items->links() }}</div>
</section>
@endsection
