@extends('admin.layout')
@section('title', 'Phim chính & fanmulu')
@section('content')
@php
    $statusLabels = ['active' => 'Hoạt động', 'paused' => 'Tạm dừng', 'archived' => 'Lưu trữ'];
@endphp
<div class="pagehead">
    <div><div class="eyebrow">PHIM & KEYWORD THEO DOMAIN</div><h1>Phim chính &amp; fanmulu</h1><p>Phim chính giữ tên và nội dung nguồn. Fanmulu dùng nội dung phim chính và một keyword cố định làm tên hiển thị. Mở một domain để cấu hình và quản lý các URL đã tạo.</p></div>
    <div class="actions"><a class="button secondary" href="/admin/sites">Website & domain</a><a class="button secondary" href="/admin/site-presets">Bộ cấu hình website</a></div>
</div>
<section class="card">
    <form method="get" action="/admin/fanmulu" class="toolbar" aria-label="Lọc fanmulu theo domain">
        <label>Tìm domain<input name="q" value="{{ $filters['q'] }}" maxlength="253" placeholder="Domain chính hoặc alias"></label>
        <label>Trạng thái domain<select name="status">@foreach(['active' => 'Đang hoạt động', 'paused' => 'Tạm dừng', 'archived' => 'Lưu trữ', 'all' => 'Tất cả trạng thái'] as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Fanmulu<select name="fanmulu">@foreach(['all' => 'Tất cả', 'on' => 'Đã bật', 'off' => 'Đang tắt'] as $value => $label)<option value="{{ $value }}" @selected($filters['fanmulu'] === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Số dòng<select name="per_page">@foreach([25, 50, 100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} domain</option>@endforeach</select></label>
        <button>Lọc danh sách</button><a class="button secondary" href="/admin/fanmulu">Xóa bộ lọc</a>
    </form>
    <div class="sectionhead"><h2>{{ number_format($websites->total()) }} domain phim</h2><span class="muted">{{ $websites->count() }} domain trên trang này</span></div>
    <div class="tablewrap"><table>
        <thead><tr><th>Domain</th><th>Fanmulu</th><th>Phim chính</th><th>Keyword & trang fanmulu</th><th>Thao tác</th></tr></thead>
        <tbody>
        @forelse($websites as $website)
            @php
                $state = $readiness->get($website->id);
                $settings = $state['settings'];
            @endphp
            <tr>
                <td><a href="/admin/sites/{{ $website->id }}/fanmulu"><strong class="code">{{ $siteLabels->label($website) }}</strong></a><br><small>#{{ $website->id }} · {{ $statusLabels[$website->status] ?? $website->status }}</small></td>
                <td><span class="badge {{ $settings['enabled'] ? 'active' : 'paused' }}">{{ $settings['enabled'] ? 'Đã bật' : 'Đang tắt' }}</span><br><small class="code">{{ $settings['prefix'] }}/…{{ $settings['suffix'] ?? '' }}</small><br><small>{{ $settings['links_per_page'] }} liên kết / trang chính</small></td>
                <td><strong>{{ number_format($state['regular_live']) }}</strong> đang phục vụ<br><small>{{ number_format($state['regular_draft']) }} chưa có HTML</small></td>
                <td>{{ number_format($state['active_primary_keywords']) }} {{ empty($settings['display_name_pool_ids']) ? 'keyword chính đang hoạt động' : 'tên thay thế đang hoạt động' }}<br><small>{{ number_format($state['fanmulu_total']) }} URL fanmulu đã lưu · {{ number_format($state['fanmulu_live']) }} đang phục vụ</small></td>
                <td>
                    <div class="actions"><a class="button secondary" href="/admin/sites/{{ $website->id }}/fanmulu">Cấu hình & danh sách</a>
                    @if($state['sample_action'])<form method="post" action="{{ $state['sample_action'] }}" target="_blank">@csrf<input type="hidden" name="request_id" value="{{ $state['sample_request_id'] }}"><button class="secondary">Tạo &amp; mở fanmulu mẫu ↗</button></form>
                    @elseif($state['sample_url'])<a class="button secondary" href="{{ $state['sample_url'] }}" target="_blank" rel="noopener">Tạo & mở fanmulu mẫu ↗</a>@endif
                    </div><p class="muted">{{ $state['message'] }}</p>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">Không có domain phim phù hợp trong quyền truy cập. Thử đổi bộ lọc hoặc thêm website và gắn nguồn phim, kho keyword.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="pagination">{{ $websites->links() }}</div>
    <p class="muted">Màn quản lý chỉ đọc dữ liệu đã lưu. Bấm Tạo & mở fanmulu mẫu mới tạo URL công khai và gắn keyword một lần. Số keyword đang hoạt động không phải dung lượng còn lại; hệ thống kiểm tra giới hạn khi URL được mở. Người dùng và crawler nhận cùng nội dung public.</p>
</section>
@endsection
