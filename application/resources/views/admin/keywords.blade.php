@extends('admin.layout')
@section('title', 'Kho keyword')
@section('content')
@php
    $statusLabels = ['active' => 'Hoạt động', 'paused' => 'Tạm dừng', 'archived' => 'Lưu trữ'];
    $hasWritablePools = $writablePools->isNotEmpty();
    $keywordVersions = $keywords->getCollection()->mapWithKeys(fn ($keyword) => [(string) $keyword->id => $keyword->row_version])->all();
    $poolVersions = $writablePools->mapWithKeys(fn ($pool) => [(string) $pool->id => $pool->row_version])->all();
    $newPoolInput = old('form_context') === 'keyword-new' ? old('pool_id') : null;
    $newPoolId = is_scalar($newPoolInput) ? (string) $newPoolInput : (string) ($writablePools->first()?->id ?? '');
    $newGroupInput = old('form_context') === 'keyword-new' ? old('group_id') : null;
    $newGroupId = is_scalar($newGroupInput) ? (string) $newGroupInput : '';
    $pageHasWritableKeywords = $keywords->getCollection()->contains(fn ($keyword) => ($poolCanWrite[$keyword->pool_id] ?? false) && !$keyword->deleted_at && !$keyword->pool_deleted_at);
@endphp
<div x-data>
<div class="pagehead">
    <div><div class="eyebrow">CÀI ĐẶT DÙNG CHUNG / KEYWORD</div><h1>Kho keyword</h1><p>Quản lý keyword tập trung. Mỗi website dùng kho được gắn riêng; sửa hoặc xóa keyword giữ nguyên các bản live và lịch sử.</p></div>
    <div class="actions">
        @if($hasWritablePools)<button type="button" @click="document.getElementById('keyword-create').showModal()">Thêm keyword</button><button type="button" class="secondary" @click="document.getElementById('keyword-import').showModal()">Nhập keyword</button>@endif
        <a href="/admin/keywords/export" class="button secondary">Xuất CSV an toàn</a>
    </div>
</div>
<dialog class="crud-modal" id="keyword-create" aria-labelledby="keyword-create-title" @if(old('form_context') === 'keyword-new') x-init="$el.showModal()" @endif>
    <div class="crud-dialog-head"><h2 id="keyword-create-title">Thêm một keyword</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()" aria-label="Đóng cửa sổ thêm keyword">Đóng</button></div>
    <div class="crud-dialog-body">
    @if(old('form_context') === 'keyword-new' && $errors->any())<div class="errornotice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="post" action="/admin/keywords" x-data="{ pool: @js($newPoolId), group: @js($newGroupId), versions: @js($poolVersions) }">
        @csrf
        <fieldset @disabled(!$hasWritablePools) style="border:0;padding:0;margin:0">
            <input type="hidden" name="pool_row_version" :value="versions[pool] || ''">
            <div class="formrow">
                <label>Kho keyword<select name="pool_id" x-model="pool" @change="group = ''" required>@foreach($writablePools as $pool)<option value="{{ $pool->id }}">{{ $pool->name }} · #{{ $pool->id }}</option>@endforeach</select></label>
                <label>Nhóm (tùy chọn)<select name="group_id" x-model="group"><option value="">Chưa gắn nhóm</option>@foreach($groups as $group)<option value="{{ $group->id }}" x-show="pool === '{{ $group->pool_id }}'" :disabled="pool !== '{{ $group->pool_id }}'">{{ $group->name }} · #{{ $group->id }}</option>@endforeach</select></label>
            </div>
            @include('admin.partials.keyword-fields', ['keyword' => null, 'creating' => true, 'formContext' => 'keyword-new'])
            @if($hasWritablePools)<button>Tạo keyword</button>@else<p class="muted">Chỉ xem · bạn chưa có kho được phép thêm keyword.</p>@endif
            <p class="muted">Keyword trùng pool, vai trò, locale và văn bản chuẩn hóa được giữ cùng ID; không tạo bản ghi trùng hoặc ghi đè metadata cũ.</p>
        </fieldset>
    </form>
</div></dialog>
<section class="card" x-data="{ all: false }">
    <form method="get" class="toolbar">
        <label>Tìm keyword<input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Văn bản keyword…" maxlength="200"></label>
        <label>Kho<select name="pool_id"><option value="">Tất cả kho được truy cập</option>@foreach($pools as $pool)<option value="{{ $pool->id }}" @selected(($filters['pool_id'] ?? '') == $pool->id)>{{ $pool->name }}{{ $pool->deleted_at ? ' · Đã xóa' : '' }}</option>@endforeach</select></label>
        <label>Nhóm<select name="group_id"><option value="">Tất cả nhóm</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(($filters['group_id'] ?? '') == $group->id)>{{ $group->name }} · kho #{{ $group->pool_id }}</option>@endforeach</select></label>
        <label>Vai trò<select name="role"><option value="">Tất cả</option>@foreach(['primary'=>'Chính','secondary'=>'Phụ','anchor'=>'Liên kết'] as $role=>$label)<option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>{{ $label }}</option>@endforeach</select></label>
        <label>Trạng thái<select name="status"><option value="">Tất cả</option>@foreach($statusLabels as $state=>$label)<option value="{{ $state }}" @selected(($filters['status'] ?? '') === $state)>{{ $label }}</option>@endforeach</select></label>
        <label>Hiển thị<select name="deleted">@foreach(['active'=>'Chưa xóa','trash'=>'Thùng rác','all'=>'Cả dữ liệu đã xóa'] as $state=>$label)<option value="{{ $state }}" @selected(($filters['deleted'] ?? 'active') === $state)>{{ $label }}</option>@endforeach</select></label>
        <label>Mỗi trang<select name="per_page">@foreach([25,50,100] as $count)<option value="{{ $count }}" @selected((int)($filters['per_page'] ?? 25) === $count)>{{ $count }}</option>@endforeach</select></label>
        <button>Lọc kho</button>
    </form>
    <p class="muted">{{ $keywords->total() }} keyword khớp bộ lọc. Số trang live và claim được cộng trên các website bạn được phép xem; giới hạn mặc định áp dụng riêng cho mỗi website.</p>
    <div class="tablewrap"><table>
        <thead><tr><th><input type="checkbox" @change="all = $event.target.checked" @disabled(!$pageHasWritableKeywords) aria-label="Chọn keyword có quyền sửa trong trang hiện tại"></th><th>Keyword / vai trò</th><th>Kho</th><th>Sử dụng / giới hạn</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>@forelse($keywords as $keyword)
        @php
            $keywordCanEdit = ($poolCanWrite[$keyword->pool_id] ?? false) && !$keyword->deleted_at && !$keyword->pool_deleted_at;
            $formContext = 'keyword-'.$keyword->id;
            $roleContext = 'keyword-role-'.$keyword->id;
        @endphp
        <tr id="keyword-{{ $keyword->id }}">
            <td><input type="checkbox" form="keyword-bulk" name="keyword_ids[]" value="{{ $keyword->id }}" :checked="{{ $keywordCanEdit ? 'all' : 'false' }}" @disabled(!$keywordCanEdit) aria-label="Chọn {{ $keyword->original_text }}"></td>
            <td><strong>{{ $keyword->original_text }}</strong><br><small>{{ $keyword->role }} · {{ $keyword->locale }} · #{{ $keyword->id }}</small></td>
            <td>{{ $keyword->pool_name }}<br><small>Kho #{{ $keyword->pool_id }}</small></td>
            <td><a href="/admin/keywords/{{ $keyword->id }}/usage">{{ $usage[$keyword->id] ?? 0 }} trang live</a><br><small>{{ $held[$keyword->id] ?? 0 }} claim held · Giới hạn mỗi website: {{ $keyword->default_max_active_pages ?? '∞' }}</small></td>
            <td><span class="badge {{ $keyword->status }}">{{ $statusLabels[$keyword->status] }}</span>@if($keyword->deleted_at)<br><span class="badge archived">Đã xóa keyword</span>@elseif($keyword->pool_deleted_at)<br><span class="badge archived">Kho đã xóa</span>@endif</td>
            <td>
                <div class="crud-row-actions">
                <button type="button" class="secondary" @click="document.getElementById('keyword-edit-{{ $keyword->id }}').showModal()">{{ $keywordCanEdit ? 'Sửa' : 'Chi tiết' }}</button>
                <dialog class="crud-modal" id="keyword-edit-{{ $keyword->id }}" aria-labelledby="keyword-edit-title-{{ $keyword->id }}" @if(in_array(old('form_context'), [$formContext, $roleContext], true)) x-init="$el.showModal()" @endif>
                    <div class="crud-dialog-head"><h2 id="keyword-edit-title-{{ $keyword->id }}">{{ $keywordCanEdit ? 'Chỉnh sửa keyword' : 'Chi tiết · Chỉ xem' }} · #{{ $keyword->id }}</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div>
                    <div class="crud-dialog-body">
                    @if(in_array(old('form_context'), [$formContext, $roleContext], true) && $errors->any())<div class="errornotice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                    <form method="post" action="/admin/keywords/{{ $keyword->id }}" style="min-width:280px">
                        @csrf
                        <fieldset @disabled(!$keywordCanEdit) style="border:0;padding:0;margin:0">
                            <input type="hidden" name="row_version" value="{{ old('form_context') === $formContext && is_scalar(old('row_version')) ? old('row_version') : $keyword->row_version }}">
                            @include('admin.partials.keyword-fields', ['keyword' => $keyword, 'creating' => false, 'formContext' => $formContext])
                            @if($keywordCanEdit)<button class="secondary">Lưu keyword</button>@else<p class="muted">Chỉ xem · không thể sửa keyword đã xóa hoặc kho không có quyền ghi.</p>@endif
                        </fieldset>
                    </form>
                    @if($keywordCanEdit && $keyword->pool_status !== 'archived')
                        <details @if(old('form_context') === $roleContext) open @endif><summary>Đổi vai trò và giữ lịch sử</summary><form method="post" action="/admin/keywords/{{ $keyword->id }}/change-role">@csrf<input type="hidden" name="form_context" value="{{ $roleContext }}"><input type="hidden" name="row_version" value="{{ old('form_context') === $roleContext && is_scalar(old('row_version')) ? old('row_version') : $keyword->row_version }}"><label>Vai trò mới<select name="role">@foreach(['primary','secondary','anchor'] as $role)@if($role !== $keyword->role)<option value="{{ $role }}" @selected(old('form_context') === $roleContext && old('role') === $role)>{{ $role }}</option>@endif @endforeach</select></label><label>URL đích nếu chọn anchor<input name="target_url" value="{{ old('form_context') === $roleContext && is_string(old('target_url')) ? old('target_url') : $keyword->target_url }}" maxlength="2000"></label><p class="muted">ID cũ được lưu trữ; ID đích giữ hoặc nhận các nhóm cũ. Các bản live chưa đổi.</p><button class="secondary">Tạo keyword vai trò mới</button></form></details>
                    @endif
                </div></dialog>
                @if(($poolCanWrite[$keyword->pool_id] ?? false) && !$keyword->pool_deleted_at)
                    @if($keyword->deleted_at)
                        <form method="post" action="/admin/keywords/{{ $keyword->id }}/restore">@csrf<input type="hidden" name="row_version" value="{{ $keyword->row_version }}"><button class="secondary">Khôi phục keyword · tạm dừng</button></form>
                    @else
                        <button type="button" class="danger" @click="document.getElementById('keyword-delete-{{ $keyword->id }}').showModal()">Xóa</button>
                        <dialog class="crud-modal" id="keyword-delete-{{ $keyword->id }}" aria-labelledby="keyword-delete-title-{{ $keyword->id }}"><div class="crud-dialog-head"><h2 id="keyword-delete-title-{{ $keyword->id }}">Xóa keyword</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div><div class="crud-dialog-body"><p><strong>{{ $keyword->original_text }}</strong></p><p class="muted">Chuyển vào thùng rác, giữ ID, thành viên nhóm và các bản live/lịch sử. Có thể khôi phục sau.</p><form method="post" action="/admin/keywords/{{ $keyword->id }}/delete">@csrf<input type="hidden" name="row_version" value="{{ $keyword->row_version }}"><button class="danger">Xác nhận xóa keyword</button></form></div></dialog>
                    @endif
                @elseif($keyword->pool_deleted_at)
                    <p class="muted">Khôi phục kho trước tại <a href="/admin/pools?deleted=trash">Pool & nhóm</a>.</p>
                @endif
                </div>
            </td>
        </tr>
        @empty<tr><td class="empty" colspan="6">Không có keyword khớp bộ lọc.</td></tr>@endforelse</tbody>
    </table></div>
    <div class="formfooter"><button type="button" class="secondary" @disabled(!$pageHasWritableKeywords) @click="document.getElementById('keyword-bulk-dialog').showModal()">Sửa các keyword đã chọn</button></div>
    <dialog class="crud-modal" id="keyword-bulk-dialog" aria-labelledby="keyword-bulk-title"><div class="crud-dialog-head"><h2 id="keyword-bulk-title">Cập nhật hàng loạt các keyword đã chọn</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div><div class="crud-dialog-body">
        <form id="keyword-bulk" method="post" action="/admin/keywords/bulk">@csrf
            <input type="hidden" name="keyword_versions_json" value="{{ json_encode($keywordVersions, JSON_UNESCAPED_UNICODE) }}">
            <fieldset @disabled(!$hasWritablePools) style="border:0;padding:0;margin:0">
                <div class="formrow three">
                    <label>Trạng thái mới<select name="status"><option value="">Giữ nguyên</option>@foreach($statusLabels as $state=>$label)<option value="{{ $state }}">{{ $label }}</option>@endforeach</select></label>
                    <label>Trọng số mới (trống = giữ)<input type="number" name="weight" min="1"></label>
                    <label>Thêm vào nhóm cùng kho<select name="group_id"><option value="">Giữ nguyên</option>@foreach($groups as $group)@if(($poolCanWrite[$group->pool_id] ?? false) && !$pools->firstWhere('id',$group->pool_id)?->deleted_at)<option value="{{ $group->id }}">{{ $group->name }} · kho #{{ $group->pool_id }}</option>@endif @endforeach</select></label>
                </div>
                <div class="formrow"><label class="checkboxline"><input name="change_cap" value="1" type="checkbox">Đổi giới hạn mặc định mỗi website</label><label>Giới hạn mới (trống = không giới hạn)<input name="default_max_active_pages" type="number" min="0"></label></div>
                @if($hasWritablePools)<button>Lưu thay đổi hàng loạt</button>@else<p class="muted">Chỉ xem · không có kho được phép sửa.</p>@endif
                <p class="muted">Chỉ áp dụng các keyword đã chọn trên trang hiện tại. Phiên bản được kiểm tra trước khi lưu; các bản live giữ nguyên.</p>
            </fieldset>
        </form>
    </div></dialog>
    <div class="pagination">{{ $keywords->links() }}</div>
</section>
<dialog class="crud-modal" id="keyword-import" aria-labelledby="keyword-import-title" @if(old('form_context') === 'keyword-import') x-init="$el.showModal()" @endif>
    <div class="crud-dialog-head"><h2 id="keyword-import-title">Nhập keyword từ file hoặc danh sách</h2><button type="button" class="secondary" @click="$el.closest('dialog').close()">Đóng</button></div><div class="crud-dialog-body">
    @if(old('form_context') === 'keyword-import' && $errors->any())<div class="errornotice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form action="/admin/keywords/import" method="post" enctype="multipart/form-data">@csrf
        <input type="hidden" name="form_context" value="keyword-import"><input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <fieldset @disabled(!$hasWritablePools) style="border:0;padding:0;margin:0">
            <div class="formrow three">
                <label>Kho đích<select name="pool_id">@foreach($writablePools as $pool)<option value="{{ $pool->id }}" @selected(old('form_context') === 'keyword-import' && old('pool_id') == $pool->id)>{{ $pool->name }}</option>@endforeach</select></label>
                <label>Vai trò mặc định<select name="role">@foreach(['primary','secondary','anchor'] as $role)<option value="{{ $role }}" @selected(old('form_context') === 'keyword-import' && old('role') === $role)>{{ $role }}</option>@endforeach</select></label>
                <label>Locale mặc định<input name="locale" value="{{ old('form_context') === 'keyword-import' && is_string(old('locale')) ? old('locale') : 'zh-CN' }}" maxlength="16" required></label>
            </div>
            <label>Định dạng<select name="format">@foreach(['auto'=>'Tự nhận diện','txt'=>'TXT','csv'=>'CSV','json'=>'JSON'] as $format=>$label)<option value="{{ $format }}" @selected(old('form_context') === 'keyword-import' && old('format') === $format)>{{ $label }}</option>@endforeach</select></label>
            <div class="formrow"><label>Mỗi dòng một keyword<textarea name="text" rows="7" placeholder="高清电影&#10;经典电影">{{ old('form_context') === 'keyword-import' && is_string(old('text')) ? old('text') : '' }}</textarea></label><label>Hoặc file TXT/CSV/JSON<input type="file" name="file" accept=".txt,.csv,.json,text/plain,text/csv,application/json"><small>UTF-8; báo riêng các dòng mới, trùng và lỗi. Kiểm tra dữ liệu trước khi xác nhận import.</small></label></div>
            @if($hasWritablePools)<div class="actions"><button class="secondary" name="dry_run" value="1">Kiểm tra trước khi nhập</button><button name="dry_run" value="0">Nhập vào kho</button></div>@else<p class="muted">Chỉ xem · không có kho được phép nhập keyword.</p>@endif
        </fieldset>
    </form>
</div></dialog>
<details class="card">
    <summary>Import jobs gần đây</summary>
    <div class="tablewrap"><table><thead><tr><th>Run</th><th>Kho / chế độ</th><th>Trạng thái</th><th>Kết quả</th></tr></thead><tbody>@forelse($imports as $run)
    @php
        $runCounts = \App\Domain\Support\Json::decode($run->counts);
    @endphp
    <tr><td><a href="/admin/keyword-imports/{{ $run->id }}">#{{ $run->id }}</a></td><td>Kho #{{ $run->pool_id }} · {{ $run->dry_run ? 'Kiểm tra' : 'Import' }}</td><td><span class="badge {{ $run->status }}">{{ $run->status }}</span></td><td>{{ $runCounts['new'] ?? '—' }} mới · {{ $runCounts['restored'] ?? '—' }} khôi phục · {{ $runCounts['duplicate'] ?? '—' }} trùng · {{ $runCounts['error'] ?? '—' }} lỗi</td></tr>
    @empty<tr><td class="empty" colspan="4">Chưa có keyword import job.</td></tr>@endforelse</tbody></table></div>
</details>
</div>
@endsection
