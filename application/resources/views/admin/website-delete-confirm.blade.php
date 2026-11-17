@extends('admin.layout')
@section('title', 'Xác nhận xóa / khôi phục website')
@section('content')
@php
    $isRestore = $operation->action === 'restore';
    $canConfirm = $operation->status === 'preview' && ($operation->expires_at === null || \Carbon\Carbon::parse($operation->expires_at)->isFuture());
@endphp
<div class="pagehead">
    <div><div class="eyebrow">WEBSITE &amp; DOMAIN</div><h1>{{ $isRestore ? 'Xác nhận khôi phục website' : 'Xác nhận xóa website' }}</h1><p>{{ number_format($operation->total) }} website trong yêu cầu này. Kiểm tra các domain bên dưới trước khi xác nhận.</p></div>
    <div class="actions"><a class="button secondary" href="/admin/sites">Website &amp; domain</a><a class="button secondary" href="/admin/sites/deleted">Website đã xóa</a></div>
</div>
<section class="card">
    <h2>{{ $isRestore ? 'Nội dung sẽ khôi phục' : 'Nội dung sẽ xóa khỏi danh sách đang dùng' }}</h2>
    @if($isRestore)
        <p>Website được khôi phục ở trạng thái <strong>tạm dừng</strong>, cùng trang, cấu hình và lịch sử đã lưu. Domain chỉ được gắn lại khi chưa có website khác đăng ký.</p>
        <p class="muted">Hệ thống kiểm tra lại từng domain khi xử lý. Website có xung đột domain sẽ được giữ trong mục Website đã xóa để bạn kiểm tra.</p>
    @else
        <p>Website ngừng phục vụ, rời danh sách đang dùng và <strong>giải phóng toàn bộ domain chính cùng alias bên dưới</strong>. Những domain này có thể được đăng ký cho website khác.</p>
        <p class="muted">Trang, keyword đã gán, cấu hình và lịch sử website được giữ để khôi phục. Kho phim, keyword, nguồn, template, giao diện và bộ cấu hình dùng chung được giữ nguyên.</p>
    @endif
    @if($operation->expires_at)
        <p class="muted">Danh sách kiểm tra có hiệu lực đến {{ \Carbon\Carbon::parse($operation->expires_at)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}. Cấu hình vừa thay đổi sẽ được kiểm tra lại trước khi áp dụng.</p>
    @endif
    @if(!$canConfirm)
        <p class="errornotice" role="alert">Yêu cầu kiểm tra đã hết hạn hoặc đã được xác nhận. Quay lại danh sách để kiểm tra trạng thái và tạo yêu cầu mới nếu cần.</p>
    @endif
</section>
<section class="card">
    <div class="sectionhead"><h2>Website và domain đã chọn</h2><span class="muted">{{ $items->count() }} / {{ number_format($items->total()) }} website</span></div>
    <div class="tablewrap"><table>
        <thead><tr><th>Website</th><th>{{ $isRestore ? 'Domain sẽ gắn lại' : 'Domain sẽ giải phóng' }}</th><th>Trang được giữ</th><th>Bản xuất bản được giữ</th></tr></thead>
        <tbody>
            @forelse($items as $item)
                <tr>
                    <td><strong class="code">{{ $item->domain ?: 'Website #'.$item->site_id }}</strong><div><small>#{{ $item->site_id }} · {{ $item->name }}</small></div></td>
                    <td>
                        @forelse($item->domains as $domain)
                            <div class="code">{{ data_get($domain, 'host_ascii') }} <small>{{ data_get($domain, 'is_primary') ? 'Domain chính' : 'Alias' }}</small></div>
                        @empty
                            <span class="muted">Không có domain được ghi nhận.</span>
                        @endforelse
                    </td>
                    <td>{{ is_numeric($item->pages_count ?? null) ? number_format((int) $item->pages_count) : 'Chưa xác định' }}</td>
                    <td>{{ is_numeric($item->publication_count ?? null) ? number_format((int) $item->publication_count) : 'Chưa xác định' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Không có website trong yêu cầu này.</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <div class="pagination">{{ $items->links() }}</div>
    <p class="muted">Xác nhận áp dụng cho toàn bộ {{ number_format($operation->total) }} website trong yêu cầu, bao gồm các trang danh sách khác.</p>
</section>
<section class="card">
    <h2>{{ $isRestore ? 'Xác nhận khôi phục' : 'Xác nhận xóa' }}</h2>
    <form method="post" action="/admin/sites/delete-operations/{{ $operation->id }}/confirm">
        @csrf
        <input type="hidden" name="row_version" value="{{ $operation->row_version }}">
        <input type="hidden" name="preview_hash" value="{{ $operation->preview_hash }}">
        <label class="checkboxline"><input type="checkbox" name="confirm_delete" value="1" required @checked(old('confirm_delete') === '1')>{{ $isRestore ? 'Tôi xác nhận khôi phục '.$operation->total.' website ở trạng thái tạm dừng.' : 'Tôi xác nhận xóa '.$operation->total.' website và giải phóng các domain đã liệt kê.' }}</label>
        <div class="formfooter"><button type="submit" class="{{ $isRestore ? 'secondary' : 'danger' }}" @disabled(!$canConfirm)>{{ $isRestore ? 'Xác nhận khôi phục website' : 'Xác nhận xóa website' }}</button><a href="{{ $isRestore ? '/admin/sites/deleted' : '/admin/sites' }}">Hủy</a></div>
    </form>
</section>
@endsection
