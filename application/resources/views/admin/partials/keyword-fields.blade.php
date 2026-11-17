@php
    $creating = $creating ?? false;
    $restoreFields = old('form_context') === $formContext;
    $keywordField = function ($name, $default = '') use ($restoreFields, $keyword) {
        $saved = $keyword?->{$name} ?? $default;
        $value = $restoreFields ? old($name, $saved) : $saved;
        return is_scalar($value) || $value === null ? $value : $saved;
    };
    $roleLabels = ['primary' => 'Chính (primary)', 'secondary' => 'Phụ (secondary)', 'anchor' => 'Liên kết (anchor)'];
    $statusLabels = ['active' => 'Hoạt động', 'paused' => 'Tạm dừng', 'archived' => 'Lưu trữ'];
@endphp
<input type="hidden" name="form_context" value="{{ $formContext }}">
<div class="formrow">
    <label>Văn bản keyword<input name="original_text" value="{{ $keywordField('original_text') }}" maxlength="255" required></label>
    <label>Locale<input name="locale" value="{{ $keywordField('locale', 'zh-CN') }}" maxlength="16" required placeholder="zh-CN"></label>
</div>
@if($creating)
    <label>Vai trò<select name="role">@foreach($roleLabels as $role => $label)<option value="{{ $role }}" @selected($keywordField('role', 'primary') === $role)>{{ $label }}</option>@endforeach</select></label>
@else
    <p class="muted">Vai trò: {{ $roleLabels[$keyword->role] }}. Dùng thao tác đổi vai trò bên dưới để giữ ID và lịch sử cũ.</p>
@endif
<div class="formrow three">
    <label>Trọng số<input name="weight" type="number" value="{{ $keywordField('weight', 1) }}" min="1" required></label>
    <label>Thứ tự ưu tiên<input name="priority" type="number" value="{{ $keywordField('priority', 0) }}"></label>
    <label>Trạng thái<select name="status">@foreach($statusLabels as $state => $label)<option value="{{ $state }}" @selected($keywordField('status', 'active') === $state)>{{ $label }}</option>@endforeach</select></label>
</div>
<div class="formrow">
    <label>Giới hạn mặc định mỗi website<input name="default_max_active_pages" type="number" value="{{ $keywordField('default_max_active_pages') }}" min="0" placeholder="Trống = không giới hạn"><small>Giới hạn riêng của website được đặt tại Website & domain → Gắn kho keyword.</small></label>
    <label>URL đích cho anchor<input name="target_url" value="{{ $keywordField('target_url') }}" maxlength="2000" placeholder="https://… hoặc /duong-dan"><small>Bắt buộc khi vai trò là anchor.</small></label>
</div>
@include('admin.partials.metadata-filters', ['filterField' => 'eligibility_form', 'filterConfig' => $keyword ? \App\Domain\Support\Json::decode($keyword->eligibility_json) : [], 'formContext' => $formContext, 'filterTitle' => 'Điều kiện sử dụng keyword'])
