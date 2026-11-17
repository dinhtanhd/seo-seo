@extends('admin.layout')
@section('title', 'Phim chính & fanmulu · '.$siteLabels->label($site))
@section('content')
@php($statusLabels = ['draft' => 'Nháp', 'live' => 'Đang phục vụ', 'archived' => 'Lưu trữ', 'gone' => 'Đã gỡ'])
<div class="pagehead">
    <div><div class="eyebrow">WEBSITE #{{ $site->id }}</div><h1>Phim chính &amp; fanmulu · {{ $siteLabels->label($site) }}</h1><p>Phim chính giữ tên và nội dung nguồn. Fanmulu dùng bản nội dung đã lưu của phim chính và một keyword cố định làm tên hiển thị.</p></div>
    <div class="actions"><a class="button secondary" href="/admin/fanmulu">← Tất cả domain</a><a class="button secondary" href="/admin/sites/{{ $site->id }}/edit">Cấu hình website</a><a href="/admin/pages?site_id={{ $site->id }}">Trang & tác phẩm</a></div>
</div>
@include('admin.partials.site-navigation', ['websiteTab' => 'fanmulu'])
<section class="card">
    <h2>Phim nguồn & keyword cho fanmulu</h2>
    <div class="formrow">
        <div><span class="muted">Phim chính đang phục vụ</span><h2>{{ number_format($readiness['regular_live']) }}</h2></div>
        <div><span class="muted">Phim chính chưa có HTML</span><h2>{{ number_format($readiness['regular_draft']) }}</h2></div>
        <div><span class="muted">{{ empty($settings['display_name_pool_ids']) ? 'Keyword chính đang hoạt động' : 'Tên thay thế đang hoạt động' }}</span><h2>{{ number_format($readiness['active_primary_keywords']) }}</h2></div>
        <div><span class="muted">URL fanmulu đã lưu</span><h2>{{ number_format($readiness['fanmulu_total']) }}</h2></div>
    </div>
    <p class="note">{{ $readiness['message'] }}</p>
    @if($readiness['sample_action'])
        <div class="actions"><form method="post" action="{{ $readiness['sample_action'] }}" target="_blank">@csrf<input type="hidden" name="request_id" value="{{ $readiness['sample_request_id'] }}"><button>Tạo &amp; mở fanmulu mẫu ↗</button></form><a href="/admin/pages/{{ $readiness['source_page_id'] }}?site_id={{ $site->id }}">Xem phim nguồn #{{ $readiness['source_page_id'] }}</a></div>
        <p class="muted">Bấm nút để tạo URL ngẫu nhiên theo cấu hình và mở trang. Phim nguồn và keyword được giữ cố định từ lần mở đầu tiên.</p>
    @elseif($readiness['sample_url'])
        <div class="actions"><a class="button" href="{{ $readiness['sample_url'] }}" target="_blank" rel="noopener">Tạo & mở fanmulu mẫu ↗</a><a href="/admin/pages/{{ $readiness['source_page_id'] }}?site_id={{ $site->id }}">Xem phim nguồn #{{ $readiness['source_page_id'] }}</a></div>
        <p class="code">{{ $readiness['sample_path'] }}</p>
        <p class="muted">Bấm mở URL này để tạo trang và gắn keyword lần đầu. Những lần truy cập lại URL đó dùng cùng tên và nội dung đã lưu. Trang quản trị không tự tạo fanmulu khi được tải.</p>
    @endif
    <p class="muted">{{ empty($settings['display_name_pool_ids']) ? 'Keyword được đếm trong các kho đang gắn và hoạt động.' : 'Tên thay thế được đếm riêng trong các kho fanmulu đã chọn, không tính keyword SEO.' }} Đây không phải dung lượng còn lại; giới hạn sử dụng được kiểm tra khi tạo URL. Phim chưa có HTML chỉ được dùng khi đủ điều kiện tạo theo yêu cầu. Người dùng và crawler nhận cùng nội dung public.</p>
</section>
<section class="card">
    <div class="sectionhead"><h2>Tạo trang khi có người mở URL mới</h2><span class="badge {{ $settings['enabled'] ? 'active' : 'paused' }}">{{ $settings['enabled'] ? 'Đã bật' : 'Đang tắt' }}</span></div>
    @unless($configuration['can_write'])<p class="note">Chỉ owner website hoặc quản trị viên hệ thống được đổi cấu hình fanmulu.</p>@endunless
    <form method="post" action="/admin/sites/{{ $site->id }}/fanmulu">
        @csrf
        <input type="hidden" name="row_version" value="{{ old('row_version', $site->row_version) }}">
        <fieldset class="site-editor-fieldset" @disabled(!$configuration['can_write'])>
            <input type="hidden" name="fanmulu[enabled]" value="0">
            <label class="checkboxline"><input type="checkbox" name="fanmulu[enabled]" value="1" @checked(old('fanmulu.enabled', $settings['enabled']))>Cho phép tạo fanmulu và chọn tên hiển thị khi URL được mở lần đầu</label>
            <p class="muted">Mỗi URL mới chỉ chọn tên hiển thị một lần. Những lần mở tiếp theo dùng lại tên và bản nội dung đã lưu. Website cần có phim chính đang phục vụ hoặc đủ điều kiện tạo khi mở link, cùng tên còn dung lượng.</p>
            @include('admin.partials.fanmulu-name-pools', ['namePoolSettings' => $settings])
            <div class="formrow">
                <div><label>Đường dẫn gốc fanmulu<input name="fanmulu[prefix]" value="{{ old('fanmulu.prefix', $settings['prefix']) }}" placeholder="/f" maxlength="120" required></label><small>Ví dụ /f: URL mới có dạng /f/ten-duong-dan. Chọn đường dẫn riêng với trang phim, danh mục, tìm kiếm và phát phim.</small></div>
                <div><label>Giới hạn số trang fanmulu<input type="number" name="fanmulu[max_pages]" value="{{ old('fanmulu.max_pages', $settings['max_pages']) }}" min="1" max="1000000" required></label><small>Chặn tạo thêm khi đạt giới hạn; không xóa các trang đã lưu.</small></div>
            </div>
            <label>Đường dẫn gốc bổ sung<textarea name="fanmulu[prefixes_text]" rows="4" maxlength="2500" placeholder="/topics&#10;/xem-phim">{{ old('fanmulu.prefixes_text', implode("\n", array_values(array_filter($settings['prefixes'] ?? [], fn ($prefix) => $prefix !== $settings['prefix'])))) }}</textarea><small>Mỗi dòng một đường dẫn, tổng tối đa 20 gồm đường dẫn chính. Không dùng các đường dẫn lồng nhau như /f và /f/x. Tất cả dùng chung giới hạn trang, đuôi URL và kho keyword. Xóa đường dẫn khỏi cấu hình không đổi các URL và keyword đã lưu.</small></label>
            <label>Đuôi URL cho trang fanmulu mới<select name="fanmulu[suffix]">@foreach($suffixChoices as $choice)<option value="{{ $choice->suffix }}" @selected((old('fanmulu.suffix', $settings['suffix'] ?? '') ?? '') === $choice->suffix)>{{ $choice->suffix === '' ? $choice->label : $choice->suffix.' · '.$choice->label }}</option>@endforeach</select></label>
            <p class="muted">Ví dụ: {{ rtrim($settings['prefix'], '/') }}/chu-de{{ $settings['suffix'] ?? '' }}. Đổi đuôi chỉ áp dụng cho URL fanmulu mới; URL và keyword của các trang đã lưu được giữ nguyên. @if(auth()->user()->is_global_admin)<a href="/admin/url-suffixes">Quản lý danh sách đuôi URL →</a>@endif</p>
            @include('admin.partials.fanmulu-url-rule', ['urlRuleSettings' => $settings])
            <label>Liên kết fanmulu trên mỗi trang chính<input type="number" name="fanmulu[links_per_page]" value="{{ old('fanmulu.links_per_page', $settings['links_per_page']) }}" min="0" max="20" required></label>
            <p class="muted">Nhập 0 để tắt chèn liên kết. Nhện tìm kiếm Trung Quốc nhận liên kết ngay trong danh sách phim; người dùng thường không thấy phần chèn này. Mỗi liên kết dùng tên thay thế phù hợp và dẫn tới một phim khác với trang gốc. URL và tên đã chốt được giữ khi tải lại.</p>
        </fieldset>
        @if($configuration['can_write'])<button>Lưu cấu hình fanmulu</button>@endif
    </form>
</section>
<section class="card">
    <div class="sectionhead"><h2>Các trang của website</h2><span class="muted">{{ $counts['regular'] ?? 0 }} chính quy · {{ $counts['fanmulu'] ?? 0 }} fanmulu</span></div>
    <form method="get" class="toolbar">
        <div><label>Tìm URL, keyword hoặc tên phim nguồn<input name="q" value="{{ $filters['q'] }}" maxlength="200" placeholder="/f/… hoặc tên phim"></label></div>
        <div class="narrow"><label>Nhóm trang<select name="kind">@foreach(['fanmulu' => 'Trang fanmulu', 'regular' => 'Trang chính quy', 'all' => 'Tất cả'] as $value => $label)<option value="{{ $value }}" @selected($filters['kind'] === $value)>{{ $label }}</option>@endforeach</select></label></div>
        <div class="narrow"><label>Trạng thái<select name="status"><option value="">Tất cả</option>@foreach($statusLabels as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></label></div>
        <div class="narrow"><label>Số dòng<select name="per_page">@foreach([25, 50, 100] as $perPage)<option value="{{ $perPage }}" @selected((int) $filters['per_page'] === $perPage)>{{ $perPage }} trang</option>@endforeach</select></label></div>
        <button>Lọc danh sách</button><a href="/admin/sites/{{ $site->id }}/fanmulu">Xóa bộ lọc</a>
    </form>
    <p class="muted">{{ $pages->total() }} trang phù hợp. Tên phim nguồn và keyword hiển thị bên dưới được lấy từ bản đã xuất bản, không lấy từ nháp đang sửa.</p>
    <div class="tablewrap"><table><thead><tr><th>Trang / URL</th><th>Keyword hiển thị</th><th>Phim chính quy nguồn</th><th>Trạng thái</th><th>Quản lý</th></tr></thead><tbody>
        @forelse($pages as $page)
            <tr>
                <td><span class="badge">{{ $page->page_kind === 'fanmulu' ? 'Fanmulu' : 'Chính quy' }}</span><br><a class="code" href="{{ rtrim($site->base_url ?? '', '/').$page->canonical_path }}" target="_blank" rel="noopener">{{ $page->canonical_path }}</a><br><small>Trang #{{ $page->id }}</small></td>
                <td>{{ $page->page_kind === 'fanmulu' ? ($page->display_title ?: 'Chưa có bản xuất bản') : 'Dùng tên phim nguồn' }}</td>
                <td>
                    <strong>{{ $page->source_title ?: 'Chưa có bản xuất bản' }}</strong>
                    @if($page->source_page_id)
                        <br><a href="/admin/pages/{{ $page->source_page_id }}?site_id={{ $site->id }}">Nguồn #{{ $page->source_page_id }}</a> · <small>Snapshot #{{ $page->source_publication_id }}</small>
                        @if($page->source_status !== 'live')
                            <br><small>Nguồn hiện tại: {{ $statusLabels[$page->source_status] ?? 'Không còn khả dụng' }}</small>
                        @endif
                    @endif
                </td>
                <td><span class="badge {{ $page->status }}">{{ $statusLabels[$page->status] ?? $page->status }}</span><br><small>Publication #{{ $page->current_publication_id ?: 'chưa có' }}</small></td>
                <td><a href="/admin/pages/{{ $page->id }}?site_id={{ $site->id }}">Lịch sử & chi tiết →</a></td>
            </tr>
        @empty<tr><td colspan="5" class="empty">Chưa có trang phù hợp. Trang fanmulu được tạo khi mở URL mới trong đường dẫn đã bật.</td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination">{{ $pages->links() }}</div>
</section>
@endsection
