@extends('admin.layout')
@section('title','Trang & tác phẩm')
@section('content')
@if(auth()->user()->is_global_admin)<p class="note">Sửa dữ liệu phim, diễn viên và tập phát tại <a href="/admin/movies">Kho phim dùng chung</a>. Danh sách dưới đây quản lý URL và thiết lập riêng theo domain.</p>@endif
<div class="pagehead"><div><div class="eyebrow">NỘI DUNG / WEBSITE</div><h1>Trang & tác phẩm</h1><p>{{ $site ? $siteLabels->label($site) : 'Tất cả domain chưa lưu trữ trong quyền truy cập' }} · Chỉnh sửa trang, lưu rồi mở website. Keyword đã gán và URL được giữ ổn định.</p></div><div class="actions">@if($site)<a class="button" href="{{ $site->base_url }}" target="_blank" rel="noopener">Mở website ↗</a><a class="button secondary" href="/admin/sites/{{ $site->id }}/cache">Quản lý cache</a>@if($site->content_type === 'movie')<a class="button secondary" href="/admin/sites/{{ $site->id }}/fanmulu">Phim chính & fanmulu</a>@endif @endif<a class="button secondary" href="/admin/bulk{{ $scope }}">Chỉnh sửa hàng loạt →</a></div></div>
<div class="card">
    <form method="get" action="/admin/pages" class="toolbar" aria-label="Lọc trang theo domain">
        <label>Domain<select name="site_id"><option value="">Tất cả domain chưa lưu trữ</option>@foreach($siteLabels->sortSites($sites) as $availableSite)<option value="{{ $availableSite->id }}" @selected($site?->id === $availableSite->id)>{{ $siteLabels->label($availableSite) }}{{ $availableSite->status === 'archived' ? ' · đã lưu trữ' : '' }}</option>@endforeach</select></label>
        <div><label>Tìm URL<input name="q" value="{{ request('q') }}" placeholder="/movie/…"></label></div>
        <div class="narrow"><label>Nhóm trang<select name="kind">@foreach(['all' => 'Tất cả', 'regular' => 'Trang chính / nội dung nguồn', 'fanmulu' => 'Trang fanmulu theo keyword'] as $value => $label)<option value="{{ $value }}" @selected($pageKind === $value)>{{ $label }}</option>@endforeach</select></label></div>
        <div class="narrow"><label>Trạng thái<select name="status"><option value="">Tất cả</option>@foreach(['draft','live','archived','gone'] as $status)<option @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></label></div><button>Lọc trang</button>
    </form>
    <p class="note">Mở chi tiết để chỉnh sửa và bấm <strong>Lưu & áp dụng</strong>. Website dùng chế độ tạo trang khi truy cập sẽ tự tạo HTML ở lần mở URL đầu tiên, sau đó dùng cache.</p>
    @php($pageSites = $sites->keyBy('id'))
    <div class="tablewrap"><table><thead><tr><th>Domain</th><th>URL chuẩn</th><th>Loại / nội dung</th><th>Trạng thái</th><th>Khóa</th><th>Thao tác</th></tr></thead><tbody>
        @forelse($pages as $page)
            @php($ready = in_array($page->id, $onDemandReadyPageIds, true))
            <tr><td>{{ $siteLabels->label($page->site_id) }}</td>
                <td><a href="/admin/pages/{{ $page->id }}?site_id={{ $page->site_id }}"><strong>{{ $page->display_title ?? ($page->page_kind === 'fanmulu' ? 'Fanmulu · chưa có tên đã lưu' : $page->canonical_path) }}</strong></a><br><small class="code">{{ $page->canonical_path }}</small><br><small>Trang #{{ $page->id }}</small>
                    @if($page->page_kind === 'fanmulu')<div><small>Phim nguồn: {{ $page->source_original_title ?: 'Chưa xác định' }}</small><br><a href="/admin/pages/{{ $page->source_page_id }}?site_id={{ $page->site_id }}"><small>Nguồn #{{ $page->source_page_id }} · snapshot #{{ $page->source_publication_id }}</small></a></div>@endif
                </td>
                <td><span class="badge">{{ $page->page_kind === 'fanmulu' ? 'Fanmulu · tên theo keyword' : ($page->page_type === 'movie' ? 'Phim chính · tên từ nguồn' : 'Nội dung nguồn') }}</span><br>{{ $page->page_type }}<br><small>Content #{{ $page->content_item_id ?? '—' }}</small></td>
                <td><span class="badge {{ $page->status }}">{{ $ready ? 'Sẵn sàng mở · chưa có cache' : ($page->status === 'live' ? 'Đang phục vụ' : ($page->status === 'draft' ? 'Chưa phục vụ' : ($page->status === 'archived' ? 'Đã lưu trữ' : 'Đã gỡ'))) }}</span><br><small>Bản HTML #{{ $page->current_publication_id ?? 'chưa có' }} · cấu hình v{{ $page->row_version }}</small></td>
                <td>{{ $page->assignment_locked ? 'Keyword đã khóa' : 'Keyword mở' }}<br><small>{{ $page->publish_locked ? 'Đã khóa cập nhật' : 'Cho phép cập nhật' }}</small></td>
                <td><a href="/admin/pages/{{ $page->id }}?site_id={{ $page->site_id }}">Chỉnh sửa →</a>@if($pageSites->has($page->site_id) && $pageSites->get($page->site_id)->status === 'active' && (($page->status === 'live' && $page->current_publication_id) || $ready))<br><a href="{{ rtrim($pageSites->get($page->site_id)->base_url,'/').$page->canonical_path }}" target="_blank" rel="noopener">Mở trang ↗</a>@endif</td>
            </tr>
        @empty<tr><td colspan="6" class="empty">Chưa có trang phù hợp. Gắn nguồn phim và crawl dữ liệu để thêm trang.</td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination">{{ $pages->links() }}</div>
</div>
@endsection
