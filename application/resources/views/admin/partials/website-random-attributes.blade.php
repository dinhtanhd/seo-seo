@php
    $randomDefaults = is_array($randomAttributeDefaults ?? null) ? $randomAttributeDefaults : [];
    $excludeName = (bool) ($excludeName ?? false);
    $movieTextFields = $excludeName ? ['list_prefix', 'detail_prefix', 'play_prefix', 'search_prefix'] : ['name', 'list_prefix', 'detail_prefix', 'play_prefix', 'search_prefix'];
    $rawRandomFields = old('random_fields', []);
    $selectedRandomFields = is_array($rawRandomFields) ? array_values(array_filter($rawRandomFields, 'is_string')) : [];
    $rawRandomValues = old('random_values', []);
    $oldRandomValues = is_array($rawRandomValues) ? $rawRandomValues : [];
    $randomTextFields = [
        'name' => ['Tên hiển thị website', 'Chọn một tên cho mỗi domain chưa ghi tên riêng trong CSV. Tên phim gốc lấy từ nguồn vẫn được giữ.', '星河影院'],
        'list_prefix' => ['Prefix danh mục', 'Đường dẫn mở danh sách thể loại, ví dụ /type hoặc /category.', '/type'],
        'detail_prefix' => ['Prefix chi tiết phim', 'Đường dẫn trang giới thiệu từng phim, ví dụ /movie hoặc /video.', '/movie'],
        'play_prefix' => ['Prefix phát phim', 'Đường dẫn phát video và các tập, ví dụ /play hoặc /watch.', '/play'],
        'search_prefix' => ['Prefix tìm kiếm', 'Đường dẫn trang tìm kiếm, ví dụ /search hoặc /find.', '/search'],
        'fanmulu_prefix' => ['Prefix fanmulu', 'Chọn một gốc fanmulu cho mỗi website. Ví dụ /f hoặc /videos; thay danh sách gốc trong bộ cấu hình của website mới.', '/f'],
        'fanmulu_url_template' => ['Quy tắc URL fanmulu', 'Chọn một mẫu cho mỗi website. Các phần chữ/số sẽ được tạo riêng và giữ cố định khi cấp từng URL fanmulu.', '{8chucai}{4chuso}'],
    ];
    $randomEnumFields = [
        'movie_key' => ['Kiểu định danh phim', ['source_id' => 'ID nguồn', 'pinyin_id' => 'Pinyin có dấu gạch nối + ID', 'pinyin_compact_id' => 'Pinyin liền + ID']],
        'category_key' => ['Kiểu định danh danh mục', ['id' => 'ID danh mục', 'pinyin_id' => 'Pinyin có dấu gạch nối + ID', 'pinyin_compact_id' => 'Pinyin liền + ID']],
    ];
    $randomAvailableSuffixes = is_array($randomSuffixChoices ?? null) ? array_values(array_filter($randomSuffixChoices, 'is_string')) : ['', '.html', '.asp'];
    $randomSuffixOptions = [];
    foreach ($randomAvailableSuffixes as $suffix) {
        $randomSuffixOptions[$suffix === '' ? '__none__' : $suffix] = $suffix === '' ? 'Không có đuôi' : $suffix;
    }
@endphp
<section class="card" id="random-attributes" x-data>
    <h2>3. Thuộc tính ngẫu nhiên cho từng website</h2>
    <p>Bật mục muốn thay đổi rồi điền các giá trị có thể chọn. Mỗi domain nhận một giá trị ở từng mục, lưu cố định ngay khi nhận danh sách; thử lại hoặc mở website không chọn lại.</p>
    <p class="muted">Mục không bật giữ thiết lập trong bộ cấu hình. Các lựa chọn này chỉ dùng cho website mới trong đợt này; trang chủ vẫn giữ đường dẫn đã cấu hình. Danh sách quản lý luôn dùng tên miền.</p>
    <div class="actions"><button type="button" class="secondary" @click="$dispatch('bulk-random-all', { enabled: true })">Bật tất cả mục gợi ý</button><button type="button" class="secondary" @click="$dispatch('bulk-random-all', { enabled: false })">Bỏ chọn các mục</button></div>
    @if(!$excludeName)
        <div class="note">Tên ghi sau domain trong CSV được ưu tiên giữ lại. Ví dụ <code>phim-a.example.com,星河影院</code> dùng tên 星河影院; domain không ghi tên riêng mới chọn ngẫu nhiên từ danh sách.</div>
    @endif
    <h3>{{ $excludeName ? 'Đường dẫn phim chính' : 'Tên hiển thị và đường dẫn phim chính' }}</h3>
    <div class="grid two">
        @foreach($movieTextFields as $randomField)
            @include('admin.partials.website-random-text-field', ['field' => $randomField, 'fieldInfo' => $randomTextFields[$randomField]])
        @endforeach
    </div>
    <div class="grid two" style="margin-top:16px">
        @foreach($randomEnumFields as $field => [$label, $choices])
            @php
                $fieldEnabled = in_array($field, $selectedRandomFields, true);
                $rawSelected = $oldRandomValues[$field] ?? ($randomDefaults[$field] ?? array_keys($choices));
                $fieldSelected = is_array($rawSelected) ? array_values(array_filter($rawSelected, 'is_string')) : [];
            @endphp
            <div x-data="{ enabled: @js($fieldEnabled) }" @bulk-random-all.window="enabled = $event.detail.enabled === true">
                <label class="checkboxline"><input type="checkbox" name="random_fields[]" value="{{ $field }}" x-model="enabled" @checked($fieldEnabled)>Ngẫu nhiên {{ mb_strtolower($label) }}</label>
                <div x-show="enabled" @if(!$fieldEnabled) x-cloak @endif x-ref="choices">
                    @foreach($choices as $value => $choiceLabel)<label class="checkboxline"><input type="checkbox" name="random_values[{{ $field }}][]" value="{{ $value }}" @checked(in_array($value, $fieldSelected, true)) @disabled(!$fieldEnabled) :disabled="!enabled">{{ $choiceLabel }}</label>@endforeach
                    <small>Chọn ít nhất một kiểu. ID được giữ trong URL để tránh phim hoặc danh mục trùng tên.</small>
                </div>
            </div>
        @endforeach
    </div>
    @php
        $field = 'suffix';
        $fieldEnabled = in_array($field, $selectedRandomFields, true);
        $rawSelected = $oldRandomValues[$field] ?? array_map(fn ($value) => $value === '' ? '__none__' : $value, $randomDefaults[$field] ?? $randomAvailableSuffixes);
        $fieldSelected = is_array($rawSelected) ? array_values(array_filter($rawSelected, 'is_string')) : [];
    @endphp
    <div style="margin-top:16px" x-data="{ enabled: @js($fieldEnabled) }" @bulk-random-all.window="enabled = $event.detail.enabled === true">
        <label class="checkboxline"><input type="checkbox" name="random_fields[]" value="suffix" x-model="enabled" @checked($fieldEnabled)>Ngẫu nhiên đuôi URL phim chính</label>
        <div x-show="enabled" @if(!$fieldEnabled) x-cloak @endif><div class="actions">@foreach($randomSuffixOptions as $value => $label)<label class="checkboxline"><input type="checkbox" name="random_values[suffix][]" value="{{ $value }}" @checked(in_array($value, $fieldSelected, true)) @disabled(!$fieldEnabled) :disabled="!enabled">{{ $label }}</label>@endforeach</div><small>Mỗi website chọn một đuôi cho danh mục, chi tiết, phát phim và tìm kiếm. Đuôi trang chủ giữ theo bộ cấu hình. <a href="/admin/url-suffixes" target="_blank" rel="noopener">Quản lý các đuôi được phép ↗</a></small></div>
    </div>
    <details style="margin-top:20px" @bulk-random-all.window="if ($event.detail.enabled === true) $el.open = true" @if(array_intersect(['fanmulu_prefix', 'fanmulu_suffix', 'fanmulu_url_template'], $selectedRandomFields)) open @endif>
        <summary>Địa chỉ và quy tắc fanmulu</summary>
        <p class="muted">Chỉ đổi cách tạo địa chỉ fanmulu của website mới. Trạng thái bật/tắt, kho keyword và giới hạn vẫn theo lựa chọn ở mục 2 và bộ cấu hình.</p>
        <div class="grid two">
            @foreach(['fanmulu_prefix', 'fanmulu_url_template'] as $randomField)
                @include('admin.partials.website-random-text-field', ['field' => $randomField, 'fieldInfo' => $randomTextFields[$randomField]])
            @endforeach
        </div>
        @php
            $field = 'fanmulu_suffix';
            $fieldEnabled = in_array($field, $selectedRandomFields, true);
            $rawSelected = $oldRandomValues[$field] ?? array_map(fn ($value) => $value === '' ? '__none__' : $value, $randomDefaults[$field] ?? $randomAvailableSuffixes);
            $fieldSelected = is_array($rawSelected) ? array_values(array_filter($rawSelected, 'is_string')) : [];
        @endphp
        <div style="margin-top:16px" x-data="{ enabled: @js($fieldEnabled) }" @bulk-random-all.window="enabled = $event.detail.enabled === true">
            <label class="checkboxline"><input type="checkbox" name="random_fields[]" value="fanmulu_suffix" x-model="enabled" @checked($fieldEnabled)>Ngẫu nhiên đuôi URL fanmulu</label>
            <div x-show="enabled" @if(!$fieldEnabled) x-cloak @endif><div class="actions">@foreach($randomSuffixOptions as $value => $label)<label class="checkboxline"><input type="checkbox" name="random_values[fanmulu_suffix][]" value="{{ $value }}" @checked(in_array($value, $fieldSelected, true)) @disabled(!$fieldEnabled) :disabled="!enabled">{{ $label }}</label>@endforeach</div></div>
        </div>
        <p class="muted">Ví dụ mẫu: <code>{ngaythangnam}/{6chucai}{4chuso}</code>. Ngày được ghim khi cấp URL; mẫu này không đổi URL fanmulu đã tồn tại.</p>
    </details>
    <p class="muted">Ví dụ minh họa một website mới: danh mục <code>/category/dianying-12.html</code>, phim <code>/video/shanhai-9-123.html</code>, phát tập 1 <code>/watch/shanhai-9-123/1.html</code>. Kết quả thực của từng domain được hiển thị sau khi nhận danh sách.</p>
</section>
