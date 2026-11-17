@php
    $tokenLabels = $tokenLabels ?? ['content.title' => 'Tên nội dung', 'content.genre' => 'Thể loại', 'content.author' => 'Tác giả', 'content.year' => 'Năm phát hành', 'content.area' => 'Quốc gia / khu vực', 'content.director' => 'Đạo diễn', 'content.actors' => 'Diễn viên', 'content.language' => 'Ngôn ngữ', 'kw.primary' => 'Keyword chính', 'kw.secondary' => 'Keyword phụ', 'site.name' => 'Tên hiển thị trên website'];
    $customOptionCache = request()->attributes->get('template_variable_ui_options');
    if ($customOptionCache === null) {
        $customOptionService = app(\App\Domain\Rules\TemplateVariableService::class);
        $customOptionCache = method_exists($customOptionService,'ready') && $customOptionService->ready() ? $customOptionService->variables(true) : collect();
        request()->attributes->set('template_variable_ui_options',$customOptionCache);
    }
    $tokenLabels += $customOptionCache->mapWithKeys(fn ($variable) => ['var.'.$variable->slug => $variable->name])->all();
    $customVariableSamples = ($customVariableSamples ?? []) + $customOptionCache->mapWithKeys(fn ($variable) => ['var.'.$variable->slug => $variable->values[0] ?? $variable->fallback ?? ''])->all();
    foreach (['template','max_anchors','per_keyword'] as $displayField) {
        if (isset($slotValues[$displayField]) && !is_scalar($slotValues[$displayField])) { $slotValues[$displayField] = ''; }
    }
    foreach ($tokenLabels as $token => $label) {
        if (isset($slotValues['fallbacks'][$token]) && !is_scalar($slotValues['fallbacks'][$token])) { $slotValues['fallbacks'][$token] = ''; }
    }
@endphp
<div x-show="mode === 'on' && !slot.startsWith('allocation.')" x-cloak>
    <div x-show="!['managed_body', 'related_links'].includes(slot)">
        <label>Nội dung {{ $slotLabel ?? 'slot' }}
            <textarea name="{{ $fieldPrefix }}[template]" rows="3" maxlength="20000" x-model="expression" :disabled="mode !== 'on' || ['managed_body', 'related_links'].includes(slot) || slot.startsWith('allocation.')">{{ $slotValues['template'] ?? '' }}</textarea>
        </label>
        <p class="muted">Điền nội dung và biến bên dưới. Intro có thể dùng HTML an toàn; các slot còn lại là văn bản.</p>
        <div class="configuration-tokens">
            @foreach($tokenLabels as $token => $label)
                @php $tokenText = '{'.'{'.$token.'}'.'}'; @endphp
                <button type="button" class="secondary" @click="expression += @js($tokenText)" :disabled="mode !== 'on'" title="{{ $label }}">{{ $tokenText }}</button>
            @endforeach
        </div>
        @if($seoPreview ?? false)
            @php
                $sampleValues = array_intersect_key(['site.name'=>'xinghedianying', 'content.title'=>'流浪地球', 'content.genre'=>'科幻片', 'content.author'=>'作者示例', 'content.year'=>'2019', 'content.area'=>'中国大陆', 'content.director'=>'郭帆', 'content.actors'=>'吴京、屈楚萧', 'content.language'=>'汉语普通话', 'kw.primary'=>'科幻电影', 'kw.secondary'=>'电影推荐、剧情介绍', 'category.name'=>'科幻片', 'search.query'=>'流浪地球', 'episode.title'=>'正片', 'episode.index'=>'1'], $tokenLabels);
                $sampleValues += $customVariableSamples ?? [];
                $sampleExpression = preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', fn ($match) => $sampleValues[trim($match[1])] ?? '', (string) ($slotValues['template'] ?? ''));
            @endphp
            <div class="note" data-seo-example>
                <strong>Ví dụ minh họa · thay đổi theo công thức đang nhập</strong>
                <p x-text="expression.replace(/\{\{\s*([^{}]+?)\s*\}\}/gu, (match, token) => (@js($sampleValues))[token.trim()] ?? '')">{{ $sampleExpression }}</p>
                <small>Dữ liệu mẫu để xem cách ghép chữ; keyword thật lấy từ phân bổ đã lưu của từng trang.</small>
            </div>
        @endif
        <details class="configuration-fallbacks">
            <summary>Giá trị dự phòng khi thiếu dữ liệu</summary>
            <p class="muted">Để trống để giữ hành vi mặc định. Giá trị dự phòng chỉ dùng khi biến thiếu dữ liệu.</p>
            <div class="formrow three">
                @foreach($tokenLabels as $token => $label)
                    @continue(str_starts_with($token,'var.'))
                    <label>{{ $label }}
                        <input name="{{ $fieldPrefix }}[fallbacks][{{ $token }}]" value="{{ $slotValues['fallbacks'][$token] ?? '' }}" maxlength="20000" :disabled="mode !== 'on' || ['managed_body', 'related_links'].includes(slot) || slot.startsWith('allocation.')">
                    </label>
                @endforeach
            </div>
            @if($customOptionCache->isNotEmpty())<p class="muted">Giá trị dự phòng của biến tùy chỉnh được đặt tại Biến template.</p>@endif
        </details>
    </div>
    <div x-show="slot === 'managed_body'" class="formrow">
        <label>Tối đa liên kết chèn trong nội dung
            <input type="number" name="{{ $fieldPrefix }}[max_anchors]" value="{{ $slotValues['max_anchors'] ?? 3 }}" min="0" max="100" :disabled="mode !== 'on' || slot !== 'managed_body'">
        </label>
        <label>Tối đa lần chèn mỗi keyword
            <input type="number" name="{{ $fieldPrefix }}[per_keyword]" value="{{ $slotValues['per_keyword'] ?? 1 }}" min="0" max="100" :disabled="mode !== 'on' || slot !== 'managed_body'">
        </label>
    </div>
    <p class="note" x-show="slot === 'managed_body'">Chỉ chèn liên kết anchor đã cấp cho trang trong nội dung được quản lý. Giá trị 0 ngừng chèn liên kết.</p>
    <p class="note" x-show="slot === 'related_links'">Hiển thị các keyword anchor đã cấp có URL đích an toàn. Link trỏ về chính trang sẽ được bỏ qua.</p>
</div>
