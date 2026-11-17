@php
    $binding = $binding ?? null;
    $context = $binding ? 'site-pool-'.$binding->id : 'site-pool-create';
    $restore = old('form_context') === $context;
    $field = function ($name, $default = null) use ($restore) {
        $value = $restore ? old($name, $default) : $default;
        return is_scalar($value) || $value === null ? $value : $default;
    };
    $selectedPool = $binding?->pool_id ?? (int) $field('pool_id', $sitePools->first()?->id);
    $typeLabels = ['home'=>'Trang chủ','category'=>'Danh mục','movie'=>'Phim','novel'=>'Tiểu thuyết','episode'=>'Tập phim','chapter'=>'Chương','editorial'=>'Bài viết'];
    $statusLabels = ['active'=>'Đang cấp keyword','paused'=>'Tạm dừng cấp mới','archived'=>'Đã gỡ khỏi cấp mới'];
@endphp
<input type="hidden" name="form_context" value="{{ $context }}">
@if($binding)<input type="hidden" name="row_version" value="{{ $field('row_version', $binding->row_version) }}">@else
    <label>Kho keyword<select name="pool_id" x-model="pool" @change="$refs.group.value=''" required>@foreach($sitePools as $pool)<option value="{{ $pool->id }}" @selected((string)$selectedPool === (string)$pool->id)>{{ $pool->name }}{{ $pool->status !== 'active' ? ' · tạm dừng' : '' }}</option>@endforeach</select></label>
@endif
<div class="formrow">
    <label>Nhóm keyword<select name="group_id" x-ref="group"><option value="">Cả kho</option>
        @foreach($sitePoolGroups as $group)
            @if(!$binding || $group->pool_id === $binding->pool_id)<option value="{{ $group->id }}" @if(!$binding) x-show="pool === '{{ $group->pool_id }}'" :disabled="pool !== '{{ $group->pool_id }}'" @endif @selected((string)$field('group_id', $binding?->group_id) === (string)$group->id)>{{ $group->name }}{{ $group->status !== 'active' ? ' · tạm dừng' : '' }}</option>@endif
        @endforeach
        @if($binding?->group_id && !$sitePoolGroups->contains('id',$binding->group_id))<option value="{{ $binding->group_id }}" selected>{{ $binding->group_name }} · đã xóa</option>@endif
    </select></label>
    <label>Trạng thái<select name="status">@foreach($statusLabels as $key=>$label)<option value="{{ $key }}" @selected($field('status',$binding?->status ?? 'active') === $key)>{{ $label }}</option>@endforeach</select></label>
</div>
<div class="formrow three">
    <label>Kiểu trang<select name="page_type"><option value="">Mọi kiểu trang</option>@foreach($typeLabels as $key=>$label)<option value="{{ $key }}" @selected($field('page_type',$binding?->page_type) === $key)>{{ $label }}</option>@endforeach</select></label>
    <label>Danh mục<select name="category_id"><option value="">Mọi danh mục</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string)$field('category_id',$binding?->category_id) === (string)$category->id)>{{ $category->name }}</option>@endforeach</select></label>
    <label>Thứ tự ưu tiên<input type="number" name="priority" value="{{ $field('priority',$binding?->priority ?? 0) }}" required></label>
</div>
@include('admin.partials.metadata-filters', ['filterField'=>'filters_form','filterConfig'=>\App\Domain\Support\Json::decode($binding?->filters_json ?? '{}'),'formContext'=>$context,'filterTitle'=>'Điều kiện nội dung của website'])
