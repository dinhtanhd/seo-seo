@php
    $statusLabels = ['active'=>'Đang hoạt động','paused'=>'Tạm dừng','archived'=>'Lưu trữ'];
    $adapterLabels = ['maccms'=>'API MacCMS / Shandian','shandian'=>'API Shandian','html'=>'Trang HTML · selector','json'=>'Nhập file JSON','csv'=>'Nhập file CSV','fixture'=>'Fixture · seed / test / demo'];
    $countLabels = ['received'=>'Đã nhận','parsed'=>'Hợp lệ','valid'=>'Hợp lệ','invalid'=>'Không hợp lệ','quarantined'=>'Cách ly','created'=>'Nội dung mới','updated'=>'Đã cập nhật','unchanged'=>'Không đổi','pages_created'=>'Trang mới','pages_updated'=>'Trang cập nhật','filtered'=>'Bỏ qua theo loại','skipped_binding'=>'Bỏ qua liên kết website','unknown_type'=>'Không có ID thể loại','published'=>'Đã xuất bản','errors'=>'Bản ghi lỗi','skipped'=>'Bản ghi bỏ qua','archived_skipped'=>'Bỏ qua phim đã lưu trữ','manual_locked_skipped'=>'Bỏ qua phim khóa cập nhật','retry_attempts'=>'Số lần thử lại','categories_updated'=>'Trang đổi danh mục','fetched_pages'=>'Số trang API đã nhận','run_id'=>'Mã lượt','status'=>'Trạng thái','cursor'=>'Vị trí tiếp tục','error'=>'Lỗi','crawl_mode'=>'Chế độ nhận','page_limit'=>'Số trang tối đa'];
    $runStatusLabels = ['queued'=>'Đã xếp hàng','running'=>'Đang nhận dữ liệu','paused'=>'Đã tạm dừng','retry_wait'=>'Chờ thử lại','succeeded'=>'Đã xử lý xong phạm vi','partial'=>'Xong, có bản ghi cần kiểm tra','failed'=>'Lượt nhận gặp lỗi'];
    $stopReasonLabels = ['source_end'=>'API đã hết dữ liệu','page_limit'=>'Đã đủ số trang đã chọn'];
@endphp
<section class="card"><h2>Lịch sử nhận & phân tích</h2><p class="muted">Tiến độ được lưu theo từng lượt. Tạm dừng/tiếp tục giữ nguyên chế độ, phạm vi, thứ tự và vị trí tiếp tục; không tạo một lượt mới.</p><div class="tablewrap"><table><thead><tr><th>Lượt</th><th>Nguồn</th><th>Trạng thái / tiến độ</th><th>Kết quả nhận / phân tích</th><th>Lỗi / thao tác</th></tr></thead><tbody>
    @forelse($runs as $run)
    @php
        $runCounts = json_decode($run->counts,true) ?: [];
        $runMode = $run->crawl_mode ?? null;
        $runLimit = $run->page_limit ?? null;
        $isManagedRun = in_array($runMode,['initial','latest'],true);
        $runModeLabel = $runMode === 'initial' ? ($runLimit ? 'Lần đầu · tối đa '.$runLimit.' trang' : 'Lần đầu · toàn bộ đến khi API hết') : ($runMode === 'latest' ? 'Cập nhật · '.$runLimit.' trang mới nhất' : 'Lượt cũ / nhập file');
        $isDescending = $runMode === 'initial' && $runLimit === null && ($runCounts['crawl_direction'] ?? null) === 'descending';
        $startPage = filter_var($runCounts['crawl_start_page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $totalPages = $runLimit ?: ($isDescending && $startPage !== false && $startPage > 0 ? $startPage : null);
        $directionLabel = $isDescending ? 'Thứ tự: trang cuối → trang 1' : (in_array($runMode, ['initial','latest'], true) && ($runMode === 'latest' || $runLimit !== null) ? 'Thứ tự: từ trang 1 tăng dần' : (array_key_exists('crawl_direction', $runCounts) ? 'Thứ tự nhận chưa xác định' : 'Thứ tự: giữ nguyên thứ tự của lượt cũ'));
        $errorCode = explode(':', (string) $run->error, 2)[0];
        $configurationErrors = [
            'BINDING_SELECTION_STALE' => 'Cấu hình nguồn đã thay đổi so với lần ghép thể loại. Kiểm tra nguồn, tải lại danh sách thể loại và lưu ghép, sau đó tạo lượt crawl mới.',
            'SOURCE_CONFIG_CHANGED' => 'Cấu hình nguồn đã thay đổi từ khi tạo lượt này. Tạo lượt crawl mới bằng cấu hình hiện tại; vị trí của lượt cũ được giữ để đối chiếu.',
            'BINDINGS_CHANGED' => 'Website hoặc cách ghép danh mục đã thay đổi từ khi tạo lượt này. Kiểm tra liên kết và ghép thể loại, sau đó tạo lượt crawl mới.',
        ];
        $needsNewRun = isset($configurationErrors[$errorCode]);
    @endphp
    <tr data-ingest-run-id="{{ $run->id }}"><td>#{{ $run->id }}<br><small>{{ $run->created_at }}</small></td><td>#{{ $run->connector_id }}</td><td><span class="badge {{ $run->status }}">{{ $runStatusLabels[$run->status] ?? $run->status }}</span><p>{{ $runModeLabel }}</p><p>{{ $directionLabel }}</p>
        @if($isDescending)
            @if($startPage !== false && $startPage > 0)<small>Bắt đầu tại trang {{ $startPage }} · trong mỗi trang nhận từ cuối danh sách lên đầu.</small>
            @elseif($startPage === 0)<small>API không có trang dữ liệu.</small>
            @elseif(in_array($run->status, ['queued','running','retry_wait'], true))<small>Chưa xác định trang cuối; worker sẽ kiểm tra API trước khi nhận dữ liệu.</small>
            @else<small>Chưa có số liệu trang bắt đầu được lưu.</small>@endif<br>
        @endif
        @if(array_key_exists('fetched_pages',$runCounts))<strong>Đã nhận {{ $runCounts['fetched_pages'] }} trang{{ $totalPages ? ' / '.$totalPages : '' }}</strong>@else<small>Chưa có số liệu trang</small>@endif<br><small>Vị trí tiếp tục: {{ $run->cursor !== null && ctype_digit((string)$run->cursor) ? 'trang '.$run->cursor : ($run->cursor ? 'đã lưu' : (in_array($run->status, ['succeeded','partial'], true) ? 'đã xong phạm vi' : 'chưa có')) }}</small>
        @if(in_array($run->id,$queuedRunIds ?? [],true))<p class="muted">Đang xếp job tiếp theo · tối đa 3 trang/job</p>@elseif($isManagedRun && $run->status==='queued')<p class="muted">Đã xếp hàng, chờ worker xử lý</p>@elseif($isManagedRun && $run->status==='running')<p class="muted">Worker đang nhận dữ liệu · tối đa 3 trang/job</p>@endif
        @if(!empty($run->next_attempt_at))<small>Lần thử tiếp theo: {{ $run->next_attempt_at }}</small>@endif
        @if(isset($runCounts['stop_reason']))<p>{{ $isDescending && $runCounts['stop_reason'] === 'source_end' && $startPage !== 0 ? 'Đã nhận đến trang 1' : ($stopReasonLabels[$runCounts['stop_reason']] ?? $runCounts['stop_reason']) }}</p>@endif
    </td><td>@forelse($runCounts as $countKey=>$countValue)@if(is_scalar($countValue) && !in_array($countKey,['fetched_pages','stop_reason','crawl_direction','crawl_start_page'],true))<div>{{ $countLabels[$countKey] ?? $countKey }}: <strong>{{ $countValue }}</strong></div>@endif @empty Chưa có kết quả @endforelse</td><td>{{ $run->error ?? '—' }}
        @if(($sourceCanOperate[$run->connector_id] ?? false) && $isManagedRun && in_array($run->status,['queued','running','retry_wait']))<form method="post" action="/admin/ingest-runs/{{ $run->id }}/pause">@csrf<button class="secondary" style="margin-top:8px">Tạm dừng lượt này</button></form>@endif
        @if($needsNewRun)<p>{{ $configurationErrors[$errorCode] }}</p>@if($sourceCanOperate[$run->connector_id] ?? false)<div class="actions"><a href="/admin/sources/{{ $run->connector_id }}">Vào nguồn để tạo lượt mới</a>@if($canManageSources)<a href="/admin/sources/{{ $run->connector_id }}/edit">Kiểm tra cấu hình nguồn</a>@endif @foreach($sourceBindings[$run->connector_id] ?? [] as $binding)@if($binding->status === 'active')<a href="/admin/sources/{{ $run->connector_id }}/types?site_id={{ $binding->site_id }}">Ghép thể loại · {{ $siteLabels->label($binding->site_id) }}</a>@endif @endforeach</div>@endif
        @elseif(($sourceCanOperate[$run->connector_id] ?? false) && in_array($run->status,['paused','failed','retry_wait']))<form method="post" action="/admin/ingest-runs/{{ $run->id }}/resume">@csrf<button class="secondary" style="margin-top:8px">Tiếp tục lượt #{{ $run->id }}</button></form>@endif
    </td></tr>@empty<tr><td colspan="5" class="empty">Chưa có lần nhận dữ liệu.</td></tr>@endforelse
</tbody></table></div></section>
@if($quarantine->isNotEmpty())<section class="card"><h2>Bản ghi bị cách ly</h2><div class="tablewrap"><table><thead><tr><th>Run / bản ghi</th><th>External ID</th><th>Lý do</th></tr></thead><tbody>@foreach($quarantine as $record)<tr><td>#{{ $record->run_id }} · dòng {{ $record->record_index }}</td><td>{{ $record->external_id ?? 'Chưa nhận ID' }}</td><td>{{ $record->error }}</td></tr>@endforeach</tbody></table></div><small>Hiển thị 100 lỗi gần nhất từ các run có quyền truy cập.</small></section>@endif
@foreach(['import_result'=>'Kết quả nhập file','crawl_result'=>'Kết quả nhận nguồn'] as $resultKey=>$resultLabel)
    @if(session($resultKey))<section class="card"><h2>{{ $resultLabel }}</h2>@foreach((array)session($resultKey) as $key=>$value)@if(is_scalar($value))<p>{{ $countLabels[$key] ?? $key }}: <strong>{{ $key==='status' ? ($runStatusLabels[$value] ?? $value) : ($key==='crawl_mode' ? (['initial'=>'Lấy lần đầu','latest'=>'Cập nhật mới nhất'][$value] ?? $value) : $value) }}</strong></p>@endif @endforeach</section>@endif
@endforeach
