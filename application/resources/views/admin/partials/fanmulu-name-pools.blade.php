@php
    $namePoolSettings = $namePoolSettings ?? [];
    $selectedNamePoolIds = array_map('intval', (array) (old('fanmulu.display_name_pool_ids_present') !== null
        ? old('fanmulu.display_name_pool_ids', []) : ($namePoolSettings['display_name_pool_ids'] ?? [])));
@endphp
<fieldset class="configuration-pool-list">
    <legend>Kho tên phim thay thế (fanmulu)</legend>
    <input type="hidden" name="fanmulu[display_name_pool_ids_present]" value="1">
    <p class="muted">Chọn kho chỉ dùng làm tên hiển thị của fanmulu. Keyword SEO chính và phụ vẫn lấy từ cấu hình keyword của website.</p>
    <div class="formrow">
        @forelse($displayNamePools as $namePool)
            <label class="checkboxline"><input type="checkbox" name="fanmulu[display_name_pool_ids][]" value="{{ $namePool->id }}" @checked(in_array((int) $namePool->id, $selectedNamePoolIds, true))>{{ $namePool->name }}
                <small>#{{ $namePool->id }} · {{ $namePool->locale }}
                    @unless($namePool->name_pool_available)
                        · lựa chọn cũ không còn khả dụng
                    @endunless
                </small>
            </label>
        @empty
            <p class="note">Chưa có kho đang hoạt động với keyword tên chính đúng ngôn ngữ. <a href="/admin/pools">Quản lý kho &amp; nhóm</a>.</p>
        @endforelse
    </div>
    <p class="muted">Không chọn kho riêng sẽ giữ cơ chế cũ lấy tên từ kho keyword đã gắn. Thay lựa chọn chỉ áp dụng cho tên chưa được chốt; URL và tên đã lưu được giữ nguyên.</p>
</fieldset>
