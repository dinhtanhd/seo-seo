@extends('admin.layout')
@section('title', 'Quảng cáo & thống kê dùng chung')
@section('content')
@php
    $storedCode = is_string(data_get($settings, 'code')) ? data_get($settings, 'code') : '';
    $storedEnabled = (bool) data_get($settings, 'enabled', false);
    $oldCode = old('code', $storedCode);
    $codeValue = is_string($oldCode) ? $oldCode : $storedCode;
    $oldEnabled = old('enabled', $storedEnabled);
    $enabledValue = is_scalar($oldEnabled) ? filter_var($oldEnabled, FILTER_VALIDATE_BOOL) : $storedEnabled;
    $storedOrigins = data_get($settings, 'extra_origins', []);
    $originsValue = is_array($storedOrigins) ? implode("\n", array_filter($storedOrigins, 'is_string')) : (is_string($storedOrigins) ? $storedOrigins : '');
    $oldOrigins = old('extra_origins', $originsValue);
    $originsValue = is_string($oldOrigins) ? $oldOrigins : $originsValue;
    $storedVersion = data_get($settings, 'row_version', 1);
    $oldVersion = old('row_version', $storedVersion);
    $versionValue = is_scalar($oldVersion) ? $oldVersion : $storedVersion;
    $canManage = (bool) ($canManage ?? false);
    $hasStoredCode = trim($storedCode) !== '';
    $regularPath = data_get($settings, 'regular_js_path', '/__tram_ads/regular.js');
    $fanmuluPath = data_get($settings, 'fanmulu_js_path', '/__tram_ads/fanmulu.js');
    $regularPath = is_string($regularPath) ? $regularPath : '/__tram_ads/regular.js';
    $fanmuluPath = is_string($fanmuluPath) ? $fanmuluPath : '/__tram_ads/fanmulu.js';
    $oldRegularPath = old('regular_js_path', $regularPath);
    $oldFanmuluPath = old('fanmulu_js_path', $fanmuluPath);
    $regularPathValue = is_string($oldRegularPath) ? $oldRegularPath : $regularPath;
    $fanmuluPathValue = is_string($oldFanmuluPath) ? $oldFanmuluPath : $fanmuluPath;
    $tongjiSettings = $tongji ?? null;
    $storedTongjiCode = data_get($tongjiSettings, 'code', '');
    $storedTongjiCode = is_string($storedTongjiCode) ? $storedTongjiCode : '';
    $oldTongjiCode = old('tongji_code', $storedTongjiCode);
    $tongjiCodeValue = is_string($oldTongjiCode) ? $oldTongjiCode : $storedTongjiCode;
    $storedTongjiEnabled = (bool) data_get($tongjiSettings, 'enabled', false);
    $oldTongjiEnabled = old('tongji_enabled', $storedTongjiEnabled);
    $tongjiEnabledValue = is_scalar($oldTongjiEnabled) ? filter_var($oldTongjiEnabled, FILTER_VALIDATE_BOOL) : $storedTongjiEnabled;
    $storedTongjiPlacement = data_get($tongjiSettings, 'placement', 'body');
    $oldTongjiPlacement = old('tongji_placement', $storedTongjiPlacement);
    $tongjiPlacementValue = is_string($oldTongjiPlacement) && in_array($oldTongjiPlacement, ['head', 'body'], true) ? $oldTongjiPlacement : 'body';
    $storedTongjiVersion = data_get($tongjiSettings, 'row_version', 1);
    $oldTongjiVersion = old('tongji_row_version', $storedTongjiVersion);
    $tongjiVersionValue = is_scalar($oldTongjiVersion) ? $oldTongjiVersion : $storedTongjiVersion;
    $hasTongjiCode = trim($storedTongjiCode) !== '';
    $storedAliases = data_get($settings, 'js_aliases', []);
    $regularAliases = is_array($storedAliases) && is_array($storedAliases['regular'] ?? null) ? array_filter($storedAliases['regular'], 'is_string') : ['/__tram_ads/regular.js'];
    $fanmuluAliases = is_array($storedAliases) && is_array($storedAliases['fanmulu'] ?? null) ? array_filter($storedAliases['fanmulu'], 'is_string') : ['/__tram_ads/fanmulu.js'];
@endphp
<div class="pagehead">
    <div><div class="eyebrow">CÀI ĐẶT DÙNG CHUNG</div><h1>Quảng cáo &amp; thống kê</h1><p>Quản lý mã JS quảng cáo, đường dẫn tệp và mã thống kê Tongji tại một nơi.</p></div>
    <div class="actions"><a class="button secondary" href="/admin/sites/bulk-edit">Sửa hàng loạt</a><a class="button secondary" href="/admin/sites">Website &amp; domain</a></div>
</div>
<section class="card">
    <div class="sectionhead"><h2>Mã quảng cáo dùng chung</h2><span class="badge {{ $storedEnabled && $hasStoredCode ? 'active' : 'paused' }}">{{ !$hasStoredCode ? 'Chưa có mã quảng cáo' : ($storedEnabled ? 'Mã chung đang bật' : 'Mã chung đang tắt') }}</span></div>
    <form method="post" action="/admin/advertising" x-data="{ enabled: @js($enabledValue), regularPath: @js($regularPathValue), fanmuluPath: @js($fanmuluPathValue) }">
        @csrf
        <input type="hidden" name="row_version" value="{{ $versionValue }}">
        <fieldset @disabled(!$canManage)>
            <input type="hidden" name="enabled" value="0">
            <label class="checkboxline"><input type="checkbox" name="enabled" value="1" x-model="enabled" @checked($enabledValue)>Bật mã quảng cáo dùng chung</label>
            <p class="muted">Tắt mục này để ngừng mã quảng cáo trên mọi website. Các lựa chọn riêng của từng domain vẫn được giữ.</p>
            <div class="formrow">
                <div>
                    <label>Đường dẫn JS · phim chính<input name="regular_js_path" x-model="regularPath" value="{{ $regularPathValue }}" maxlength="240" spellcheck="false" placeholder="/assets/js/ads.js" required></label>
                    <p class="code" aria-live="polite" x-text="'https://domain-cua-ban.example' + regularPath">https://domain-cua-ban.example{{ $regularPathValue }}</p>
                    @error('regular_js_path')<p class="errornotice">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label>Đường dẫn JS · fanmulu<input name="fanmulu_js_path" x-model="fanmuluPath" value="{{ $fanmuluPathValue }}" maxlength="240" spellcheck="false" placeholder="/assets/js/fanmulu.js" required></label>
                    <p class="code" aria-live="polite" x-text="'https://domain-cua-ban.example' + fanmuluPath">https://domain-cua-ban.example{{ $fanmuluPathValue }}</p>
                    @error('fanmulu_js_path')<p class="errornotice">{{ $message }}</p>@enderror
                </div>
            </div>
            <small>Nhập đường dẫn bắt đầu bằng / và kết thúc bằng .js; tên tệp nằm ở cuối đường dẫn. Không kèm tên miền, dấu ?, dấu # hoặc đoạn ../. Hai đường dẫn phải khác nhau và dùng cùng mã quảng cáo bên dưới. Mỗi website dùng tên miền của chính nó.</small>
            <p class="muted">Hai địa chỉ mặc định <code>/__tram_ads/regular.js</code> và <code>/__tram_ads/fanmulu.js</code> vẫn hoạt động sau khi đổi tên tệp.</p>
            @if($regularAliases !== [] || $fanmuluAliases !== [])
                <details style="margin-bottom:20px"><summary>Đường dẫn JS cũ đang được giữ</summary><div class="grid two">
                    <div><strong>Phim chính</strong>@foreach($regularAliases as $alias)<p class="code">{{ $alias }}</p>@endforeach</div>
                    <div><strong>Fanmulu</strong>@foreach($fanmuluAliases as $alias)<p class="code">{{ $alias }}</p>@endforeach</div>
                </div><small>Trang đã lưu cache vẫn tải được mã qua các địa chỉ cũ này.</small></details>
            @endif
            <label>Mã JS quảng cáo<textarea name="code" rows="16" maxlength="65536" spellcheck="false" placeholder="// Dán mã JavaScript quảng cáo của bạn tại đây" :required="enabled">{{ $codeValue }}</textarea></label>
            <small>Có thể dán JavaScript hoặc các thẻ &lt;script&gt; của nhà cung cấp. Mã chạy trực tiếp trên trang public được bật; cách hiển thị do mã quảng cáo quyết định. Mã không chạy trong trang admin này.</small>
            @error('code')<p class="errornotice">{{ $message }}</p>@enderror
            <details style="margin-top:20px" @if($originsValue !== '' || $errors->has('extra_origins')) open @endif>
                <summary>Nâng cao · Miền tài nguyên bổ sung</summary>
                <p class="muted">Hệ thống nhận diện các địa chỉ HTTPS có trong mã. Nếu nhà cung cấp cần tải thêm tài nguyên từ địa chỉ khác, thêm địa chỉ gốc bên dưới.</p>
                <label>Địa chỉ HTTPS bổ sung<textarea name="extra_origins" rows="4" maxlength="10000" spellcheck="false" placeholder="https://cdn.quangcao.example.com">{{ $originsValue }}</textarea></label>
                <small>Mỗi dòng một địa chỉ gốc, gồm https:// và tên miền; không có đường dẫn hoặc dấu *.</small>
                @error('extra_origins')<p class="errornotice">{{ $message }}</p>@enderror
            </details>
            @if($canManage)<div class="formfooter"><button>Lưu mã JS</button></div>@endif
        </fieldset>
    </form>
    @unless($canManage)<p class="note">Chỉ quản trị toàn hệ thống được sửa mã quảng cáo dùng chung.</p>@endunless
</section>
<section class="card" id="tongji-config">
    <div class="sectionhead"><h2>Thống kê truy cập · Tongji</h2><span class="badge {{ $storedTongjiEnabled && $hasTongjiCode ? 'active' : 'paused' }}">{{ !$hasTongjiCode ? 'Chưa có mã thống kê' : ($storedTongjiEnabled ? 'Thống kê đang bật' : 'Thống kê đang tắt') }}</span></div>
    <p>Dán nguyên đoạn mã HTML của 51.la, CNZZ hoặc dịch vụ thống kê bạn dùng. Thống kê áp dụng chung cho các website public, độc lập với lựa chọn quảng cáo ở từng domain.</p>
    <form method="post" action="/admin/advertising/tongji" x-data="{ enabled: @js($tongjiEnabledValue) }">
        @csrf
        <input type="hidden" name="tongji_row_version" value="{{ $tongjiVersionValue }}">
        <fieldset @disabled(!$canManage)>
            <input type="hidden" name="tongji_enabled" value="0">
            <label class="checkboxline"><input type="checkbox" name="tongji_enabled" value="1" x-model="enabled" @checked($tongjiEnabledValue)>Bật mã thống kê dùng chung</label>
            @error('tongji_enabled')<p class="errornotice">{{ $message }}</p>@enderror
            <label>Vị trí chèn<select name="tongji_placement">
                <option value="head" @selected($tongjiPlacementValue === 'head')>Cuối phần head · trước &lt;/head&gt;</option>
                <option value="body" @selected($tongjiPlacementValue === 'body')>Cuối trang · trước &lt;/body&gt;</option>
            </select></label>
            @error('tongji_placement')<p class="errornotice">{{ $message }}</p>@enderror
            <label>Mã HTML thống kê<textarea name="tongji_code" rows="12" maxlength="65536" spellcheck="false" placeholder="Dán toàn bộ mã thống kê, gồm các thẻ script và HTML đi kèm" :required="enabled">{{ $tongjiCodeValue }}</textarea></label>
            <small>Lưu và chèn nguyên mã bạn nhập, không chuyển thành tệp JS quảng cáo. Mã không chạy trong trang admin này. Tắt thống kê vẫn giữ mã đã lưu.</small>
            @error('tongji_code')<p class="errornotice">{{ $message }}</p>@enderror
            @if($canManage)<div class="formfooter"><button>Lưu mã thống kê</button></div>@endif
        </fieldset>
    </form>
    @unless($canManage)<p class="note">Chỉ quản trị toàn hệ thống được sửa mã thống kê dùng chung.</p>@endunless
</section>
<section class="card">
    <h2>Bật quảng cáo cho từng website</h2>
    <p>Mở domain trong Website &amp; domain, chọn <strong>Quảng cáo JS</strong>, rồi bật riêng <strong>Phim chính</strong> và <strong>Fanmulu</strong>. Website mới mặc định chưa bật cả hai mục.</p>
    <a class="button secondary" href="/admin/sites">Chọn website</a>
</section>
@endsection
