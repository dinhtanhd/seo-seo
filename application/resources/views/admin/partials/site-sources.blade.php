@php
    $sourceStatusLabels = ['active' => 'Đang liên kết', 'paused' => 'Đã ngừng nhận · còn liên kết', 'archived' => 'Đã lưu trữ · còn liên kết'];
    $connectorStatusLabels = ['active' => 'Hoạt động', 'paused' => 'Tạm dừng', 'archived' => 'Lưu trữ'];
    $sourceAdapterLabels = ['maccms' => 'API MacCMS', 'shandian' => 'API Shandian', 'html' => 'Trang HTML', 'json' => 'File JSON', 'csv' => 'File CSV', 'fixture' => 'Fixture / demo', 'import' => 'Nhập file'];
    $canAttachSource = auth()->user()->is_global_admin;
    $unboundSources = $availableSources->whereNotIn('id', $websiteSourceBindings->pluck('connector_id'));
@endphp
<section class="card">
    <h2>Nguồn nội dung của {{ $siteLabels->label($site) }}</h2>
    <p class="muted">Website dùng nguồn đã gắn bên dưới. Xóa liên kết để website ngừng nhận phim và cập nhật từ nguồn đó; kho phim chung và các trang đã xuất bản được giữ nguyên.</p>
    <p><a href="/admin/sources">Xem danh mục nguồn dùng chung</a></p>
    @forelse($websiteSourceBindings as $binding)
        @php
            $mapping = App\Domain\Support\Json::decode($binding->mapping_json);
            $policy = App\Domain\Support\Json::decode($binding->auto_publish_policy);
            $selectedTypes = is_array($mapping['selected_type_ids'] ?? null) ? $mapping['selected_type_ids'] : [];
            $categoryMap = is_array($mapping['category_map'] ?? null) ? $mapping['category_map'] : [];
            $modeLabel = ($mapping['mode'] ?? 'all') === 'selected' ? (count($selectedTypes) ? 'Nhận '.count($selectedTypes).' thể loại đã chọn' : 'Không nhận thể loại nào') : 'Nhận tất cả thể loại';
            $formContext = 'source-binding:'.$binding->id;
            $hasOldBinding = old('form_context') === $formContext;
            $displayVersion = $hasOldBinding && is_scalar(old('mapping_version')) ? old('mapping_version') : $binding->mapping_version;
            $displayStatus = $hasOldBinding && is_string(old('status')) ? old('status') : $binding->status;
            $displayEnabled = $hasOldBinding && is_scalar(old('enabled')) ? (string)old('enabled') === '1' : (bool)($policy['enabled'] ?? false);
        @endphp
        <div class="card" style="margin-top:16px;box-shadow:none">
            <div class="sectionhead"><h3>{{ $binding->name }}</h3><span class="badge {{ $binding->status }}">{{ $sourceStatusLabels[$binding->status] ?? $binding->status }}</span></div>
            <p class="muted">Nguồn #{{ $binding->connector_id }} · {{ $sourceAdapterLabels[$binding->kind] ?? $binding->kind }} · Nguồn chung: {{ $connectorStatusLabels[$binding->source_status] ?? $binding->source_status }} · Mapping v{{ $binding->mapping_version }}</p>
            <p>{{ $modeLabel }} · {{ count(array_filter($categoryMap, fn($value) => $value !== null)) }} thể loại đã ghép danh mục · Tự áp dụng: <strong>{{ ($policy['enabled'] ?? false) ? 'Đang bật' : 'Đang tắt' }}</strong></p>
            @if(in_array($binding->kind, ['maccms', 'shandian'], true))<p><a class="button secondary" href="/admin/sources/{{ $binding->connector_id }}/types?site_id={{ $site->id }}">Chọn thể loại API & ghép danh mục</a></p>@endif
            @if($binding->source_status !== 'active')<p class="note">Nguồn chung đang tạm dừng hoặc lưu trữ. Liên kết và bản live vẫn giữ nguyên; nguồn chưa nhận dữ liệu mới.</p>@endif
            @if($canEdit)
                <form method="post" action="/admin/sites/{{ $site->id }}/sources/{{ $binding->connector_id }}">
                    @csrf
                    <input type="hidden" name="form_context" value="{{ $formContext }}">
                    <input type="hidden" name="mapping_version" value="{{ $displayVersion }}">
                    <input type="hidden" name="status" value="{{ $displayStatus }}">
                    <input type="hidden" name="enabled" value="0">
                    <label class="checkboxline"><input type="checkbox" name="enabled" value="1" @checked($displayEnabled)>Tự áp dụng nội dung mới cho website này</label>
                    <p class="muted">Khi bật, nội dung mới từ liên kết đang hoạt động tự được đưa lên website và tạo cache. Muốn website không còn liên kết với nguồn này, dùng Xóa liên kết nguồn.</p>
                    <button class="secondary">Lưu liên kết nguồn</button>
                </form>
                <div x-data style="margin-top:12px">
                    <button type="button" class="danger" @click="document.getElementById('source-unlink-{{ $binding->id }}').showModal()">Xóa liên kết nguồn</button>
                    <dialog class="crud-modal" id="source-unlink-{{ $binding->id }}" aria-labelledby="source-unlink-title-{{ $binding->id }}">
                        <div class="crud-dialog-head"><h2 id="source-unlink-title-{{ $binding->id }}">Xóa liên kết {{ $binding->name }}</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div>
                        <div class="crud-dialog-body">
                            <p>Website <strong>{{ $siteLabels->label($site) }}</strong> sẽ ngừng nhận phim mới và cập nhật tự động từ nguồn này. Các phim đã xuất bản, URL, keyword và lịch sử được giữ nguyên.</p>
                            <p>Kho phim và nguồn dùng chung của các website khác được giữ. Bạn có thể chủ động gắn lại nguồn sau.</p>
                            <form method="post" action="/admin/sites/{{ $site->id }}/sources/{{ $binding->connector_id }}/delete">
                                @csrf
                                <input type="hidden" name="mapping_version" value="{{ $binding->mapping_version }}">
                                <button class="danger">Xóa liên kết nguồn khỏi website</button>
                            </form>
                        </div>
                    </dialog>
                </div>
            @else<p class="muted">Chỉ xem · Owner website hoặc quản trị toàn hệ thống chỉnh liên kết và tự áp dụng.</p>@endif
        </div>
    @empty<p class="empty">Website chưa gắn nguồn nội dung.</p>@endforelse
</section>
@if($canAttachSource)
    <section class="card"><h2>Gắn nguồn dùng chung</h2>
        @if($unboundSources->isNotEmpty())
            <form method="post" action="/admin/sites/{{ $site->id }}/sources">@csrf
                <label>Nguồn nội dung<select name="connector_id" required>@foreach($unboundSources as $source)<option value="{{ $source->id }}">{{ $source->name }} · {{ $sourceAdapterLabels[$source->kind] ?? $source->kind }}{{ $source->status !== 'active' ? ' · tạm dừng' : '' }}</option>@endforeach</select></label>
                <p class="muted">Liên kết mới nhận tất cả thể loại. Có thể chọn lại thể loại và ghép danh mục trước khi crawl; bấm Gắn nguồn chưa tải dữ liệu.</p>
                <input type="hidden" name="enabled" value="0"><label class="checkboxline"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', '1') === '1')>Tự áp dụng nội dung mới & tạo cache sau khi crawl</label>
                <button>Gắn nguồn</button>
            </form>
        @else<p class="muted">Không có nguồn hoạt động hoặc tạm dừng nào chưa gắn website này. Tạo thêm nguồn ở danh mục dùng chung.</p>@endif
    </section>
@elseif($canEdit)<p class="note">Quản trị toàn hệ thống gắn thêm nguồn dùng chung. Bạn có thể chỉnh các liên kết đã gắn với website.</p>@endif
