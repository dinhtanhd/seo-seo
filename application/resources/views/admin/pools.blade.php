@extends('admin.layout')
@section('title', 'Pool & nhóm')
@section('content')
@php
    $statusLabels = ['active' => 'Hoạt động', 'paused' => 'Tạm dừng', 'archived' => 'Lưu trữ'];
    $poolVersions = $writablePools->mapWithKeys(fn ($pool) => [(string) $pool->id => $pool->row_version])->all();
    $newGroupPool = old('form_context') === 'group-new' && is_scalar(old('pool_id')) ? (string) old('pool_id') : (string) ($writablePools->first()?->id ?? '');
@endphp
<div x-data="{ tab: @js(request('tab') === 'groups' || (is_string(old('form_context')) && str_starts_with(old('form_context'), 'group-')) ? 'groups' : 'pools') }" x-init="if (window.location.hash.startsWith('#group')) tab = 'groups'" @hashchange.window="if (window.location.hash.startsWith('#group')) tab = 'groups'; else if (window.location.hash.startsWith('#pool')) tab = 'pools'">
<div class="pagehead"><div><div class="eyebrow">CÀI ĐẶT DÙNG CHUNG / KHO & NHÓM</div><h1>Pool & nhóm</h1><p>Kho và nhóm được quản lý tập trung. Gắn kho, đặt bộ lọc và giới hạn riêng tại trang chỉnh sửa của từng website.</p></div><div class="actions">@if($canCreatePool)<button type="button" @click="document.getElementById('pool-create-dialog').showModal()">Thêm kho</button>@endif @if($writablePools->isNotEmpty())<button type="button" class="secondary" @click="document.getElementById('group-create-dialog').showModal()">Thêm nhóm</button>@endif<a href="/admin/sites" class="button secondary">Website & domain</a></div></div>
<section class="card">
    <form method="get" class="toolbar">
        <input type="hidden" name="tab" :value="tab">
        <label>Tìm kho hoặc nhóm<input name="q" value="{{ $filters['q'] ?? '' }}" maxlength="200" placeholder="Tên kho hoặc nhóm…"></label>
        <label>Trạng thái<select name="status"><option value="">Tất cả</option>@foreach($statusLabels as $state=>$label)<option value="{{ $state }}" @selected(($filters['status'] ?? '') === $state)>{{ $label }}</option>@endforeach</select></label>
        <label>Hiển thị<select name="deleted">@foreach(['active'=>'Chưa xóa','trash'=>'Thùng rác','all'=>'Cả dữ liệu đã xóa'] as $state=>$label)<option value="{{ $state }}" @selected(($filters['deleted'] ?? 'active') === $state)>{{ $label }}</option>@endforeach</select></label>
        <button>Lọc kho & nhóm</button>
    </form>
    <p class="muted">{{ $pools->count() }} kho và {{ $groups->count() }} nhóm khớp bộ lọc. Xóa mềm giữ ID, keyword, thành viên nhóm, binding và lịch sử.</p>
</section>
<nav class="management-tabs" role="tablist" aria-label="Chọn danh sách kho hoặc nhóm"><button type="button" role="tab" id="pool-list-tab" aria-controls="pool-list-panel" :aria-selected="tab === 'pools'" :class="{ active: tab === 'pools' }" @click="tab = 'pools'; window.location.hash = 'pools'">Kho keyword · {{ $pools->count() }}</button><button type="button" role="tab" id="group-list-tab" aria-controls="group-list-panel" :aria-selected="tab === 'groups'" :class="{ active: tab === 'groups' }" @click="tab = 'groups'; window.location.hash = 'groups'">Nhóm keyword · {{ $groups->count() }}</button></nav>
    <dialog class="crud-modal" id="pool-create-dialog" aria-labelledby="pool-create-title" @if(old('form_context') === 'pool-new') x-init="$el.showModal()" @endif>
        <div class="crud-dialog-head"><h2 id="pool-create-title">Tạo kho keyword dùng chung</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div><div class="crud-dialog-body">
        @if(old('form_context') === 'pool-new' && $errors->any())<div class="errornotice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="post" action="/admin/pools">@csrf<input type="hidden" name="form_context" value="pool-new">
            <fieldset @disabled(!$canCreatePool) style="border:0;padding:0;margin:0">
                <div class="formrow"><label>Tên kho<input name="name" maxlength="200" value="{{ old('form_context') === 'pool-new' && is_string(old('name')) ? old('name') : '' }}" required></label><label>Locale<input name="locale" maxlength="16" value="{{ old('form_context') === 'pool-new' && is_string(old('locale')) ? old('locale') : 'zh-CN' }}" required></label></div>
                <label>Trạng thái<select name="status">@foreach($statusLabels as $state=>$label)<option value="{{ $state }}" @selected((old('form_context') === 'pool-new' ? old('status','active') : 'active') === $state)>{{ $label }}</option>@endforeach</select></label>
                <p class="muted">Kho mới dùng chung; chọn website sử dụng kho tại Website & domain → Gắn kho keyword.</p>
                @if($canCreatePool)<button>Tạo kho</button>@else<p class="muted">Chỉ xem · tạo kho dùng chung cần quyền quản trị toàn hệ thống.</p>@endif
            </fieldset>
        </form>
    </div></dialog>
    <dialog class="crud-modal" id="group-create-dialog" aria-labelledby="group-create-title" @if(old('form_context') === 'group-new') x-init="$el.showModal()" @endif>
        <div class="crud-dialog-head"><h2 id="group-create-title">Tạo nhóm keyword</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div><div class="crud-dialog-body">
        @if(old('form_context') === 'group-new' && $errors->any())<div class="errornotice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="post" action="/admin/groups" x-data="{ pool: @js($newGroupPool), versions: @js($poolVersions) }">@csrf<input type="hidden" name="form_context" value="group-new">
            <fieldset @disabled($writablePools->isEmpty()) style="border:0;padding:0;margin:0">
                <input type="hidden" name="pool_row_version" :value="versions[pool] || ''">
                <div class="formrow"><label>Kho chứa nhóm<select name="pool_id" x-model="pool" required>@foreach($writablePools as $pool)<option value="{{ $pool->id }}">{{ $pool->name }} · #{{ $pool->id }}</option>@endforeach</select></label><label>Tên nhóm<input name="name" maxlength="200" value="{{ old('form_context') === 'group-new' && is_string(old('name')) ? old('name') : '' }}" required></label></div>
                <label>Trạng thái<select name="status">@foreach($statusLabels as $state=>$label)<option value="{{ $state }}" @selected((old('form_context') === 'group-new' ? old('status','active') : 'active') === $state)>{{ $label }}</option>@endforeach</select></label>
                <p class="muted">Nhóm mới bắt đầu trống. Thêm keyword ở phần quản lý thành viên hoặc từ Kho keyword.</p>
                @if($writablePools->isNotEmpty())<button>Tạo nhóm</button>@else<p class="muted">Chỉ xem · chưa có kho được phép tạo nhóm.</p>@endif
            </fieldset>
        </form>
    </div></dialog>
<section class="card" id="pool-list-panel" role="tabpanel" aria-labelledby="pool-list-tab" x-show="tab === 'pools'">
    <h2>Các kho keyword</h2>
    <div class="tablewrap"><table><thead><tr><th>Kho / locale</th><th>Keyword</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>@forelse($pools as $pool)
    @php
        $poolCanEdit = $poolCanWrite[$pool->id] ?? false;
        $poolContext = 'pool-'.$pool->id;
        $poolRestore = old('form_context') === $poolContext;
    @endphp
    <tr id="pool-{{ $pool->id }}"><td><strong>{{ $pool->name }}</strong><br><small>{{ $pool->locale }} · #{{ $pool->id }}</small></td><td><a href="/admin/keywords?pool_id={{ $pool->id }}{{ $pool->deleted_at ? '&deleted=all' : '' }}">{{ $keywordCounts[$pool->id] ?? 0 }} keyword chưa xóa</a></td><td><span class="badge {{ $pool->status }}">{{ $statusLabels[$pool->status] }}</span>@if($pool->deleted_at)<br><span class="badge archived">Đã xóa kho</span>@endif</td><td>
        <div class="crud-row-actions"><button type="button" class="secondary" @click="document.getElementById('pool-edit-{{ $pool->id }}').showModal()">{{ $poolCanEdit && !$pool->deleted_at ? 'Sửa' : 'Chi tiết' }}</button>
        <dialog class="crud-modal" id="pool-edit-{{ $pool->id }}" aria-labelledby="pool-edit-title-{{ $pool->id }}" @if($poolRestore) x-init="$el.showModal()" @endif><div class="crud-dialog-head"><h2 id="pool-edit-title-{{ $pool->id }}">{{ $poolCanEdit && !$pool->deleted_at ? 'Chỉnh sửa kho' : 'Chi tiết · Chỉ xem' }} · #{{ $pool->id }}</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div><div class="crud-dialog-body">
            @if($poolRestore && $errors->any())<div class="errornotice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <form method="post" action="/admin/pools/{{ $pool->id }}">@csrf<input type="hidden" name="form_context" value="{{ $poolContext }}">
                <fieldset @disabled(!$poolCanEdit || $pool->deleted_at) style="border:0;padding:0;margin:0">
                    <input type="hidden" name="row_version" value="{{ $poolRestore && is_scalar(old('row_version')) ? old('row_version') : $pool->row_version }}">
                    <div class="formrow"><label>Tên kho<input name="name" maxlength="200" value="{{ $poolRestore && is_string(old('name')) ? old('name') : $pool->name }}" required></label><label>Locale<input name="locale" maxlength="16" value="{{ $poolRestore && is_string(old('locale')) ? old('locale') : $pool->locale }}" required></label></div>
                    <label>Trạng thái<select name="status">@foreach($statusLabels as $state=>$label)<option value="{{ $state }}" @selected(($poolRestore ? old('status',$pool->status) : $pool->status) === $state)>{{ $label }}</option>@endforeach</select></label>
                    @if($poolCanEdit && !$pool->deleted_at)<button class="secondary">Lưu kho</button>@else<p class="muted">Chỉ xem · kho đã xóa hoặc không có quyền ghi.</p>@endif
                </fieldset>
            </form>
        </div></dialog>
        @if($poolCanEdit)
            @if($pool->deleted_at)<form method="post" action="/admin/pools/{{ $pool->id }}/restore">@csrf<input type="hidden" name="row_version" value="{{ $pool->row_version }}"><button class="secondary">Khôi phục kho · tạm dừng</button></form>
            @else<button type="button" class="danger" @click="document.getElementById('pool-delete-{{ $pool->id }}').showModal()">Xóa</button><dialog class="crud-modal" id="pool-delete-{{ $pool->id }}" aria-labelledby="pool-delete-title-{{ $pool->id }}"><div class="crud-dialog-head"><h2 id="pool-delete-title-{{ $pool->id }}">Xóa kho</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div><div class="crud-dialog-body"><p><strong>{{ $pool->name }}</strong></p><p class="muted">Ngừng cấp mới từ kho và chuyển vào thùng rác. Keyword, nhóm, binding và các bản live/lịch sử được giữ.</p><form method="post" action="/admin/pools/{{ $pool->id }}/delete">@csrf<input type="hidden" name="row_version" value="{{ $pool->row_version }}"><button class="danger">Xác nhận xóa kho</button></form></div></dialog>@endif
        @endif
        </div>
    </td></tr>@empty<tr><td colspan="4" class="empty">Không có kho khớp bộ lọc.</td></tr>@endforelse</tbody></table></div>
</section>
<section class="card" id="group-list-panel" role="tabpanel" aria-labelledby="group-list-tab" x-show="tab === 'groups'" x-cloak>
    <h2>Nhóm & thành viên keyword</h2>
    <div class="tablewrap"><table><thead><tr><th>Nhóm keyword</th><th>Kho</th><th>Thành viên</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>@forelse($groups as $group)
    @php
        $groupPool = $allPools->firstWhere('id', $group->pool_id);
        $groupCanEdit = ($poolCanWrite[$group->pool_id] ?? false) && !$groupPool->deleted_at;
        $groupContext = 'group-'.$group->id;
        $groupRestore = old('form_context') === $groupContext;
    @endphp
    <tr id="group-{{ $group->id }}"><td><strong>{{ $group->name }}</strong><br><small>#{{ $group->id }}</small></td><td>{{ $groupPool->name }}</td><td>{{ ($groupKeywords[$group->id] ?? collect())->count() }} keyword</td><td><span class="badge {{ $group->status }}">{{ $statusLabels[$group->status] }}</span>@if($group->deleted_at)<br><span class="badge archived">Đã xóa nhóm</span>@elseif($groupPool->deleted_at)<br><span class="badge archived">Kho đã xóa</span>@endif</td><td><div class="crud-row-actions"><button type="button" class="secondary" @click="document.getElementById('group-edit-{{ $group->id }}').showModal()">{{ $groupCanEdit && !$group->deleted_at ? 'Sửa & thành viên' : 'Chi tiết' }}</button>
    <dialog class="crud-modal" id="group-edit-{{ $group->id }}" aria-labelledby="group-edit-title-{{ $group->id }}" @if($groupRestore) x-init="$el.showModal()" @endif><div class="crud-dialog-head"><h2 id="group-edit-title-{{ $group->id }}">{{ $group->name }} · #{{ $group->id }}</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div><div class="crud-dialog-body">
        @if($groupRestore && $errors->any())<div class="errornotice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="post" action="/admin/groups/{{ $group->id }}">@csrf<input type="hidden" name="form_context" value="{{ $groupContext }}">
            <fieldset @disabled(!$groupCanEdit || $group->deleted_at) style="border:0;padding:0;margin:0">
                <input type="hidden" name="row_version" value="{{ $groupRestore && is_scalar(old('row_version')) ? old('row_version') : $group->row_version }}">
                <div class="formrow"><label>Tên nhóm<input name="name" maxlength="200" value="{{ $groupRestore && is_string(old('name')) ? old('name') : $group->name }}" required></label><label>Trạng thái<select name="status">@foreach($statusLabels as $state=>$label)<option value="{{ $state }}" @selected(($groupRestore ? old('status',$group->status) : $group->status) === $state)>{{ $label }}</option>@endforeach</select></label></div>
                @include('admin.partials.group-members', ['memberRows' => $groupKeywords[$group->id] ?? collect(), 'memberChoices' => $initialKeywordOptions[$group->pool_id] ?? collect(), 'memberPoolId' => $group->pool_id, 'memberContext' => $groupContext])
                @if($groupCanEdit && !$group->deleted_at)<button class="secondary">Lưu nhóm & thành viên</button>@else<p class="muted">Chỉ xem · nhóm hoặc kho đã xóa, hoặc bạn không có quyền ghi.</p>@endif
            </fieldset>
        </form>
    </div></dialog>
        @if($groupCanEdit)
            @if($group->deleted_at)<form method="post" action="/admin/groups/{{ $group->id }}/restore">@csrf<input type="hidden" name="row_version" value="{{ $group->row_version }}"><button class="secondary">Khôi phục nhóm · tạm dừng</button></form>
            @else<button type="button" class="danger" @click="document.getElementById('group-delete-{{ $group->id }}').showModal()">Xóa</button><dialog class="crud-modal" id="group-delete-{{ $group->id }}" aria-labelledby="group-delete-title-{{ $group->id }}"><div class="crud-dialog-head"><h2 id="group-delete-title-{{ $group->id }}">Xóa nhóm</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div><div class="crud-dialog-body"><p><strong>{{ $group->name }}</strong></p><p class="muted">Thành viên và binding được giữ để khôi phục; các bản live/lịch sử không bị sửa.</p><form method="post" action="/admin/groups/{{ $group->id }}/delete">@csrf<input type="hidden" name="row_version" value="{{ $group->row_version }}"><button class="danger">Xác nhận xóa nhóm</button></form></div></dialog>@endif
        @elseif($groupPool->deleted_at)
            <p class="muted">Khôi phục kho trước khi chỉnh sửa hoặc khôi phục nhóm.</p>
        @endif
    </div></td></tr>
    @empty<tr><td colspan="5" class="empty">Không có nhóm khớp bộ lọc.</td></tr>@endforelse</tbody></table></div>
</section>
</div>
@endsection
