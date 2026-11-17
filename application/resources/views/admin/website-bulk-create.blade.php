@extends('admin.layout')
@section('title', 'Thêm website hàng loạt')
@section('content')
@php
    $selectedPresetIds = array_map('intval', (array) old('preset_ids', request()->filled('preset_id') ? [request()->integer('preset_id')] : $presets->pluck('id')->all()));
    $displayNameCatalog = is_array($displayNames ?? null) ? $displayNames : [
        'names' => ['xinghedianying', 'shandianyingyuan', 'yunhaidianying', 'qingfengyingyuan', 'liuguangdianying', 'xingkongyingyuan', 'yueguangdianying', 'jinseyingyuan', 'lanhaidianying', 'huanxiangyingyuan'],
        'row_version' => 1, 'using_defaults' => true,
    ];
    $savedDisplayNameText = implode("\n", $displayNameCatalog['names'] ?? []);
    $rawDisplayNameText = old('display_names', $savedDisplayNameText);
    $displayNameText = is_string($rawDisplayNameText) ? $rawDisplayNameText : $savedDisplayNameText;
    $rawDisplayNameVersion = old('row_version', $displayNameCatalog['row_version'] ?? 1);
    $displayNameRowVersion = is_scalar($rawDisplayNameVersion) ? $rawDisplayNameVersion : ($displayNameCatalog['row_version'] ?? 1);
@endphp
<div class="pagehead"><div><div class="eyebrow">TẠO WEBSITE TỪ DANH SÁCH DOMAIN</div><h1>Thêm website hàng loạt</h1><p>Nhập domain, chọn các bộ cấu hình rồi bấm Tạo website. Hệ thống tự tạo, gắn một nguồn phim cho mỗi website, kho keyword và cấu hình cache để mở website luôn.</p></div><div class="actions"><a class="button secondary" href="/admin/sites">Website & domain</a><a class="button secondary" href="/admin/site-presets">Quản lý bộ cấu hình</a></div></div>
@if($recentOperations->isNotEmpty())
<details class="card"><summary>Các đợt thêm website gần đây</summary><p class="muted">Mở lại tiến độ để tiếp tục quản lý sau khi đóng tab. Hiển thị 20 đợt mới nhất.</p><div class="tablewrap"><table><thead><tr><th>Thời gian</th><th>Số domain</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>@foreach($recentOperations as $recent)<tr><td>{{ \Carbon\Carbon::parse($recent->created_at)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</td><td>{{ number_format($recent->total) }}</td><td>{{ ['queued' => 'Đang chờ', 'running' => 'Đang tạo', 'completed' => 'Đã xử lý', 'partial' => 'Có dòng cần xử lý'][$recent->status] ?? $recent->status }}</td><td><a href="/admin/sites/bulk-create/{{ $recent->id }}">Mở tiến độ →</a></td></tr>@endforeach</tbody></table></div></details>
@endif
<form method="post" action="/admin/sites/bulk-create" enctype="multipart/form-data" x-data="{ randomSeo: @js((bool) old('random_seo', true)) }">
    @csrf<input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
    <section class="card"><h2>1. Danh sách domain</h2><label>Mỗi dòng một tên miền hoặc URL<textarea name="domains" rows="12" spellcheck="false" placeholder="phim-a.example.com&#10;phim-b.example.com&#10;https://phim-c.example.com&#10;http://demo.localhost:8080">{{ old('domains') }}</textarea></label><p class="muted">Có thể thêm tên hiển thị sau dấu phẩy, ví dụ <code>phim-a.example.com,星河影院</code>. Danh sách quản lý vẫn hiển thị theo domain. Domain đang có sẽ được báo lỗi; dữ liệu cũ được giữ nguyên.</p><div class="formrow"><label>Hoặc tải file danh sách<input type="file" name="domain_file" accept=".txt,.csv,text/plain,text/csv"><div><small>TXT hoặc CSV UTF-8, tối đa 2 MB. Có thể kết hợp với danh sách đã điền.</small></div></label><label>Giao thức cho dòng chỉ có domain<select name="default_scheme"><option value="https" @selected(old('default_scheme', 'https') === 'https')>HTTPS</option><option value="http" @selected(old('default_scheme', 'https') === 'http')>HTTP · dùng cho localhost</option></select><div><small>URL đã ghi http:// hoặc https:// sẽ giữ giao thức đó.</small></div></label></div></section>
    <section class="card" id="create-display-names">
        <h2>Tên hiển thị trên website</h2>
        <p>Mỗi domain chưa có tên riêng trong CSV sẽ nhận một tên từ danh sách này. Ví dụ <code>phim01.localhost</code> có thể dùng tên <strong>xinghedianying</strong> trên website; danh sách quản lý vẫn hiển thị theo domain.</p>
        <input type="hidden" name="use_display_names" value="1">
        <input type="hidden" name="row_version" value="{{ $displayNameRowVersion }}">
        <label for="bulk-display-names">Danh sách tên, mỗi dòng một tên</label>
        <textarea id="bulk-display-names" name="display_names" rows="10" spellcheck="false" required aria-describedby="bulk-display-names-help bulk-display-names-state" placeholder="xinghedianying&#10;shandianyingyuan&#10;yunhaidianying">{{ $displayNameText }}</textarea>
        <p class="muted" id="bulk-display-names-help">Tối đa 1.000 tên, mỗi tên tối đa 200 ký tự. Bạn có thể nhập tên pinyin hoặc tên riêng bằng ngôn ngữ khác. Tên được chọn một lần cho từng website; thử lại không đổi tên đã chọn. Các website có thể nhận cùng tên.</p>
        <p class="muted" id="bulk-display-names-state">
            @if($displayNameCatalog['using_defaults'] ?? true)
                Chưa có danh sách riêng đã lưu. Danh sách ban đầu gồm 10 tên pinyin gợi ý; sửa rồi bấm Lưu danh sách tên để dùng lại cho các đợt sau.
            @else
                Ô này được điền từ danh sách tên đã lưu. Bạn có thể sửa và lưu lại để dùng cho các đợt sau.
            @endif
        </p>
        <div class="actions"><button type="submit" class="secondary" formaction="/admin/sites/bulk-create/display-names" formnovalidate>Lưu danh sách tên</button></div>
        <p class="muted">Bấm <strong>Tạo website</strong> sẽ dùng danh sách đang nhập, kể cả khi chưa bấm lưu. Tên ghi riêng trong CSV được ưu tiên, ví dụ <code>phim-a.example.com,Yunfan Yingshi</code> sẽ dùng tên Yunfan Yingshi. Lưu danh sách tên chỉ lưu lựa chọn cho lần sau.</p>
    </section>
    <section class="card"><h2>2. Chọn bộ cấu hình để phân phối</h2><p>Nhập bao nhiêu domain tùy nhu cầu, tối đa 10.000 dòng mỗi đợt. Hệ thống chọn bộ cấu hình, giao diện và một nguồn phim cho từng website, lưu cố định lựa chọn; thử lại lỗi giữ nguyên nguồn đã nhận.</p><div class="formrow"><label>Cách chọn bộ cấu hình<select name="distribution"><option value="random" @selected(old('distribution', 'random') === 'random')>Ngẫu nhiên trong các bộ đã chọn</option><option value="round_robin" @selected(old('distribution', 'random') === 'round_robin')>Phân phối lần lượt, cân bằng số domain</option></select></label><div><input type="hidden" name="random_theme" value="0"><label class="checkboxline"><input type="checkbox" name="random_theme" value="1" @checked((bool) old('random_theme', true))>Ngẫu nhiên giao diện HTML/CSS cho từng website</label><div><small>Giao diện HTML/CSS được chọn độc lập với bộ cấu hình, cố định theo từng website. Bỏ chọn để dùng giao diện đã lưu trong mỗi bộ cấu hình.</small></div></div></div>
        <div class="formrow">
            <div>
                <input type="hidden" name="random_seo" value="0">
                <label class="checkboxline"><input type="checkbox" name="random_seo" value="1" x-model="randomSeo" @checked((bool) old('random_seo', true))>Ngẫu nhiên một bộ SEO mẫu mới cho từng website</label>
                <small>Bộ SEO được chọn độc lập với giao diện HTML/CSS, lưu một lần cho mỗi domain. Thử lại hàng đợi, mở link hoặc dựng lại cache giữ nguyên lựa chọn. Bỏ chọn để dùng bộ SEO đã lưu trong bộ cấu hình.</small>
            </div>
            <div>
                @if($eligibleSeoTemplates->isNotEmpty())
                    <p><strong>{{ number_format($eligibleSeoTemplates->count()) }} bộ SEO mẫu đang hoạt động</strong></p>
                    <details><summary>Xem các bộ SEO có thể được chọn</summary><ul>@foreach($eligibleSeoTemplates as $seoTemplate)<li>{{ $seoTemplate->name }} · #{{ $seoTemplate->id }}</li>@endforeach</ul></details>
                @else
                    <p class="note">Chưa có bộ SEO mẫu mới đang hoạt động. <a href="/admin/templates">Thêm 12 bộ mẫu tại Template SEO</a>, hoặc bỏ chọn ngẫu nhiên SEO để dùng bộ đã lưu trong cấu hình. Hệ thống không tự cài mẫu.</p>
                @endif
            </div>
        </div>
        <div class="formrow">
            <label>Phim chính &amp; fanmulu cho domain mới<select name="fanmulu_mode">
                <option value="enabled" @selected(old('fanmulu_mode', 'enabled') === 'enabled')>Bật fanmulu cùng phim chính</option>
                <option value="preset" @selected(old('fanmulu_mode', 'enabled') === 'preset')>Theo từng bộ cấu hình</option>
                <option value="disabled" @selected(old('fanmulu_mode', 'enabled') === 'disabled')>Chỉ phim chính</option>
            </select></label>
            <p class="muted">Fanmulu dùng nội dung phim chính và một keyword cố định làm tên hiển thị. URL mới được tạo khi mở lần đầu rồi lưu cache. Prefix, đuôi URL và giới hạn lấy từ bộ cấu hình; bộ cũ chưa có lựa chọn này dùng /f, không đuôi, tối đa10.000trang. Website đã có giữ nguyên.</p>
        </div>
        @if($presets->isNotEmpty())
            <div x-data><div class="actions"><button type="button" class="secondary" @click="$root.querySelectorAll('input[name=&quot;preset_ids[]&quot;]').forEach(el => el.checked = true)">Chọn tất cả</button><button type="button" class="secondary" @click="$root.querySelectorAll('input[name=&quot;preset_ids[]&quot;]').forEach(el => el.checked = false)">Bỏ chọn tất cả</button></div><div class="tablewrap"><table><thead><tr><th>Chọn</th><th>Bộ cấu hình</th><th>Thiết lập</th><th></th></tr></thead><tbody>@foreach($presets as $preset)@php($summary = (array) ($preset->summary ?? []))<tr><td><input type="checkbox" name="preset_ids[]" value="{{ $preset->id }}" aria-label="Chọn {{ $preset->name }}" @checked(in_array($preset->id, $selectedPresetIds, true))></td><td><strong>{{ $preset->name }}</strong><div><small>{{ $preset->description ?? '' }}</small></div></td><td>{{ ($summary['delivery_mode'] ?? '') === 'on_demand' ? 'Tạo khi mở link + cache HTML' : 'Tạo sẵn trang' }}<div><small>{{ $summary['locale'] ?? 'zh-CN' }} · {{ $summary['pool_count'] ?? '—' }} kho keyword · {{ $summary['source_count'] ?? '—' }} nguồn ứng viên · mỗi website nhận 1 nguồn</small></div></td><td><a href="/admin/site-presets/{{ $preset->id }}/edit" target="_blank" rel="noopener">Xem cấu hình ↗</a></td></tr>@endforeach</tbody></table></div></div>
        @else
            <p class="note">Chưa có bộ cấu hình hoạt động. <a href="/admin/site-presets">Chuẩn bị 10 bộ mẫu</a> trước khi thêm domain.</p>
        @endif
    </section>
    @include('admin.partials.website-random-attributes', ['excludeName' => true])
    <section class="card"><h2>4. Tự tạo & mở website</h2><p>Hệ thống xử lý từng domain và hiển thị kết quả riêng. Domain sẵn sàng có nút Mở website; nếu có lỗi, bạn có thể sửa cấu hình hoặc thử lại những dòng lỗi.</p><p class="muted">Domain thực cần trỏ DNS và máy chủ web về hệ thống. Domain *.localhost dùng cổng local hiện tại để xem ngay trên máy này.</p><div class="actions"><button @disabled($presets->isEmpty()) :disabled="@js($presets->isEmpty()) || (@js($eligibleSeoTemplates->isEmpty()) && randomSeo)">Tạo website</button><a class="button secondary" href="/admin/sites">Hủy</a></div></section>
</form>
@endsection
