@extends('admin.layout')
@section('title', 'Website đã xóa')
@section('content')
@php
    $rawFilter = $filters['q'] ?? request('q', '');
    $trashFilter = is_string($rawFilter) ? $rawFilter : '';
@endphp
<div class="pagehead">
    <div><div class="eyebrow">WEBSITE &amp; DOMAIN</div><h1>Website đã xóa</h1><p>Trang, cấu hình và lịch sử của các website này được giữ để khôi phục. Domain đã được giải phóng khi xóa.</p></div>
    <div class="actions"><a class="button secondary" href="/admin/sites">Website &amp; domain</a><a class="button secondary" href="/admin/sites/bulk-edit">Sửa hàng loạt</a></div>
</div>
<section class="card">
    <form method="get" action="/admin/sites/deleted" class="toolbar">
        <label>Tìm website hoặc domain cũ<input name="q" value="{{ $trashFilter }}" maxlength="200" placeholder="Domain, tên hiển thị hoặc ID website"></label><button>Lọc danh sách</button><a class="button secondary" href="/admin/sites/deleted">Xóa bộ lọc</a>
    </form>
    <p class="muted">{{ number_format($websites->total()) }} website đã xóa. Khôi phục cần xác nhận, giữ nguyên ID và lịch sử; website trở lại ở trạng thái tạm dừng.</p>
    <div class="tablewrap"><table>
        <thead><tr><th>Website</th><th>Domain cũ</th><th>Dữ liệu được giữ</th><th>Ngày xóa</th><th>Khôi phục</th></tr></thead>
        <tbody>
            @forelse($websites as $website)
                @php
                    $savedDomains = collect($website->domains ?? []);
                    $primaryHost = data_get($savedDomains->firstWhere('is_primary', true), 'host_ascii');
                    $conflicts = is_array($website->domain_conflicts ?? null) ? $website->domain_conflicts : [];
                @endphp
                <tr>
                    <td><strong class="code">{{ $primaryHost ?: 'Website #'.$website->id }}</strong><div><small>#{{ $website->id }} · {{ $website->name }}</small></div></td>
                    <td>
                        @forelse($savedDomains as $domain)
                            <div class="code">{{ data_get($domain, 'host_ascii') }} <small>{{ data_get($domain, 'is_primary') ? 'Domain chính' : 'Alias' }}</small></div>
                        @empty
                            <span class="muted">Không có domain được ghi nhận.</span>
                        @endforelse
                    </td>
                    <td><div>{{ is_numeric($website->pages_count ?? null) ? number_format((int) $website->pages_count).' trang' : 'Số trang chưa xác định' }}</div><div>{{ is_numeric($website->publication_count ?? null) ? number_format((int) $website->publication_count).' bản xuất bản' : 'Số bản xuất bản chưa xác định' }}</div></td>
                    <td>{{ $website->deleted_at ? \Carbon\Carbon::parse($website->deleted_at)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') : 'Chưa ghi nhận' }}</td>
                    <td>
                        @if($conflicts !== [])
                            <p class="errornotice">Domain đã được website khác đăng ký: <span class="code">{{ implode(', ', $conflicts) }}</span></p>
                            <p class="muted">Cần xử lý liên kết domain đang trùng trước khi khôi phục website này.</p>
                        @endif
                        <form method="post" action="/admin/sites/{{ $website->id }}/restore">@csrf<input type="hidden" name="row_version" value="{{ $website->restore_row_version ?? $website->row_version }}"><button type="submit" class="secondary" aria-label="Khôi phục website {{ $primaryHost ?: '#'.$website->id }}" @disabled($conflicts !== [])>Khôi phục</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">{{ $trashFilter !== '' ? 'Không có website đã xóa khớp bộ lọc.' : 'Chưa có website đã xóa.' }}</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <div class="pagination">{{ $websites->links() }}</div>
</section>
@endsection
