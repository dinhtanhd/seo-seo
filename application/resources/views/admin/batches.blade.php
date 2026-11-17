@extends('admin.layout')
@section('title','Lịch sử áp dụng')
@section('content')
<div class="pagehead"><div><div class="eyebrow">PUBLICATION / BATCH</div><h1>Lịch sử áp dụng</h1><p>Mỗi batch thuộc một website. Danh sách và thao tác xuất bản dùng đúng website ghi trên từng dòng.</p></div><div class="actions"><a class="button" href="/admin/bulk{{ $scope }}">Phát hành nhiều trang →</a><a class="button secondary" href="/admin/sites">Website & cache →</a></div></div>
<section class="card">
    <form method="get" class="actions" style="margin-bottom:20px">
        <div><label for="batch-site-filter">Lọc theo domain</label><select name="site_id" id="batch-site-filter"><option value="">Tất cả domain được phép</option>@foreach($sites as $availableSite)<option value="{{ $availableSite->id }}" @selected($site?->id === $availableSite->id)>{{ $siteLabels->label($availableSite) }} · #{{ $availableSite->id }}</option>@endforeach</select></div>
        <button>Lọc danh sách</button>
    </form>
    <table><thead><tr><th>Batch</th><th>Domain</th><th>Thao tác</th><th>Trạng thái</th><th>Kết quả</th><th>Ngày tạo</th></tr></thead><tbody>
    @forelse($batches as $batch)
        <tr><td><a href="/admin/batches/{{ $batch->id }}?site_id={{ $batch->site_id }}"><strong>#{{ $batch->id }}</strong></a><br><small class="code">{{ $batch->public_id }}</small></td><td>{{ $siteLabels->label($batch->site_id) }}<br><small>Website #{{ $batch->site_id }}</small></td><td>{{ $batch->action }}</td><td><span class="badge {{ $batch->status }}">{{ $batch->status }}</span></td><td>@forelse(App\Domain\Support\Json::decode($batch->counts) as $state => $count)<div>{{ $statusLabels[$state] ?? $state }}: <strong>{{ $count }}</strong></div>@empty<span>Chưa có kết quả</span>@endforelse</td><td>{{ $batch->created_at }}</td></tr>
    @empty
        <tr><td colspan="6" class="empty">Chưa có lần áp dụng trong bộ lọc này. Mở domain hoặc chọn các trang trong màn Sửa hàng loạt để áp dụng.</td></tr>
    @endforelse
    </tbody></table><div class="pagination">{{ $batches->links() }}</div>
</section>
@endsection
