@extends('admin.layout')
@section('title', 'Bộ cấu hình website')
@section('content')
<div class="pagehead">
    <div><div class="eyebrow">DÙNG CHUNG CHO NHIỀU DOMAIN</div><h1>Bộ cấu hình website</h1><p>Mỗi bộ gồm giao diện, Template SEO, URL, nguồn ứng viên, kho keyword và cách phục vụ. Chọn nhiều bộ khi thêm domain để hệ thống phân phối theo lựa chọn ngẫu nhiên hoặc lần lượt.</p></div>
    <div class="actions"><a class="button" href="/admin/site-presets/create">Tạo bộ cấu hình</a><a class="button secondary" href="/admin/deletions?kind=preset">Xóa &amp; khôi phục hàng loạt</a><a class="button secondary" href="/admin/sites/bulk-create">Thêm website hàng loạt</a></div>
</div>
<section class="card">
    <div class="sectionhead"><h2>{{ $presets->count() }} bộ cấu hình</h2><form method="post" action="/admin/site-presets/defaults">@csrf<button class="secondary">Chuẩn bị 10 bộ mẫu</button></form></div>
    <p class="muted">Bộ mẫu được tạo từ tài nguyên đang có. Sửa bộ chỉ áp dụng cho các website được tạo sau; không tự đổi website đang hoạt động.</p>
    <div class="tablewrap"><table><thead><tr><th>Bộ cấu hình</th><th>Thiết lập</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
    @forelse($presets as $preset)
        @php($summary = (array) ($preset->summary ?? []))
        <tr>
            <td><a href="/admin/site-presets/{{ $preset->id }}/edit"><strong>{{ $preset->name }}</strong></a><div><small>#{{ $preset->id }} · {{ $preset->description ?? '' }}</small></div></td>
            <td><div>{{ ($summary['delivery_mode'] ?? '') === 'on_demand' ? 'Tạo phim khi mở link lần đầu' : 'Tạo sẵn trang' }}</div><div><small>{{ $summary['locale'] ?? 'zh-CN' }} @if(isset($summary['theme_name'])) · {{ $summary['theme_name'] }} @elseif(isset($summary['theme_id'])) · Giao diện #{{ $summary['theme_id'] }} @endif</small></div>@if(isset($summary['pool_count']) || isset($summary['source_count']))<div><small>{{ $summary['pool_count'] ?? '—' }} kho keyword · {{ $summary['source_count'] ?? '—' }} nguồn ứng viên</small></div>@endif @if(isset($summary['routes']['detail']))<div class="code">{{ $summary['routes']['detail'] }}/{phim}{{ $summary['suffixes']['detail'] ?? '' }}</div><div><small>{{ ($summary['movie_key'] ?? '') === 'source_id' ? 'ID nguồn/phim' : 'Pinyin + ID nguồn/phim' }} · {{ ($summary['category_key'] ?? '') === 'id' ? 'Danh mục ID' : 'Danh mục pinyin' }}</small></div>@endif</td>
            <td><span class="badge {{ $preset->status }}">{{ $preset->status === 'active' ? 'Đang dùng' : 'Lưu trữ' }}</span></td>
            <td><div class="actions"><a class="button secondary" href="/admin/site-presets/{{ $preset->id }}/edit">Chỉnh sửa</a>@if(($preset->deleted_at ?? null) === null)<a class="button danger" href="/admin/deletions/preset/{{ $preset->id }}">Xóa</a>@endif @if($preset->status === 'active')<a href="/admin/sites/bulk-create?preset_id={{ $preset->id }}">Dùng bộ này →</a><form method="post" action="/admin/site-presets/{{ $preset->id }}/archive" onsubmit="return confirm('Lưu trữ bộ cấu hình này? Website đã tạo vẫn giữ nguyên.')">@csrf<input type="hidden" name="row_version" value="{{ $preset->row_version }}"><button class="danger">Lưu trữ</button></form>@else<form method="post" action="/admin/site-presets/{{ $preset->id }}/restore">@csrf<input type="hidden" name="row_version" value="{{ $preset->row_version }}"><button class="secondary">Khôi phục</button></form>@endif</div></td>
        </tr>
    @empty
        <tr><td colspan="4" class="empty">Chưa có bộ cấu hình. Bấm Chuẩn bị 10 bộ mẫu hoặc tạo bộ riêng.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endsection
