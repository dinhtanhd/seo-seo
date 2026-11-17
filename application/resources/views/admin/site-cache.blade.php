@extends('admin.layout')
@section('title', 'Cache & hoạt động · '.$siteLabels->label($site))
@section('content')
@php
    $presentationLabels = ['queued'=>'Chờ cập nhật','running'=>'Đang cập nhật','processing'=>'Đang cập nhật','applying'=>'Đang cập nhật','retrying'=>'Đang thử lại','completed'=>'Đã cập nhật','partial'=>'Có trang cần kiểm tra','failed'=>'Cần xử lý lỗi','stale'=>'Cấu hình đã thay đổi','superseded'=>'Đã có cấu hình mới'];
    $batchLabels = ['draft'=>'Nháp','previewing'=>'Đang kiểm tra','ready'=>'Đã kiểm tra','queued'=>'Chờ xử lý','running'=>'Đang áp dụng','completed'=>'Đã áp dụng','partial'=>'Áp dụng một phần','failed'=>'Thất bại','stale'=>'Hết hiệu lực','cancelling'=>'Đang dừng','cancelled'=>'Đã hủy'];
    $formatBytes = static function (?int $bytes): string {
        if ($bytes === null) {
            return 'Chưa xác định';
        }
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GiB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }
        return number_format($bytes).' byte';
    };
    $cacheReady = $stats['enabled'] && $site->content_type === 'movie' && $site->status === 'active';
    $activePages = ($pageCounts['live'] ?? 0) + ($pageCounts['draft'] ?? 0);
@endphp
<div class="pagehead">
    <div><div class="eyebrow">WEBSITE #{{ $site->id }}</div><h1>Cache & hoạt động · {{ $siteLabels->label($site) }}</h1><p>Kiểm tra trang đang phục vụ, yêu cầu áp dụng và cache HTML của website.</p></div>
    <div class="actions">
        @if($openUrl)<a class="button" href="{{ $openUrl }}" target="_blank" rel="noopener">Mở website ↗</a>@endif
        <a class="button secondary" href="/admin/sites/{{ $site->id }}/presentation">Giao diện & đường dẫn</a>
        <a class="button secondary" href="/admin/sites">Website & domain</a>
    </div>
</div>
@include('admin.partials.site-navigation', ['websiteTab' => 'cache'])
@if($appearance)
    <section class="card" id="site-appearance">
        <div class="sectionhead"><h2>Giao diện toàn website</h2><span class="badge active">Đang áp dụng</span></div>
        <p>{{ $appearance['theme']['name'] ?? 'Giao diện mặc định' }}@if(isset($appearance['theme']['version_no'])) · phiên bản {{ $appearance['theme']['version_no'] }}@endif</p>
        <p>Đổi giao diện đã xóa cache toàn website. Phim chính và fanmulu được render bằng giao diện đang chọn khi có người mở link, rồi lưu cache. Nội dung và keyword giữ nguyên.</p>
        <div class="actions">@if($openUrl)<a class="button secondary" href="{{ $openUrl }}" target="_blank" rel="noopener">Mở website ↗</a>@endif<a href="/admin/sites/{{ $site->id }}/presentation">Đổi giao diện</a></div>
    </section>
@elseif($presentationEvent)
    <section class="card" id="site-presentation-progress">
        <div class="sectionhead"><h2>Đổi giao diện website</h2><span class="badge">{{ $presentationLabels[$presentationPayload['status'] ?? ''] ?? ($presentationPayload['status'] ?? 'Chưa xác định') }}</span></div>
        <p>Trang chủ được cập nhật trước. Các trang đang phục vụ được đổi giao diện theo từng nhóm nhỏ; phim chưa được mở vẫn giữ nguyên. Nội dung và keyword đã gán được giữ lại.</p>
        <div class="stats">
            <div class="stat"><div class="label">Đã xử lý</div><div class="number">{{ number_format($presentationPayload['stats']['processed'] ?? 0) }}</div></div>
            <div class="stat"><div class="label">Đã cập nhật</div><div class="number">{{ number_format($presentationPayload['stats']['published'] ?? 0) }}</div></div>
            <div class="stat"><div class="label">Đã đúng cấu hình</div><div class="number">{{ number_format($presentationPayload['stats']['unchanged'] ?? 0) }}</div></div>
            <div class="stat"><div class="label">Cần kiểm tra</div><div class="number">{{ number_format($presentationPayload['stats']['errors'] ?? 0) }}</div></div>
        </div>
        @if(!empty($presentationPayload['home']['error_code']))<p class="errornotice">Trang chủ: {{ $presentationPayload['home']['error_code'] }}</p>@endif
        @if(!empty($presentationPayload['error_code']) || !empty($presentationPayload['error']))<p class="errornotice">{{ $presentationPayload['error_code'] ?? $presentationPayload['error'] }}</p>@endif
        @if(!empty($presentationPayload['failures']))<details><summary>Các trang cần kiểm tra</summary><ul>@foreach($presentationPayload['failures'] as $failure)<li>Trang #{{ $failure['page_id'] ?? '?' }} · {{ $failure['error_code'] ?? $failure['error'] ?? 'Chưa xác định' }}</li>@endforeach</ul></details>@endif
        <div class="actions"><a class="button secondary" href="/admin/sites/{{ $site->id }}/cache#site-presentation-progress">Tải lại tiến độ</a>@if($openUrl)<a href="{{ $openUrl }}" target="_blank" rel="noopener">Mở website ↗</a>@endif</div>
    </section>
@endif
<div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
    <section class="stat"><div class="label">Trang đang phục vụ</div><div class="number">{{ number_format($pageCounts['live'] ?? 0) }}</div></section>
    <section class="stat"><div class="label">{{ $delivery['mode'] === 'on_demand' ? 'Phim trong kho' : 'Trang đang sửa' }}</div><div class="number">{{ number_format($pageCounts['draft'] ?? 0) }}</div></section>
    <section class="stat"><div class="label">{{ $delivery['mode'] === 'on_demand' ? 'Yêu cầu nội bộ được giữ' : 'Trang đang xử lý' }}</div><div class="number">{{ number_format($publicationPending) }}</div></section>
    <section class="stat"><div class="label">Dung lượng cache HTML</div><div class="number">{{ $formatBytes($stats['bytes']) }}</div></section>
</div>
<section class="card">
    <div class="sectionhead"><h2>Cách phục vụ trang phim</h2><span class="badge {{ $delivery['mode'] === 'on_demand' && $delivery['runtime_enabled'] ? 'active' : 'paused' }}">{{ $delivery['mode'] === 'on_demand' ? 'Tạo khi mở link lần đầu' : 'Tạo sẵn trang' }}</span></div>
    <p>Chọn cách đưa phim đã nhập vào website. Lưu lựa chọn giữ nguyên nội dung, keyword và lịch sử của các trang đang phục vụ.</p>
    @if(!$delivery['available'])
        <p class="note">Hệ thống chưa cập nhật phần cấu hình này trong database. Website tiếp tục dùng chế độ tạo sẵn trang.</p>
    @elseif(!$delivery['runtime_enabled'])
        <p class="note">Tính năng tạo trang khi truy cập chưa bật trên máy chủ. Lựa chọn đã lưu chỉ có hiệu lực khi tính năng này được bật.</p>
    @endif
    @if($canWrite && $delivery['available'])
        <form method="post" action="/admin/sites/{{ $site->id }}/cache">
            @csrf
            <input type="hidden" name="action" value="delivery_mode">
            <input type="hidden" name="delivery_row_version" value="{{ $delivery['row_version'] }}">
            <fieldset>
                <legend>Cách phục vụ</legend>
                <label><input type="radio" name="delivery_mode" value="prepublish" @checked(old('delivery_mode', $delivery['mode']) === 'prepublish')> Tạo sẵn trang</label>
                <p class="muted">Trang được tạo sẵn để khách truy cập đọc bản đã lưu và cache HTML.</p>
                <label><input type="radio" name="delivery_mode" value="on_demand" @checked(old('delivery_mode', $delivery['mode']) === 'on_demand') @disabled($site->content_type !== 'movie' || $site->status !== 'active')> Tạo khi mở link lần đầu</label>
                <p class="muted">Khi mở URL phim đã nhập nhưng chưa có bản phục vụ, hệ thống tạo bản đầu tiên một lần rồi lưu cache. Các lượt sau dùng bản đã lưu; trang đang phục vụ giữ nguyên keyword.</p>
            </fieldset>
            @if($site->content_type !== 'movie' || $site->status !== 'active')<p class="note">Chế độ khi truy cập lần đầu chỉ dành cho website phim đang hoạt động.</p>@endif
            @error('delivery_mode')<p class="errornotice">{{ $message }}</p>@enderror
            @error('delivery_row_version')<p class="errornotice">{{ $message }}</p>@enderror
            <div class="actions"><button>Lưu cách phục vụ</button></div>
        </form>
    @elseif(!$canWrite)
        <p class="note">Bạn có quyền xem. Chủ website, người vận hành hoặc quản trị hệ thống có thể đổi cách phục vụ.</p>
    @endif
</section>
<details class="card">
    <summary>Nâng cao · Lịch sử áp dụng & cấu hình</summary>
<section class="card">
    <div class="sectionhead"><h2>Áp dụng và mở website</h2><span class="badge {{ $publicationPending ? 'queued' : 'active' }}">{{ $publicationPending ? 'Đang có trang chờ áp dụng' : 'Không có trang chờ áp dụng' }}</span></div>
    <p>Website phục vụ bản đã xuất bản. {{ $delivery['mode'] === 'on_demand' ? 'Nút dưới đây cập nhật giao diện từ cấu hình đã lưu, giữ nguyên nội dung và keyword.' : 'Nút dưới đây áp dụng cấu hình và nội dung nháp đã lưu; theo dõi kết quả ngay tại màn hình này.' }}</p>
    <div class="actions">
        @if($canWrite && $activePages && $site->status === 'active')
            <form method="post" action="/admin/sites/{{ $site->id }}/apply">
                @csrf<input type="hidden" name="site_id" value="{{ $site->id }}"><input type="hidden" name="idempotency_key" value="{{ $applyKey }}">
                <button>{{ $delivery['mode'] === 'on_demand' ? 'Áp dụng giao diện đã lưu' : 'Áp dụng cấu hình & nội dung' }}</button>
            </form>
        @endif
        <a href="/admin/pages?site_id={{ $site->id }}">Trang & tác phẩm →</a>
    </div>
    @if(!$activePages)<p class="note">Website chưa có trang đang hoạt động. Nhập nội dung hoặc tạo trang để bắt đầu.</p>@endif
    @if($latestBatch)
        <div class="sectionhead"><h3>Lần áp dụng gần nhất #{{ $latestBatch->id }}</h3><span class="badge {{ $latestBatch->status }}">{{ $batchLabels[$latestBatch->status] ?? $latestBatch->status }}</span></div>
        <p>{{ number_format($batchCounts['published'] ?? 0) }} trang đã xuất bản · {{ number_format($batchCounts['unchanged'] ?? 0) }} trang giữ nguyên · {{ number_format(($batchCounts['error'] ?? 0) + ($batchCounts['failed'] ?? 0) + ($batchCounts['stale'] ?? 0) + ($batchCounts['locked'] ?? 0) + ($batchCounts['capacity_conflict'] ?? 0)) }} trang cần kiểm tra.</p>
        @if($batchErrors->isNotEmpty())
            <p class="note">Có trang chưa áp dụng được. Bản đang phục vụ được giữ nguyên cho các trang lỗi; xem nguyên nhân bên dưới rồi sửa trước khi áp dụng lại.</p>
            <div class="tablewrap"><table><thead><tr><th>Trang</th><th>Nguyên nhân</th></tr></thead><tbody>
                @foreach($batchErrors as $error)
                    <tr><td><a href="/admin/pages/{{ $error->page_id }}?site_id={{ $site->id }}">#{{ $error->page_id }} · {{ $error->canonical_path }}</a></td><td><code>{{ $error->error_code }}</code>
                        @if(in_array($error->error_code, ['QUICK_APPLY_CONFIGURATION_CHANGED','QUICK_APPLY_PAGE_CHANGED','QUICK_APPLY_PERMISSION_CHANGED']))
                            <br><small>Cấu hình, trang hoặc quyền vừa thay đổi trong lúc xử lý. Kiểm tra lại rồi bấm Lưu & áp dụng.</small>
                        @elseif($error->error_code === 'QUICK_APPLY_ASSIGNMENT_CHANGED')
                            <br><small>Keyword dự kiến không còn khớp. Hệ thống đã giữ bản cũ; kiểm tra kho và giới hạn trước khi áp dụng lại.</small>
                        @endif
                        @if(str_contains($error->error_code, 'KEYWORD_REQUIRED') || str_contains($error->error_code, 'PRIMARY_REQUIRED') || str_contains($error->error_code, 'NO_ELIGIBLE_KEYWORD'))
                            <br><small>Chưa có keyword phù hợp. Kiểm tra kho keyword đã gắn vào website và giới hạn sử dụng.</small> <a href="/admin/sites/{{ $site->id }}/edit?tab=pools">Kho keyword của website →</a>
                        @endif
                    </td></tr>
                @endforeach
            </tbody></table></div>
        @endif
        <a href="/admin/batches/{{ $latestBatch->id }}?site_id={{ $site->id }}">Kết quả đầy đủ và lịch sử →</a>
    @else<p class="muted">Website chưa có lần áp dụng nào được ghi nhận.</p>@endif
    @if($catalogEvent)
        @php
            $referenceSkips = $catalogPayload['skipped_reference_page_ids'] ?? [];
            $referenceSkipsValid = is_array($referenceSkips);
            $hasReferenceSkips = !$referenceSkipsValid || $referenceSkips !== [];
            $catalogStatus = $catalogPayload['status'] ?? '';
            $referenceUnavailable = isset($catalogPayload['reference_batch_id']) && !$referenceBatch;
            $catalogLabels = [
                'waiting_parent' => 'Đợi nội dung phim',
                'waiting_catalog' => 'Đang cập nhật trang chủ và danh mục',
                'waiting_references' => 'Đang cập nhật liên kết phim',
                'completed' => $referenceUnavailable ? 'Đã xử lý yêu cầu; chưa xác nhận liên kết phim' : ($hasReferenceSkips ? 'Đã xử lý cập nhật; có trang giữ nguyên liên kết' : (isset($catalogPayload['reference_batch_id']) ? 'Đã cập nhật trang chủ, danh mục và liên kết phim' : 'Đã cập nhật trang chủ và danh mục')),
                'failed' => isset($catalogPayload['reference_batch_id']) ? 'Cập nhật liên kết phim gặp lỗi' : 'Cập nhật trang chủ và danh mục gặp lỗi',
                'skipped' => 'Không cần cập nhật danh mục',
            ];
            $referencePending = collect(['pending', 'claimed', 'rendering', 'activating'])->sum(fn ($status) => (int) $referenceCounts->get($status, 0));
            $referenceErrors = $referenceCounts->sum() - $referencePending - ($referenceCounts['published'] ?? 0) - ($referenceCounts['unchanged'] ?? 0);
            $referenceSkipLabels = ['PAGE_CHANGED' => 'Trang đã thay đổi trong lúc xử lý', 'PAGE_LOCKED' => 'Trang đang khóa áp dụng', 'OWN_DRAFT_CHANGED' => 'Trang có bản sửa chưa áp dụng'];
        @endphp
        <h3>Trang chủ, danh mục & liên kết phim</h3>
        <p>{{ number_format($catalogPending) }} yêu cầu đang chờ · {{ number_format($catalogFailed) }} yêu cầu còn lỗi. Ưu tiên hiển thị yêu cầu chưa xử lý xong.</p>
        <p><span class="badge {{ $catalogStatus === 'failed' ? 'failed' : ($catalogStatus === 'completed' && !$hasReferenceSkips && !$referenceUnavailable ? 'active' : 'queued') }}">{{ $catalogLabels[$catalogStatus] ?? 'Chưa xác định trạng thái' }}</span></p>
        @if(($catalogPayload['warning'] ?? '') === 'CATALOG_PARENT_PARTIAL')<p class="note">Có nội dung chưa áp dụng được. Trang chủ, danh mục và liên kết chỉ cập nhật từ phần nội dung đã phục vụ; hãy kiểm tra lỗi của lần áp dụng phim.</p>@endif
        @if(!empty($catalogPayload['error']))<p class="errornotice">Lỗi: <code>{{ is_string($catalogPayload['error']) ? $catalogPayload['error'] : \App\Domain\Support\Json::encode($catalogPayload['error']) }}</code>. Đây chưa phải kết quả thành công.</p>@endif
        @if($referenceBatch)
            <p><strong>Liên kết phim:</strong> {{ number_format($referenceCounts['published'] ?? 0) }} trang đã cập nhật · {{ number_format($referenceCounts['unchanged'] ?? 0) }} trang giữ nguyên · {{ number_format($referencePending) }} trang đang chờ · {{ number_format($referenceErrors) }} trang cần kiểm tra.</p>
        @elseif(isset($catalogPayload['reference_batch_id']))
            <p class="note">Chưa đọc được lần cập nhật liên kết thuộc website này. Chưa xác nhận kết quả cập nhật liên kết.</p>
        @endif
        @if($hasReferenceSkips)
            @if($referenceSkipsValid)
                <p class="note" role="status">Có {{ number_format(count($referenceSkips)) }} trang chưa cập nhật liên kết. Bản đang phục vụ được giữ nguyên; kiểm tra các trang bên dưới trước khi áp dụng lại.</p>
                <ul>@foreach($referenceSkips as $referencePageId => $referenceReason)<li>Trang #{{ $referencePageId }}: {{ $referenceSkipLabels[is_string($referenceReason) ? $referenceReason : ''] ?? 'Chưa xác định nguyên nhân' }}@if(is_string($referenceReason)) · <code>{{ $referenceReason }}</code>@endif</li>@endforeach</ul>
            @else
                <p class="note">Chưa đọc được danh sách trang giữ nguyên liên kết. Chưa xác nhận tất cả liên kết đã cập nhật.</p>
            @endif
        @endif
        <div class="actions">@if($catalogParent)<a href="/admin/batches/{{ $catalogParent->id }}?site_id={{ $site->id }}">Lần áp dụng nội dung #{{ $catalogParent->id }}</a>@endif @if($catalogChild)<a href="/admin/batches/{{ $catalogChild->id }}?site_id={{ $site->id }}">Lần cập nhật danh mục #{{ $catalogChild->id }}</a>@endif @if($referenceBatch)<a href="/admin/batches/{{ $referenceBatch->id }}?site_id={{ $site->id }}">Lần cập nhật liên kết #{{ $referenceBatch->id }}</a>@endif</div>
    @endif
</section>
</details>
<section class="card">
    <div class="sectionhead"><h2>Cache HTML public</h2><span class="badge {{ $cacheReady ? 'active' : 'paused' }}">{{ $cacheReady ? 'Đã bật cho website phim' : 'Chưa bật cho website này' }}</span></div>
    <p>Cache lưu HTML để mở nhanh hơn. Phim mới ở chế độ tạo khi mở link sẽ được tạo và lưu cache trong lượt truy cập đầu tiên. Phim đã có bản phục vụ được đọc từ database khi mất cache; keyword giữ nguyên.</p>
    <p><strong>{{ $stats['file_count'] === null ? 'Chưa xác định số' : number_format($stats['file_count']) }} file HTML</strong> · {{ $formatBytes($stats['bytes']) }}. Một trang có thể có nhiều file theo domain và kiểu hiển thị.</p>
    @if($stats['error'])<p class="note">{{ $stats['error'] }}</p>@endif
    @if(isset($stats['budget']))
        <p class="note" data-cache-budget>Ngân sách ghi cache: <strong>{{ $formatBytes($stats['budget']['limit_bytes']) }}</strong> · Đã ghi lũy kế: <strong>{{ $formatBytes($stats['budget']['accounted_bytes']) }}</strong>.<br>{{ $stats['budget']['description'] }} Khi hết ngân sách, website tiếp tục đọc HTML đã lưu trong database.</p>
        @if($stats['budget']['exhausted'] === true)<p class="note">Đã hết ngân sách ghi cache. Bạn có thể xóa cache website để tính lại từ dung lượng thực tế; nội dung, keyword và lịch sử vẫn được giữ.</p>@endif
    @endif
    <div class="actions">
        @if($canWrite)
            <form method="post" action="/admin/sites/{{ $site->id }}/cache">@csrf<input type="hidden" name="action" value="rebuild"><button @disabled(!$cacheReady || !$stats['available'])>Dựng lại cache</button></form>
            <form method="post" action="/admin/sites/{{ $site->id }}/cache">@csrf<input type="hidden" name="action" value="clear"><button class="secondary" @disabled(!$stats['available'])>Xóa cache website này</button></form>
        @else<p class="note">Bạn có quyền xem. Chỉ chủ website, người vận hành hoặc quản trị hệ thống được xóa và dựng lại cache.</p>@endif
        <a href="/admin/sites/{{ $site->id }}/cache">Tải lại số liệu</a>
    </div>
    <p class="muted">Dựng lại cache gửi yêu cầu xử lý nền. Khi có cache, PHP đọc file HTML để trả cho khách; khi cache thiếu, ứng dụng phục vụ bản đã lưu và tạo lại cache.</p>
    <div class="grid two">
        <div><h3>Yêu cầu cache đang chờ</h3><p><strong>{{ number_format($cachePending) }}</strong> yêu cầu mới · <strong>{{ number_format($cacheRetry) }}</strong> yêu cầu từng lỗi, chờ thử lại.</p>
            @if($latestCacheEvent)<p class="muted">{{ $latestCacheEvent->delivered_at ? 'Yêu cầu gần nhất đã xử lý lúc' : 'Yêu cầu gần nhất đang chờ từ' }} {{ \Carbon\CarbonImmutable::parse($latestCacheEvent->delivered_at ?? $latestCacheEvent->created_at)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}.</p>@endif
        </div>
        <div><h3>Phạm vi thao tác</h3><p>Chỉ các domain thuộc website này. Cache của website khác, lịch sử xuất bản và kho keyword được giữ nguyên.</p></div>
    </div>
    <div class="tablewrap"><table><thead><tr><th>Domain</th><th>File HTML</th><th>Dung lượng</th><th>Trạng thái</th></tr></thead><tbody>
        @forelse($stats['hosts'] as $host)<tr><td><code>{{ $host['host'] }}</code>@if($host['is_primary']) <span class="badge">Chính</span>@endif</td><td>{{ $host['file_count'] === null ? 'Chưa xác định' : number_format($host['file_count']) }}</td><td>{{ $formatBytes($host['bytes']) }}</td><td>{{ $host['error'] ?? (!$host['eligible'] ? 'Hostname không dùng cache public' : ($host['status'] === 'active' ? 'Đang hoạt động' : 'Domain đang tạm dừng')) }}</td></tr>
        @empty<tr><td colspan="4" class="empty">Website chưa có domain được cấu hình.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endsection
