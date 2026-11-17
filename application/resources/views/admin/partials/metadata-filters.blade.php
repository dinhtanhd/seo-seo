@php
    $filterValues = \App\Domain\Support\MetadataFilterForm::values($filterConfig ?? []);
    $filterLabels = ['genres' => 'Thể loại nội dung', 'kinds' => 'Loại nội dung', 'page_types' => 'Loại trang', 'intents' => 'Ý định tìm kiếm', 'tags' => 'Thẻ nội dung'];
    $filterExamples = ['genres' => "剧情\n悬疑", 'kinds' => "movie\nnovel", 'page_types' => "movie\ncategory", 'intents' => "review\nwatch", 'tags' => "华语\n经典"];
    $filterRestore = isset($formContext) && old('form_context') === $formContext;
@endphp
<fieldset class="metadata-filter-fields">
    <legend>{{ $filterTitle ?? 'Điều kiện sử dụng keyword' }}</legend>
    <p class="muted">Mỗi dòng một giá trị. Để trống để không giới hạn; các điều kiện có dữ liệu phải cùng khớp với trang.</p>
    <input type="hidden" name="{{ $filterField }}_present" value="1" @if(isset($filterDisabled)) :disabled="{{ $filterDisabled }}" @endif>
    <div class="formrow three">
        @foreach($filterLabels as $filterKey => $filterLabel)
            @php
                $filterInput = $filterRestore ? old($filterField.'.'.$filterKey, '') : $filterValues[$filterKey];
                $filterInput = is_array($filterInput) ? implode("\n", array_filter($filterInput, fn ($entry) => is_string($entry))) : (is_string($filterInput) ? $filterInput : '');
            @endphp
            <label>{{ $filterLabel }}
                <textarea name="{{ $filterField }}[{{ $filterKey }}]" rows="3" maxlength="22000" placeholder="{{ $filterExamples[$filterKey] }}" @if(isset($filterDisabled)) :disabled="{{ $filterDisabled }}" @endif>{{ $filterInput }}</textarea>
            </label>
        @endforeach
    </div>
</fieldset>
