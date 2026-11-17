@php
    $templateValues = \App\Domain\Support\TemplateConfigurationForm::values($definition);
    $restoreTemplate = old('form_context') === $formContext;
    $submittedTemplate = $restoreTemplate && is_array(old('template_form')) ? old('template_form') : [];
    $slotLabels = ['title'=>'Tiêu đề SEO', 'description'=>'Mô tả SEO', 'h1'=>'Tiêu đề H1', 'intro'=>'Đoạn giới thiệu', 'meta_keywords'=>'Meta keywords', 'related_links'=>'Liên kết liên quan', 'managed_body'=>'Chèn link trong nội dung'];
@endphp
<input type="hidden" name="configuration_form_present" value="1"><input type="hidden" name="form_context" value="{{ $formContext }}">
<fieldset class="configuration-fields" @disabled(!$canEditTemplate)>
    <div class="formrow">
        <label>Tên template<input name="name" value="{{ $restoreTemplate && is_string(old('name')) ? old('name') : $templateName }}" maxlength="200" required></label>
        <label>Tên vùng nội dung được quản lý<input name="template_form[managed_body_zone]" value="{{ is_string($submittedTemplate['managed_body_zone'] ?? null) ? $submittedTemplate['managed_body_zone'] : $templateValues['managed_body_zone'] }}" maxlength="200" required><small>Tên vùng nội dung để chèn liên kết. Mặc định main-content.</small></label>
    </div>
    <p class="note">Không đặt trong template: bỏ cấu hình slot tại template này; quy tắc chèn vẫn có thể ghi đè. Tắt slot: vô hiệu hóa slot cho đến khi có quy tắc ghi đè.</p>
    <div class="configuration-slots">
    @foreach($templateValues['slots'] as $slotName => $slotValues)
        @php
            $submittedSlot = is_array($submittedTemplate['slots'][$slotName] ?? null) ? $submittedTemplate['slots'][$slotName] : [];
            $slotValues = array_replace($slotValues, $submittedSlot);
            if (!in_array($slotValues['mode'], ['on','off','inherit'], true)) { $slotValues['mode'] = 'inherit'; }
            if (!is_scalar($slotValues['template'])) { $slotValues['template'] = ''; }
        @endphp
        <details class="configuration-slot" @if(in_array($slotName,['title','description','h1']) || isset($submittedTemplate['slots'][$slotName])) open @endif x-data="{ slot: @js($slotName), mode: @js($slotValues['mode']), expression: @js($slotValues['template']) }">
            <summary>{{ $slotLabels[$slotName] ?? 'Tiêu đề H2 · '.substr($slotName,3) }} <span class="badge" x-text="{ on: 'Bật', inherit: 'Không đặt', off: 'Tắt' }[mode]"></span><small>{{ $slotName }}</small></summary>
            <div class="formrow"><label>Trạng thái {{ $slotLabels[$slotName] ?? $slotName }}<select name="template_form[slots][{{ $slotName }}][mode]" x-model="mode"><option value="inherit">Không đặt trong template</option><option value="on">Bật bằng nội dung này</option><option value="off">Tắt slot</option></select></label></div>
            @include('admin.partials.configuration-slot', ['fieldPrefix'=>'template_form[slots]['.$slotName.']','slotLabel'=>$slotLabels[$slotName] ?? $slotName])
        </details>
    @endforeach
    </div>
    <details class="configuration-add-slot">
        <summary>Thêm một vị trí H2</summary>
        <div class="formrow">
            <label>Tên vị trí H2<input name="new_h2_name" maxlength="70" pattern="[a-z0-9_-]+" placeholder="summary" value="{{ $restoreTemplate && is_string(old('new_h2_name')) ? old('new_h2_name') : '' }}"><small>Để trống để không thêm. Ví dụ summary tạo h2.summary.</small></label>
            <label>Nội dung H2<textarea name="new_h2_template" rows="3" maxlength="20000" placeholder="Tiêu đề mục nội dung">{{ $restoreTemplate && is_string(old('new_h2_template')) ? old('new_h2_template') : '' }}</textarea></label>
        </div>
    </details>
</fieldset>
@if($canEditTemplate)<div class="formfooter"><button name="intent" value="apply">Lưu & áp dụng cho website đang dùng</button><details><summary>Nâng cao</summary><button name="intent" value="draft" class="secondary">Chỉ lưu vào kho template</button></details></div>@endif
