@extends('admin.layout')
@section('title', 'Crawl & nhập nội dung')
@section('content')
@php
    $statusLabels = ['active'=>'Đang hoạt động','paused'=>'Tạm dừng','archived'=>'Lưu trữ'];
    $adapterLabels = ['maccms'=>'API MacCMS / Shandian','shandian'=>'API Shandian','html'=>'Trang HTML · selector','json'=>'Nhập file JSON','csv'=>'Nhập file CSV','fixture'=>'Fixture · seed / test / demo'];
    $countLabels = ['received'=>'Đã nhận','parsed'=>'Hợp lệ','valid'=>'Hợp lệ','invalid'=>'Không hợp lệ','quarantined'=>'Cách ly','created'=>'Nội dung mới','updated'=>'Đã cập nhật','unchanged'=>'Không đổi','pages_created'=>'Trang mới','pages_updated'=>'Trang cập nhật','filtered'=>'Bỏ qua theo loại','skipped_binding'=>'Bỏ qua liên kết website','unknown_type'=>'Không có ID thể loại','published'=>'Đã xuất bản','errors'=>'Bản ghi lỗi','skipped'=>'Bản ghi bỏ qua','retry_attempts'=>'Số lần thử lại','categories_updated'=>'Trang đổi danh mục','fetched_pages'=>'Số trang API đã nhận','run_id'=>'Mã lượt','status'=>'Trạng thái','cursor'=>'Vị trí tiếp tục','error'=>'Lỗi','crawl_mode'=>'Chế độ nhận','page_limit'=>'Số trang tối đa'];
    $runStatusLabels = ['queued'=>'Đã xếp hàng','running'=>'Đang nhận dữ liệu','paused'=>'Đã tạm dừng','retry_wait'=>'Chờ thử lại','succeeded'=>'Đã xử lý xong phạm vi','partial'=>'Xong, có bản ghi cần kiểm tra','failed'=>'Lượt nhận gặp lỗi'];
    $stopReasonLabels = ['source_end'=>'API đã hết dữ liệu','page_limit'=>'Đã đủ số trang đã chọn'];
@endphp
<div class="pagehead"><div><div class="eyebrow">VẬN HÀNH NGUỒN #{{ $selectedSource->id }}</div><h1>Crawl & nhập nội dung</h1><p>{{ $selectedSource->name }} · chỉ thao tác với nguồn này.</p></div><div class="actions"><a class="button secondary" href="/admin/sources">← Danh sách nguồn</a>@if($canManageSources)<a class="button secondary" href="/admin/movies?source_id={{ $selectedSource->id }}">Phim đã nhận từ nguồn</a><a class="button secondary" href="/admin/sources/{{ $selectedSource->id }}/edit">Chỉnh sửa nguồn</a>@endif</div></div>
<div class="note">API MacCMS/Shandian lấy toàn bộ lần đầu từ trang cuối về trang 1, nhận phim cũ trước và phim mới sau. Cập nhật sau vẫn lấy 3–5 trang từ trang 1. Bật tự áp dụng trong cấu hình website để phim tự lên trang sau crawl.</div>
@foreach($sources as $source)
@php
    $sourceConfig = $canManageSources ? (json_decode($source->config_json,true) ?: []) : [];
    $sourceFormId = 'source:'.$source->id;
    $isOldSourceForm = old('source_form_id') === $sourceFormId;
    $sourceFormValues = App\Domain\Support\SourceConfigurationForm::values($sourceConfig,$source->kind);
    if ($isOldSourceForm && is_array(old('source_form'))) {
        $sourceFormValues = array_replace($sourceFormValues,array_intersect_key(old('source_form'),$sourceFormValues));
        foreach ($sourceFormValues as $key=>$value) {
            if (!in_array($key,['query_rows','field_rows','file_mapping_rows'],true) && !is_scalar($value) && $value!==null) {
                $sourceFormValues[$key]='';
            }
        }
    }
    $canConfigureSource = $canManageSources;
    $canOperateSource = $sourceCanOperate[$source->id] ?? false;
    $isRemoteSource = in_array($source->kind,['maccms','shandian','html'],true);
    $displayEndpoint = is_string($sourceConfig['endpoint'] ?? null) ? explode('?',$sourceConfig['endpoint'],2)[0] : '';
    $oldSourceName = $isOldSourceForm && is_string(old('name')) ? old('name') : $source->name;
    $oldSourceVersion = $isOldSourceForm && is_scalar(old('row_version')) ? old('row_version') : $source->row_version;
    $oldSourceStatus = $isOldSourceForm && is_string(old('status')) ? old('status') : $source->status;
    $sourceLatestPages = (int) ($source->latest_max_pages ?? 3);
    $oldSourceLatestPages = $isOldSourceForm && is_scalar(old('latest_max_pages')) ? old('latest_max_pages') : $sourceLatestPages;
    $isOldCrawlForm = old('crawl_form_id') === 'crawl:'.$source->id;
    $canInitialAll = in_array($source->kind,['maccms','shandian'],true);
    $crawlChoices = $canInitialAll ? ['initial_all','initial_pages','latest'] : ['initial_pages','latest'];
    $crawlChoice = $isOldCrawlForm && in_array(old('crawl_scope'), $crawlChoices, true) ? old('crawl_scope') : ($canInitialAll ? 'initial_all' : 'initial_pages');
    $crawlInitialPages = $isOldCrawlForm && is_scalar(old('initial_max_pages')) ? old('initial_max_pages') : 1000;
    $crawlLatestPages = $isOldCrawlForm && is_scalar(old('latest_max_pages')) ? old('latest_max_pages') : $sourceLatestPages;
@endphp
<section class="card">
    <h2>{{ $source->name }} <span class="badge {{ $source->status }}">{{ $statusLabels[$source->status] ?? $source->status }}</span></h2>
    <small>Connector #{{ $source->id }} · {{ $adapterLabels[$source->kind] ?? $source->kind }}</small>
    @if($canManageSources)<div class="formrow" style="margin-top:12px"><div><strong>{{ $isRemoteSource ? 'Endpoint' : 'Cách nhận dữ liệu' }}</strong><p class="code" style="overflow-wrap:anywhere">{{ $isRemoteSource ? ($displayEndpoint ?: 'Chưa cấu hình') : 'Nhập file JSON hoặc CSV' }}</p></div><div><strong>Lịch cập nhật mới nhất</strong>
        @if($isRemoteSource)<p>{{ App\Domain\Ingestion\SourceSchedule::label(App\Domain\Ingestion\SourceSchedule::forSource($source)) }} · {{ $sourceLatestPages }} trang từ trang 1</p><a href="/admin/sources/{{ $source->id }}/edit#schedule">Chỉnh lịch cập nhật</a>@if($source->status !== 'active')<p class="muted">Nguồn đang dừng; lịch chưa chạy.</p>@endif
        @else<p>Adapter nhập file không có lịch crawl.</p>@endif
    </div></div>@endif
    @if($source->kind==='fixture')<div class="note">Nguồn fixture dùng cho seed/test/demo; không phải nguồn production.</div>@endif
    <div style="margin:12px 0"><strong>Domain đang liên kết</strong><div class="actions" style="margin-top:8px">@forelse($sourceBindings[$source->id] ?? [] as $binding)<a class="button secondary" href="/admin/sites/{{ $binding->site_id }}/edit?tab=sources">{{ $siteLabels->label($binding->site_id) }} · {{ $statusLabels[$binding->status] ?? $binding->status }}</a>@empty<span class="muted">{{ $canManageSources ? 'Chưa gắn website. Chọn nguồn tại Website & domain → Nguồn nội dung.' : 'Chưa có liên kết website thuộc quyền của bạn.' }}</span>@endforelse</div></div>
    @if($canOperateSource)
    <div class="grid two">
        @if($isRemoteSource)
        <form action="/admin/sources/{{ $source->id }}/crawl" method="post" x-data="{ choice: @js($crawlChoice) }">@csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="crawl_form_id" value="crawl:{{ $source->id }}">
            <input type="hidden" name="crawl_mode" value="initial" :value="choice === 'latest' ? 'latest' : 'initial'">
            <input type="hidden" name="initial_limit_mode" value="{{ $crawlChoice==='initial_pages' ? 'pages' : 'all' }}" :value="choice === 'initial_pages' ? 'pages' : 'all'" :disabled="choice === 'latest'">
            <label>Phạm vi nhận dữ liệu<select name="crawl_scope" x-model="choice">@if($canInitialAll)<option value="initial_all" @selected($crawlChoice==='initial_all')>Lấy lần đầu · toàn bộ dữ liệu · trang cuối → 1</option>@endif<option value="initial_pages" @selected($crawlChoice==='initial_pages')>Lấy lần đầu · chọn số trang</option><option value="latest" @selected($crawlChoice==='latest')>Cập nhật mới nhất · 3–5 trang</option></select></label>
            <div x-show="choice === 'initial_pages'" x-cloak><label>Số trang đầu tiên cần lấy<input name="initial_max_pages" type="number" value="{{ $crawlInitialPages }}" min="1" max="1000000" :required="choice === 'initial_pages'" :disabled="choice !== 'initial_pages'"></label></div>
            <div x-show="choice === 'latest'" x-cloak><label>Số trang mới nhất<select name="latest_max_pages" :disabled="choice !== 'latest'">@foreach([3,4,5] as $pageCount)<option value="{{ $pageCount }}" @selected((string)$crawlLatestPages===(string)$pageCount)>{{ $pageCount }} trang từ trang 1</option>@endforeach</select></label></div>
            @if($canInitialAll)<p class="muted" x-show="choice === 'initial_all'">Xác định trang cuối rồi nhận ngược về trang 1. Trong mỗi trang, nhận từ cuối danh sách lên đầu để phim mới được nhập sau. Lượt kiểm tra số trang không được tính là một trang đã nhận. Bộ lọc thể loại của từng website vẫn được giữ.</p>@endif
            <p class="muted" x-show="choice === 'initial_pages'" x-cloak>Nhận từ trang 1 đến đủ số trang đã chọn hoặc khi API hết dữ liệu; giữ bộ lọc thể loại của từng website. Lượt này không bị giới hạn 3 trang tổng cộng.</p>
            @unless($canInitialAll)<p class="muted">Nguồn HTML cần chọn giới hạn số trang. Chế độ lấy toàn bộ chỉ áp dụng cho API MacCMS/Shandian.</p>@endunless
            <p class="muted" x-show="choice === 'latest'" x-cloak>Mở lượt mới từ trang 1 để nhận phim mới/cập nhật gần đây. Không tiếp tục cursor cũ.</p>
            <small>Mỗi job nhận tối đa 3 trang rồi tự xếp phần tiếp theo. Có thể tạm dừng và tiếp tục tại lịch sử bên dưới. Lịch tự động chỉ chạy cập nhật mới nhất; lấy toàn bộ cần bạn chủ động bắt đầu.</small>
            <div class="formfooter"><button @disabled($source->status!=='active')>Bắt đầu nhận dữ liệu</button></div>
        </form>
        @else<div class="note">Adapter này chỉ nhập file; không crawl từ endpoint.</div>@endif
        <form action="/admin/sources/{{ $source->id }}/import" method="post" enctype="multipart/form-data">@csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <div class="formrow"><div><label>File metadata nội dung<input type="file" name="file" required accept=".json,.csv"></label></div><div><label>Định dạng<select name="format"><option value="json">JSON</option><option value="csv">CSV</option></select></label></div></div>
            <button class="secondary" @disabled($source->status!=='active')>Nhập file nội dung</button>
        </form>
    </div>
    @else<p class="muted">Bạn có quyền xem nguồn này. Nhập/crawl cần quyền vận hành trên mọi website đang gắn nguồn.</p>@endif
</section>
@endforeach
@include('admin.sources.run-history')
@endsection
