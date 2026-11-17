@extends('admin.layout')
@section('title', 'Website & domain')
@section('content')
@php
    $statusLabels = ['active' => 'Hoạt động', 'paused' => 'Tạm dừng', 'archived' => 'Lưu trữ', 'pending' => 'Chờ xác nhận'];
    $typeLabels = ['movie' => 'Phim', 'novel' => 'Tiểu thuyết'];
@endphp
<div class="pagehead">
    <div>
        <div class="eyebrow">QUẢN LÝ NHIỀU WEBSITE</div>
        <h1>Website & domain</h1>
        <p>Quản lý theo tên miền chính. Mở một domain để cấu hình, gắn nguồn và kho keyword hoặc quản lý nội dung.</p>
    </div>
    @if(auth()->user()->is_global_admin)
        <div class="actions">
            <a class="button" href="/admin/sites/create">Tạo website</a>
            <a class="button" href="/admin/sites/bulk-create">Thêm hàng loạt</a>
            <a class="button secondary" href="/admin/sites/bulk-edit">Sửa hàng loạt</a>
            @if($websiteDeletionAvailable ?? false)<a class="button secondary" href="/admin/sites/deleted">Website đã xóa</a>@endif
            <a class="button secondary" href="/admin/site-presets">Bộ cấu hình website</a>
            <a class="button secondary" href="/admin/site-groups">Nhóm website</a>
            <a class="button secondary" href="/admin/url-suffixes">Danh mục đuôi URL</a>
        </div>
    @else
        <div class="actions"><a class="button secondary" href="/admin/sites/bulk-edit">Sửa hàng loạt</a></div>
    @endif
</div>
<section class="card">
    <form method="get" action="/admin/sites" class="toolbar site-directory-filters">
        <div><label>Tìm domain<input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Domain chính, alias, URL chuẩn hoặc ID"></label></div>
        <div class="narrow"><label>Trạng thái<select name="status"><option value="">Tất cả trạng thái</option>@foreach(['active', 'paused', 'archived'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $statusLabels[$status] }}</option>@endforeach</select></label></div>
        <div class="narrow"><label>Loại nội dung<select name="content_type"><option value="">Tất cả nội dung</option>@foreach($typeLabels as $type => $label)<option value="{{ $type }}" @selected(($filters['content_type'] ?? '') === $type)>{{ $label }}</option>@endforeach</select></label></div>
        <div class="narrow"><label>Số dòng mỗi trang<select name="per_page">@foreach([25, 50, 100] as $size)<option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }} website</option>@endforeach</select></label></div>
        <button>Lọc danh sách</button>
        <a class="button secondary" href="/admin/sites">Xóa bộ lọc</a>
    </form>
    <div class="sectionhead site-directory-count"><h2>{{ number_format($websites->total()) }} website</h2><span class="muted">{{ $websites->count() }} website trên trang này</span></div>
    <div class="tablewrap">
        <table class="site-directory-table">
            <thead><tr><th>Tên miền chính</th><th>URL chuẩn & domain bổ sung</th><th>Giao diện & kho phim</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
            <tbody>
                @forelse($websites as $website)
                    @php
                        $websiteDomains = $domains[$website->id] ?? collect();
                        $primaryDomain = $websiteDomains->where('is_primary', true)->sortBy(fn($domain) => ($domain->status === 'active' ? '0' : '1').'|'.$domain->host_ascii.'|'.$domain->id)->first();
                        $aliases = $websiteDomains->where('is_primary', false);
                        $mayManage = (bool) ($canManage[$website->id] ?? false);
                        $resourceSummary = $websiteSummaries[$website->id] ?? [];
                        $servingTheme = $resourceSummary['theme'] ?? ['origin' => 'unknown'];
                        $websiteSources = $resourceSummary['sources'] ?? [];
                    @endphp
                    <tr>
                        <td data-label="Tên miền chính"><a href="/admin/sites/{{ $website->id }}/edit"><strong class="code">{{ $siteLabels->label($website) }}</strong></a><small class="site-directory-meta">#{{ $website->id }} · {{ $website->locale }}</small>@if($primaryDomain)<span class="badge {{ $primaryDomain->status }}">{{ $statusLabels[$primaryDomain->status] ?? $primaryDomain->status }}</span>@else<small class="muted">Chưa đăng ký domain chính</small>@endif</td>
                        <td data-label="URL chuẩn & domain bổ sung">
                            @if($primaryDomain)
                                <small>URL chuẩn</small>
                            @else
                                <p class="muted">Chưa có domain chính</p>
                            @endif
                            <a class="code site-canonical" href="{{ $website->base_url }}" target="_blank" rel="noopener">{{ $website->base_url }}</a>
                            @foreach($aliases as $alias)
                                <div class="site-domain-line"><span class="code">{{ $alias->host_ascii }}</span><span class="badge {{ $alias->status }}">{{ $statusLabels[$alias->status] ?? $alias->status }}</span><small>Alias</small></div>
                            @endforeach
                        </td>
                        <td data-label="Giao diện & kho phim" style="min-width:180px">
                            <small class="muted">{{ $typeLabels[$website->content_type] ?? $website->content_type }}</small>
                            <div class="site-directory-meta">
                                <small>Giao diện đang dùng</small><br>
                                @if(in_array($servingTheme['origin'], ['appearance', 'publication'], true))
                                    <a href="/admin/sites/{{ $website->id }}/presentation">{{ $servingTheme['name'] ?? 'Chưa xác định tên' }}</a>
                                    @if($servingTheme['version_no'] ?? null)<small>· v{{ $servingTheme['version_no'] }}</small>@endif
                                @elseif($servingTheme['origin'] === 'pending')
                                    <span class="muted">Chưa áp dụng</span>
                                    @if($servingTheme['name'] ?? null)<small class="site-directory-meta">Đã chọn: {{ $servingTheme['name'] }}</small>@endif
                                @elseif($servingTheme['origin'] === 'builtin')
                                    <span>Mặc định hệ thống</span>
                                @else
                                    <span class="muted">Chưa xác định</span>
                                @endif
                            </div>
                            <div class="site-directory-meta">
                                <small>{{ $website->content_type === 'movie' ? 'Kho phim / nguồn' : 'Nguồn nội dung' }}</small>
                                @forelse($websiteSources as $source)
                                    <div><a href="/admin/sites/{{ $website->id }}/edit?tab=sources">{{ $source->name }}</a>
                                        <small>#{{ $source->id }}</small>
                                        @if($source->status !== 'active')<span class="badge paused">{{ $statusLabels[$source->status] ?? $source->status }}</span>@endif
                                        @if($source->binding_status !== 'active')<span class="badge paused">Liên kết: {{ $statusLabels[$source->binding_status] ?? $source->binding_status }}</span>@endif
                                    </div>
                                @empty
                                    <div class="muted">{{ $website->content_type === 'movie' ? 'Chưa gắn nguồn phim' : 'Chưa gắn nguồn nội dung' }}</div>
                                @endforelse
                            </div>
                        </td>
                        <td data-label="Trạng thái"><span class="badge {{ $website->status }}">{{ $statusLabels[$website->status] ?? $website->status }}</span></td>
                        <td data-label="Thao tác"><div class="actions site-directory-actions"><a class="button secondary" href="/admin/sites/{{ $website->id }}/edit">{{ $mayManage ? 'Chỉnh sửa' : 'Xem cấu hình' }}</a><a href="{{ $website->base_url }}" target="_blank" rel="noopener">Mở website ↗</a><a href="/admin/sites/{{ $website->id }}/cache">Cache</a><a href="/admin/pages?site_id={{ $website->id }}">Trang & tác phẩm</a>@if($website->content_type === 'movie')<a href="/admin/sites/{{ $website->id }}/presentation?site_id={{ $website->id }}">Giao diện & đường dẫn</a><a href="/admin/sites/{{ $website->id }}/fanmulu">Phim chính & fanmulu</a>@endif @if(auth()->user()->is_global_admin && ($websiteDeletionAvailable ?? false))<form method="post" action="/admin/sites/{{ $website->id }}/delete" class="inlineform">@csrf<input type="hidden" name="row_version" value="{{ $website->row_version }}"><button type="submit" class="danger" aria-label="Xóa website {{ $siteLabels->label($website) }}">Xóa</button></form>@endif</div></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">{{ !empty($filters['q']) || !empty($filters['status']) || !empty($filters['content_type']) ? 'Không có website khớp bộ lọc.' : 'Chưa có website được phép truy cập.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination">{{ $websites->links() }}</div>
</section>
@endsection
