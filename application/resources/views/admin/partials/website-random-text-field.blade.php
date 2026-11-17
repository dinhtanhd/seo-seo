@php
    [$fieldLabel, $fieldHelp, $fieldPlaceholder] = $fieldInfo;
    $fieldEnabled = in_array($field, $selectedRandomFields, true);
    $defaultCandidates = is_array($randomDefaults[$field] ?? null) ? array_values(array_filter($randomDefaults[$field], 'is_string')) : [$fieldPlaceholder];
    $defaultCandidateText = implode("\n", $defaultCandidates);
    $fieldValue = is_string($oldRandomValues[$field] ?? null) ? $oldRandomValues[$field] : $defaultCandidateText;
@endphp
<div x-data="{ enabled: @js($fieldEnabled) }" @bulk-random-all.window="enabled = $event.detail.enabled === true">
    <label class="checkboxline"><input type="checkbox" name="random_fields[]" value="{{ $field }}" x-model="enabled" @checked($fieldEnabled)>Ngẫu nhiên {{ mb_strtolower($fieldLabel) }}</label>
    <small>{{ $fieldHelp }}</small>
    <div x-show="enabled" @if(!$fieldEnabled) x-cloak @endif>
        <label>{{ $fieldLabel }} có thể chọn<textarea name="random_values[{{ $field }}]" rows="4" maxlength="15000" spellcheck="false" placeholder="{{ $fieldPlaceholder }}" x-ref="values" @disabled(!$fieldEnabled) :disabled="!enabled">{{ $fieldValue }}</textarea></label>
        <div class="actions"><button type="button" class="secondary" @click="enabled = true; $refs.values.value = @js($defaultCandidateText); $refs.values.focus()">Điền gợi ý</button><small>Mỗi dòng một giá trị, tối đa 50 giá trị.</small></div>
    </div>
</div>
