@extends('admin.layout')
@section('title', $template ? 'Bộ SEO · '.$template->name : 'Tạo bộ Template SEO')
@section('content')
@php
    $labels = \App\Domain\Rules\SeoTemplateBundle::labels();
    $formContext = $template ? 'template-bundle:'.$template->id : 'template-bundle:new';
    $restoreBundle = old('form_context') === $formContext;
    $submittedBundle = $restoreBundle && is_array(old('bundle_form.page_templates')) ? old('bundle_form.page_templates') : [];
    $canEditTemplate = (bool) auth()->user()->is_global_admin;
    $variableService = app(\App\Domain\Rules\TemplateVariableService::class);
    $customVariableOptions = method_exists($variableService,'ready') && $variableService->ready() ? $variableService->variables(true) : collect();
    request()->attributes->set('template_variable_ui_options',$customVariableOptions);
    $customVariableLabels = $customVariableOptions->mapWithKeys(fn ($variable) => ['var.'.$variable->slug => $variable->name])->all();
    $customVariableSamples = $customVariableOptions->mapWithKeys(fn ($variable) => ['var.'.$variable->slug => $variable->values[0] ?? $variable->fallback ?? ''])->all();
    $slotLabels = ['title'=>'Tiêu đề SEO', 'description'=>'Mô tả SEO', 'h1'=>'Tiêu đề H1', 'intro'=>'Đoạn giới thiệu', 'meta_keywords'=>'Meta keywords', 'related_links'=>'Liên kết liên quan', 'managed_body'=>'Chèn link trong nội dung'];
    $activeView = array_key_first($labels);
    foreach (array_keys($labels) as $viewKey) {
        if ($errors->has('bundle_form.page_templates.'.$viewKey.'.*')) { $activeView = $viewKey; break; }
    }
@endphp
<div class="pagehead">
    <div><div class="eyebrow">{{ $template ? 'BỘ TEMPLATE #'.$template->id : 'BỘ TEMPLATE MỚI' }}</div><h1>{{ $template ? $template->name : 'Tạo bộ Template SEO' }}</h1><p>Điền cấu hình SEO cho năm kiểu trang trong cùng một bộ. Chọn bộ này một lần tại cấu hình website.</p></div>
    <div class="actions">@if($canEditTemplate)<button type="submit" form="seo-template-editor" name="intent" value="apply">Lưu & áp dụng</button>@if($template && ($template->deleted_at ?? null) === null)<a class="button danger" href="/admin/deletions/template/{{ $template->id }}">Xóa</a>@endif @endif<a class="button secondary" href="/admin/templates">← Danh sách các bộ</a></div>
</div>
<p class="note">Lưu & áp dụng tạo phiên bản mới và cập nhật {{ number_format($affectedPageCount ?? 0) }} trang đang phục vụ trên {{ number_format($affectedSiteCount ?? 0) }} website dùng bộ này. Trang nháp được giữ lại. Quy tắc riêng của trang, danh mục hoặc website vẫn được ưu tiên; kết quả và lỗi được báo theo từng website.</p>
@if(!empty($starter))
    <section class="card" aria-label="Đối chiếu mẫu SEO gốc">
        <h2>Cấu trúc tham khảo · {{ $starter['reference_brand'] }}</h2>
        <p>{{ $starter['description'] }}</p>
        @php $reference = is_array($starter['reference'] ?? null) ? $starter['reference'] : []; @endphp
        <p class="muted">HTML đã ghi nhận: {{ $reference['page_kind'] ?? 'Chưa xác định kiểu trang' }} · {{ $reference['checked_at'] ?? 'Chưa có thời điểm' }}. Các kiểu trang còn lại được viết thích ứng theo cấu trúc này, dùng tên website và phim của bạn.</p>
        @if(\App\Domain\Rules\Html::safeUrl($reference['url'] ?? ''))<a href="{{ $reference['url'] }}" target="_blank" rel="noopener noreferrer">Mở website tham khảo</a>@endif
        <details><summary>Xem Title / Keywords / Description đã ghi nhận</summary><dl>
            @foreach(['title'=>'T · Title', 'keywords'=>'K · Keywords', 'description'=>'D · Description'] as $field=>$label)
                <dt>{{ $label }}</dt><dd>{{ $reference[$field] ?? 'Chưa lấy được' }}</dd>
            @endforeach
        </dl></details>
    </section>
@endif
<p class="note"><a href="{{ route('admin.template-variables.index') }}">Quản lý biến template →</a> Tạo biến như <code>@{{var.watch}}</code> từ file hoặc bộ giá trị trong DB. Các nút chèn biến có ngay trong mỗi ô. Mỗi trang chọn một giá trị rồi giữ cố định; ví dụ bên dưới dùng giá trị đầu danh sách để minh họa.</p>
@if(!$canEditTemplate)<p class="note">Bạn đang xem cấu hình. Chỉ quản trị toàn hệ thống được lưu bộ template dùng chung.</p>@endif
<form id="seo-template-editor" method="post" action="{{ $template ? '/admin/template-bundles/'.$template->id : '/admin/template-bundles' }}" x-data="{ activeView: @js($activeView) }" novalidate>
    @csrf
    <input type="hidden" name="form_context" value="{{ $formContext }}">
    @if($template)<input type="hidden" name="row_version" value="{{ $restoreBundle && is_scalar(old('row_version')) ? old('row_version') : $template->row_version }}">@endif
    <fieldset class="site-editor-fieldset" @disabled(!$canEditTemplate)>
        <section class="card"><label>Tên bộ Template SEO<input name="name" value="{{ $restoreBundle && is_string(old('name')) ? old('name') : ($template?->name ?? '') }}" maxlength="200" required placeholder="SEO phim Trung Quốc"></label>@if($template)<small>Phiên bản hiện tại: {{ $template->version_no }}</small>@endif</section>
        <nav class="site-editor-tabs" aria-label="Các trang trong bộ Template SEO">
            @foreach($labels as $viewKey => $viewLabel)<button type="button" :class="{ secondary: activeView !== @js($viewKey) }" :aria-pressed="activeView === @js($viewKey)" @click="activeView = @js($viewKey)">{{ $viewLabel }}</button>@endforeach
        </nav>
        @foreach($labels as $viewKey => $viewLabel)
            @php
                $pageValues = \App\Domain\Support\TemplateConfigurationForm::values($definition['page_templates'][$viewKey]);
                $submittedPage = is_array($submittedBundle[$viewKey] ?? null) ? $submittedBundle[$viewKey] : [];
                $tokenLabels = ['site.name'=>'Tên hiển thị trên website', 'kw.secondary'=>'Keyword phụ'];
                if (in_array($viewKey,['detail','play'],true)) { $tokenLabels += ['kw.primary'=>'Keyword chính', 'content.title'=>'Tên phim', 'content.genre'=>'Thể loại', 'content.author'=>'Tác giả', 'content.year'=>'Năm phát hành', 'content.area'=>'Quốc gia / khu vực', 'content.director'=>'Đạo diễn', 'content.actors'=>'Diễn viên', 'content.language'=>'Ngôn ngữ']; }
                if ($viewKey === 'list') { $tokenLabels['category.name'] = 'Tên danh mục'; }
                if ($viewKey === 'search') { $tokenLabels['search.query'] = 'Từ khóa tìm kiếm'; }
                if ($viewKey === 'play') { $tokenLabels += ['episode.title'=>'Tên tập', 'episode.index'=>'Số thứ tự tập']; }
                $tokenLabels += $customVariableLabels;
            @endphp
            <section class="card configuration-editor" x-show="activeView === @js($viewKey)" @if($viewKey !== $activeView) x-cloak @endif>
                <div class="sectionhead"><h2>{{ $viewLabel }}</h2><span class="badge">{{ $viewKey }}</span></div>
                <p class="muted">Biến chỉ được lấy từ dữ liệu của kiểu trang này. Nút chèn biến bên dưới đã lọc theo kiểu trang; có thể đặt giá trị dự phòng cho biến tùy chọn.</p>
                <label>Tên vùng nội dung được quản lý<input name="bundle_form[page_templates][{{ $viewKey }}][managed_body_zone]" value="{{ is_string($submittedPage['managed_body_zone'] ?? null) ? $submittedPage['managed_body_zone'] : $pageValues['managed_body_zone'] }}" maxlength="200" required><small>Vùng nội dung dùng để chèn liên kết, mặc định main-content.</small></label>
                <p class="note">Không đặt: để các quy tắc chèn hoặc mặc định quyết định slot. Tắt: vô hiệu hóa slot tại bộ này cho đến khi có quy tắc riêng ghi đè.</p>
                <div class="configuration-slots">
                    @foreach($pageValues['slots'] as $slotName => $slotValues)
                        @php
                            $submittedSlot = is_array($submittedPage['slots'][$slotName] ?? null) ? $submittedPage['slots'][$slotName] : [];
                            $slotValues = array_replace($slotValues, $submittedSlot);
                            if (!in_array($slotValues['mode'], ['on','off','inherit'], true)) { $slotValues['mode'] = 'inherit'; }
                            if (!is_scalar($slotValues['template'])) { $slotValues['template'] = ''; }
                            if (!is_array($slotValues['fallbacks'])) { $slotValues['fallbacks'] = []; }
                        @endphp
                        <details class="configuration-slot" @if(in_array($slotName,['title','description','h1','meta_keywords']) || isset($submittedPage['slots'][$slotName])) open @endif x-data="{ slot: @js($slotName), mode: @js($slotValues['mode']), expression: @js($slotValues['template']) }">
                            <summary>{{ $slotLabels[$slotName] ?? 'Tiêu đề H2 · '.substr($slotName,3) }} <span class="badge" x-text="{ on: 'Bật', inherit: 'Không đặt', off: 'Tắt' }[mode]"></span><small>{{ $slotName }}</small></summary>
                            <div class="formrow"><label>Trạng thái {{ $slotLabels[$slotName] ?? $slotName }}<select name="bundle_form[page_templates][{{ $viewKey }}][slots][{{ $slotName }}][mode]" x-model="mode"><option value="inherit" @selected($slotValues['mode'] === 'inherit')>Không đặt trong template</option><option value="on" @selected($slotValues['mode'] === 'on')>Bật bằng nội dung này</option><option value="off" @selected($slotValues['mode'] === 'off')>Tắt slot</option></select></label></div>
                            @include('admin.partials.configuration-slot',['fieldPrefix'=>'bundle_form[page_templates]['.$viewKey.'][slots]['.$slotName.']','slotLabel'=>$slotLabels[$slotName] ?? $slotName,'tokenLabels'=>$tokenLabels,'seoPreview'=>true])
                        </details>
                    @endforeach
                </div>
                <details class="configuration-add-slot">
                    <summary>Thêm vị trí H2 cho {{ $viewLabel }}</summary>
                    <div class="formrow">
                        <label>Tên vị trí H2<input name="bundle_form[page_templates][{{ $viewKey }}][new_h2_name]" maxlength="70" pattern="[a-z0-9_-]+" placeholder="summary" value="{{ is_string($submittedPage['new_h2_name'] ?? null) ? $submittedPage['new_h2_name'] : '' }}"><small>Để trống để không thêm. Ví dụ summary tạo h2.summary.</small></label>
                        <label>Nội dung H2<textarea name="bundle_form[page_templates][{{ $viewKey }}][new_h2_template]" rows="3" maxlength="20000">{{ is_string($submittedPage['new_h2_template'] ?? null) ? $submittedPage['new_h2_template'] : '' }}</textarea></label>
                    </div>
                </details>
            </section>
        @endforeach
    </fieldset>
    @if($canEditTemplate)<div class="formfooter"><button name="intent" value="apply">Lưu & áp dụng</button><a class="button secondary" href="/admin/sites">Chọn bộ cho website →</a></div><details><summary>Nâng cao · chỉ lưu trong kho</summary><p>Lưu phiên bản để chỉnh tiếp, chưa gửi yêu cầu cập nhật website.</p><button class="secondary" name="intent" value="draft">Chỉ lưu phiên bản</button></details>@endif
</form>
@endsection
