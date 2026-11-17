@extends('admin.layout')
@section('title','Ngoại lệ nâng cao')
@section('content')
@php
    $editingRule = $rules->firstWhere('id', (int) request('rule_id'));
    $formContext = 'rule:'.($editingRule?->id ?? 'new');
    $restoreForm = old('form_context') === $formContext;
    $showRuleEditor = (bool) $editingRule || request()->boolean('create') || old('form_context') === 'rule:new';
    $ruleKind = $ruleKind ?? request('kind', 'all');
    $ruleKind = in_array($ruleKind, ['all', 'allocation', 'links', 'seo'], true) ? $ruleKind : 'all';
    $ruleListUrl = '/admin/rules?'.http_build_query(array_filter(['site_id' => $site?->id, 'status' => $ruleStatus, 'kind' => $ruleKind]));
    $ruleCreateUrl = '/admin/rules?'.http_build_query(array_filter(['site_id' => $site?->id, 'kind' => $ruleKind, 'create' => 1]));
    $currentConfig = $editingRule?->config_json === null ? null : json_decode($editingRule->config_json, true);
    $ruleSlot = $editingRule?->slot ?? match ($ruleKind) { 'allocation' => 'allocation.secondary', 'links' => 'managed_body', default => 'title' };
    $formValues = \App\Domain\Support\RuleConfigurationForm::values($ruleSlot, $currentConfig, $pools->pluck('id')->all());
    if (!$editingRule) { $formValues['mode'] = 'on'; }
    if ($restoreForm) {
        foreach (array_keys($formValues) as $key) { $formValues[$key] = old($key, $formValues[$key]); }
    }
    if (!in_array($formValues['mode'], ['on','off','inherit'], true)) { $formValues['mode'] = 'on'; }
    if (!is_array($formValues['rule_form'])) { $formValues['rule_form'] = \App\Domain\Support\RuleConfigurationForm::values($ruleSlot, $currentConfig)['rule_form']; }
    if (!is_scalar($formValues['rule_form']['template'] ?? '')) { $formValues['rule_form']['template'] = ''; }
    foreach (['pool_ids','fallback_pool_ids','keyword_ids'] as $field) { $formValues[$field] = is_array($formValues[$field]) ? array_values(array_filter($formValues[$field], fn($id) => is_scalar($id) && ctype_digit((string) $id))) : []; }
    if (!is_scalar($formValues['allocation_count'])) { $formValues['allocation_count'] = ''; }
    if (!is_string($formValues['allocation_mode'])) { $formValues['allocation_mode'] = 'weighted'; }
    $initial = $formValues + ['scopeType' => $editingRule?->scope_type ?? ($site ? 'site' : 'global'), 'slot' => $ruleSlot];
    if ($restoreForm && !$editingRule) { $initial['scopeType'] = is_string(old('scope_type')) ? old('scope_type') : $initial['scopeType']; $initial['slot'] = is_string(old('slot')) ? old('slot') : $ruleSlot; }
    $initial['isExisting'] = (bool) $editingRule;
    $oldPageId = old('page_id', 0);
    $initial['pageId'] = is_scalar($oldPageId) && ctype_digit((string) $oldPageId) ? (string) $oldPageId : '';
    $initial['pageChoices'] = $pages->map(fn ($page) => ['id' => (int) $page->id, 'canonical_path' => $page->canonical_path, 'page_type' => $page->page_type])->values()->all();
    if (!$editingRule && str_starts_with($initial['slot'], 'h2.') && $initial['slot'] !== 'h2.summary') { $initial['h2Name'] = substr($initial['slot'],3); $initial['slot'] = 'h2.custom'; }
    $canManageRule = $editingRule ? ($ruleCanWrite[$editingRule->id] ?? auth()->user()->is_global_admin) : ($canWriteRule ?? auth()->user()->is_global_admin);
    $canEditRule = $canManageRule && $editingRule?->status !== 'archived';
    $scopeLabels = ['global' => 'Chung · mọi domain', 'site' => 'Domain', 'page_type' => 'Loại trang', 'category' => 'Danh mục', 'page' => 'Một trang'];
    $slotLabels = ['title' => 'Tiêu đề SEO', 'description' => 'Mô tả SEO', 'h1' => 'Tiêu đề H1', 'h2.summary' => 'Tiêu đề H2 · summary', 'intro' => 'Đoạn giới thiệu', 'meta_keywords' => 'Meta keywords', 'related_links' => 'Liên kết liên quan', 'managed_body' => 'Chèn link trong nội dung', 'allocation.primary' => 'Cấp keyword chính', 'allocation.secondary' => 'Cấp keyword phụ', 'allocation.anchor' => 'Cấp keyword anchor'];
    $groupLabels = ['allocation' => 'Phân bổ keyword', 'links' => 'Liên kết trong nội dung', 'seo' => 'Ghi đè Template SEO'];
    $editingSummary = $editingRule ? ($ruleSummaries[$editingRule->id] ?? []) : [];
    $poolSettingsUrl = $site ? '/admin/sites/'.$site->id.'/edit?tab=pools' : '/admin/sites';
    $fanmuluSettingsUrl = $site ? '/admin/sites/'.$site->id.'/fanmulu' : '/admin/fanmulu';
    $orderedPools = $pools->sortBy(fn($pool) => array_search($pool->id, $formValues['pool_ids'], false) === false ? 100000 + $pool->id : array_search($pool->id, $formValues['pool_ids'], false));
    $fallbackPools = $pools->sortBy(fn($pool) => array_search($pool->id, $formValues['fallback_pool_ids'], false) === false ? 100000 + $pool->id : array_search($pool->id, $formValues['fallback_pool_ids'], false));
@endphp
<div class="pagehead"><div><div class="eyebrow">NÂNG CAO</div><h1>{{ $showRuleEditor ? ($editingRule ? 'Sửa ngoại lệ #'.$editingRule->id : 'Tạo ngoại lệ') : 'Ngoại lệ nâng cao' }}</h1><p>Dùng khi cần phân bổ keyword hoặc ghi đè riêng một phần cấu hình. Ngoại lệ riêng có thể ưu tiên hơn cấu hình chung.</p></div><div class="actions">@if($showRuleEditor)<a class="button secondary" href="{{ $ruleListUrl }}">← Danh sách ngoại lệ</a>@elseif($canWriteRule ?? auth()->user()->is_global_admin)<a class="button" href="{{ $ruleCreateUrl }}">Tạo ngoại lệ</a>@endif</div></div>
<div class="formrow three rule-shortcuts">
    <section class="card"><h3>Tiêu đề, mô tả &amp; keyword SEO</h3><p>Sửa bộ TKD và nội dung SEO dùng chung tại Template SEO.</p><a href="/admin/templates">Mở Template SEO →</a></section>
    <section class="card"><h3>Kho keyword của website</h3><p>Gắn kho và điều chỉnh giới hạn trong cấu hình domain.</p><a href="{{ $poolSettingsUrl }}">{{ $site ? 'Mở kho keyword của domain' : 'Chọn domain để gắn kho' }} →</a></section>
    <section class="card"><h3>Liên kết fanmulu</h3><p>Thiết lập URL và liên kết keyword của fanmulu tại mục riêng.</p><a href="{{ $fanmuluSettingsUrl }}">Mở Phim chính &amp; fanmulu →</a></section>
</div>
@if(!$editingRule)
<form method="get" action="/admin/rules" class="toolbar" aria-label="Lọc ngoại lệ">
    @if($showRuleEditor)<input type="hidden" name="create" value="1">@endif
    @if($ruleSites->isNotEmpty())<label>Domain<select name="site_id"><option value="" @selected(!$site)>Tất cả domain</option>@foreach($ruleSites as $ruleSite)<option value="{{ $ruleSite->id }}" @selected($site?->id === $ruleSite->id)>{{ $siteLabels->label($ruleSite) }}{{ $ruleSite->status === 'archived' ? ' · đã lưu trữ' : '' }}</option>@endforeach</select></label>@endif
    <label>Nhóm ngoại lệ<select name="kind"><option value="all" @selected($ruleKind === 'all')>Tất cả nhóm</option>@foreach($groupLabels as $kind => $label)<option value="{{ $kind }}" @selected($ruleKind === $kind)>{{ $label }}</option>@endforeach</select></label>
    @if(!$showRuleEditor)<label>Hiển thị<select name="status"><option value="active" @selected($ruleStatus === 'active')>Đang dùng</option><option value="archived" @selected($ruleStatus === 'archived')>Đã lưu trữ</option><option value="all" @selected($ruleStatus === 'all')>Tất cả</option></select></label>@endif
    <button class="secondary">Lọc ngoại lệ</button>
</form>
@if($site && !$showRuleEditor)<p class="muted">Ngoại lệ chung vẫn hiển thị khi lọc domain để đối chiếu. Ngoại lệ riêng của trang, danh mục hoặc domain có thể được ưu tiên hơn.</p>@endif
@endif
@if(!$showRuleEditor)
<section class="card" id="rules-list">
    <div class="sectionhead"><h2>Ngoại lệ hiện có · {{ number_format($rules->total()) }}</h2>@if($canWriteRule ?? auth()->user()->is_global_admin)<a class="button secondary" href="/admin/deletions?kind=rule">Xóa &amp; khôi phục hàng loạt</a>@endif</div><p class="muted">Chỉ cần chỉnh ở đây khi muốn khác với bộ SEO hoặc cấu hình domain.</p>
    @if($ruleStatus === 'archived')<p class="note">Các quy tắc lưu trữ được giữ để khôi phục. Hệ thống bỏ qua chúng khi dựng bản mới; bản trang và keyword đang phục vụ giữ nguyên.</p>@endif
    <div class="tablewrap"><table><thead><tr><th>Ngoại lệ</th><th>Áp dụng cho</th><th>Tác dụng</th><th>Trạng thái</th><th></th></tr></thead><tbody>
    @forelse($rules as $rule)
        @php
            $config = $rule->config_json === null ? null : json_decode($rule->config_json, true);
            $ruleState = \App\Domain\Support\RuleConfigurationForm::values($rule->slot, $config)['mode'];
            $summary = $ruleSummaries[$rule->id] ?? [];
        @endphp
        <tr><td><strong>{{ $slotLabels[$rule->slot] ?? $rule->slot }}</strong><small class="site-directory-meta">{{ $groupLabels[$summary['group'] ?? 'seo'] }}</small><details><summary>Mã #{{ $rule->id }}</summary><small>{{ $rule->slot }} · phiên bản {{ $rule->version_no }}</small></details></td><td>{{ $scopeLabels[$rule->scope_type] }}{{ $rule->site_id ? ' · '.$siteLabels->label((int) $rule->site_id) : '' }}@if($rule->page_id)<small class="site-directory-meta">{{ $ruleTargets['pages'][$rule->page_id] ?? 'Trang #'.$rule->page_id }}</small>@endif @if($rule->category_id)<small class="site-directory-meta">{{ $ruleTargets['categories'][$rule->category_id] ?? 'Danh mục #'.$rule->category_id }}</small>@endif @if($rule->page_type)<small class="site-directory-meta">{{ ['movie'=>'Phim','home'=>'Trang chủ','category'=>'Thể loại','novel'=>'Tiểu thuyết','episode'=>'Tập phim','chapter'=>'Chương'][$rule->page_type] ?? $rule->page_type }}</small>@endif</td><td><p>{{ $summary['effect'] ?? '' }}</p>@if(!empty($summary['warning']))<p class="{{ !empty($summary['overrides_seo']) ? 'muted' : 'note' }}">{{ $summary['warning'] }}</p>@endif</td><td><span class="badge {{ $rule->status }}">{{ $rule->status === 'archived' ? 'Đã lưu trữ' : ($rule->status === 'paused' ? 'Tạm dừng' : ['on'=>'Bật','inherit'=>'Kế thừa','off'=>'Tắt'][$ruleState]) }}</span></td><td x-data><div class="crud-row-actions"><a href="/admin/rules?{{ http_build_query(array_filter(['site_id' => $rule->site_id, 'status' => $ruleStatus, 'rule_id' => $rule->id])) }}#rule-editor">{{ $rule->status !== 'archived' && ($ruleCanWrite[$rule->id] ?? auth()->user()->is_global_admin) ? 'Chỉnh sửa' : 'Xem cấu hình' }}</a>
            @if($ruleCanWrite[$rule->id] ?? auth()->user()->is_global_admin)
                @if(($rule->deleted_at ?? null) === null)<a class="button danger" href="/admin/deletions/rule/{{ $rule->id }}">Xóa</a>@endif
                @if($rule->status === 'archived')<form method="post" action="{{ route('admin.rules.restore', $rule->id) }}">@csrf<input type="hidden" name="row_version" value="{{ $rule->row_version }}"><button class="secondary">Khôi phục</button></form>
                @else<button type="button" class="danger" aria-label="Lưu trữ quy tắc #{{ $rule->id }}" @click="$refs.archive.showModal()">Lưu trữ</button>@endif
            @endif
        </div>
        @if($rule->status !== 'archived' && ($ruleCanWrite[$rule->id] ?? auth()->user()->is_global_admin))
            <dialog class="crud-modal" x-ref="archive" aria-labelledby="rule-archive-{{ $rule->id }}"><div class="crud-dialog-head"><h2 id="rule-archive-{{ $rule->id }}">Lưu trữ quy tắc #{{ $rule->id }}</h2><button type="button" class="secondary" @click="$refs.archive.close()" aria-label="Đóng">×</button></div><div class="crud-dialog-body"><p>Lưu trữ <strong>{{ $slotLabels[$rule->slot] ?? $rule->slot }}</strong>? Bản mới sẽ kế thừa cấu hình cấp trước. Các phiên bản, keyword và bản trang đang phục vụ được giữ nguyên; có thể khôi phục quy tắc từ mục Đã lưu trữ.</p><form method="post" action="{{ route('admin.rules.archive', $rule->id) }}">@csrf<input type="hidden" name="row_version" value="{{ $rule->row_version }}"><div class="crud-dialog-actions"><button class="danger">Lưu trữ quy tắc</button><button type="button" class="secondary" @click="$refs.archive.close()">Hủy</button></div></form></div></dialog>
        @endif
        </td></tr>
    @empty<tr><td colspan="5" class="empty">Chưa có quy tắc.</td></tr>@endforelse
    </tbody></table></div><div class="pagination">{{ $rules->links() }}</div>
</section>
@else
<section class="card configuration-editor" id="rule-editor" x-data="tramRuleEditor({{ $site?->id ?? 0 }}, @js($keywordChoices), @js($initial))">
    <h2>{{ $editingRule ? 'Cấu hình ngoại lệ #'.$editingRule->id : 'Cấu hình ngoại lệ mới' }}</h2>
    @if(!empty($editingSummary['effect']))<p>{{ $editingSummary['effect'] }}</p>@endif
    @if(!empty($editingSummary['warning']))<p class="note">{{ $editingSummary['warning'] }}</p>@endif
    @if($editingRule?->status === 'archived')<p class="note">Quy tắc đã lưu trữ. Cấu hình và phiên bản được giữ nguyên; khôi phục trước khi chỉnh sửa. Khôi phục không tự đổi các trang đang phục vụ.</p>
    @elseif(!$canEditRule)<p class="note">Bạn đang xem cấu hình. Chỉ người có quyền chỉnh sửa trong phạm vi này được lưu phiên bản mới.</p>@endif
    <form method="post" action="/admin/rules">
        @csrf
        <input type="hidden" name="configuration_form_present" value="1"><input type="hidden" name="form_context" value="{{ $formContext }}">
        @if($editingRule)
            <input type="hidden" name="rule_id" value="{{ $editingRule->id }}"><input type="hidden" name="row_version" value="{{ $restoreForm && is_scalar(old('row_version')) ? old('row_version') : $editingRule->row_version }}">
            @foreach(['scope_type','site_id','page_type','category_id','page_id','slot'] as $identity)<input type="hidden" name="{{ $identity }}" value="{{ $editingRule->$identity }}">@endforeach
            <p class="configuration-identity"><strong>{{ $slotLabels[$ruleSlot] ?? $ruleSlot }}</strong> · {{ $scopeLabels[$editingRule->scope_type] }} · v{{ $editingRule->version_no }}. Phạm vi giữ nguyên; tạo quy tắc riêng để áp dụng cho nơi khác.</p>
        @endif
        <fieldset class="configuration-fields" @disabled(!$canEditRule)>
            <input type="hidden" name="rule_form[_present]" value="1">
            @if(!$editingRule)
                <div class="formrow three">
                    <label>Áp dụng cho<select name="scope_type" x-model="scopeType">@if(auth()->user()->is_global_admin)<option value="global">Toàn hệ thống</option>@endif @if($site)<option value="site">Domain</option><option value="page_type">Loại trang</option><option value="category">Danh mục</option><option value="page">Một trang</option>@endif</select></label>
                    @if($site)<label x-show="scopeType !== 'global'">Domain<select name="site_id" :disabled="scopeType === 'global'"><option value="{{ $site->id }}">{{ $siteLabels->label($site) }}</option></select></label>@endif
                    <label>Nội dung cần điều chỉnh<select x-model="slot">@foreach($groupLabels as $group=>$label)<optgroup label="{{ $label }}">@foreach($slotLabels as $key=>$slotLabel)@if(($group === 'allocation' && str_starts_with($key,'allocation.')) || ($group === 'links' && in_array($key,['managed_body','related_links'])) || ($group === 'seo' && !str_starts_with($key,'allocation.') && !in_array($key,['managed_body','related_links'])))<option value="{{ $key }}">{{ $slotLabel }}</option>@endif @endforeach @if($group === 'seo')<option value="h2.custom">Tiêu đề H2 khác</option>@endif</optgroup>@endforeach</select><input type="hidden" name="slot" :value="submittedSlot"></label>
                </div>
                @if(!$site)<p class="note">Đang tạo quy tắc toàn hệ thống. Chọn một domain ở bộ lọc phía trên để tạo quy tắc riêng cho domain, loại trang, danh mục hoặc một trang.</p>@endif
                <div class="formrow three">
                    <label x-show="scopeType === 'page_type'">Loại trang<select name="page_type" :disabled="scopeType !== 'page_type'">@foreach(['movie'=>'Phim','home'=>'Trang chủ','category'=>'Danh mục','novel'=>'Tiểu thuyết','episode'=>'Tập phim','chapter'=>'Chương'] as $key=>$label)<option value="{{ $key }}" @selected($restoreForm && old('page_type') === $key)>{{ $label }}</option>@endforeach</select></label>
                    <label x-show="scopeType === 'category'">Danh mục<select name="category_id" :disabled="scopeType !== 'category'"><option value="">Chọn danh mục</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($restoreForm && (string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
                    <div x-show="scopeType === 'page'"><label>Tìm trang theo đường dẫn<input type="search" x-model="pageQuery" @input.debounce.300ms="loadPages()" placeholder="/movie/... hoặc ID trang" :disabled="scopeType !== 'page'"></label><label>Trang được chọn<select name="page_id" x-model="pageId" :disabled="scopeType !== 'page'"><option value="">Chọn trang</option><template x-for="page in pageChoices" :key="page.id"><option :value="String(page.id)" x-text="page.canonical_path"></option></template></select></label><p class="muted" x-text="pageError" role="status"></p></div>
                    <label x-show="slot === 'h2.custom'">Tên vị trí H2<input x-model="h2Name" pattern="[a-z0-9_-]+" maxlength="70" placeholder="summary" :disabled="slot !== 'h2.custom'"><small>Chữ thường, số, dấu gạch ngang hoặc gạch dưới.</small></label>
                </div>
            @endif
            <div class="formrow"><label>Cách dùng ngoại lệ<select name="mode" x-model="mode"><option value="on">Dùng cấu hình này</option><option value="inherit">Kế thừa cấu hình cấp trước</option><option value="off">Tắt vị trí này</option></select></label></div>
            @include('admin.partials.configuration-slot', ['fieldPrefix'=>'rule_form','slotValues'=>$formValues['rule_form']])
            <div x-show="slot.startsWith('allocation.') && mode === 'on'" x-cloak>
                <h3>Chính sách cấp keyword</h3>
                <div class="formrow three">
                    <label>Cách phân bổ<select name="allocation_mode" x-model="allocationMode" :disabled="!allocationEnabled"><option value="weighted">Theo trọng số</option><option value="random-once">Ngẫu nhiên một lần</option><option value="sequential">Theo thứ tự</option><option value="manual">Chọn thủ công</option></select></label>
                    <label>Số keyword<input name="allocation_count" type="number" min="0" :max="slot === 'allocation.primary' ? 1 : 20" value="{{ $formValues['allocation_count'] }}" :disabled="!allocationEnabled"><small>Keyword chính tối đa 1; keyword phụ và anchor tối đa 20.</small></label>
                    <label class="checkboxline"><input type="hidden" name="allocation_required" value="0" :disabled="!allocationEnabled"><input name="allocation_required" value="1" type="checkbox" @checked($formValues['allocation_required']) :disabled="!allocationEnabled">Bắt buộc có đủ keyword</label>
                </div>
                <div class="formrow">
                    <fieldset class="configuration-pool-list"><legend>Kho keyword ưu tiên</legend><p class="muted">Thứ tự đang dùng được giữ khi lưu. Chọn các kho đã gắn với website.</p>@forelse($orderedPools as $pool)<label class="checkboxline"><input name="pool_ids[]" value="{{ $pool->id }}" type="checkbox" @checked(in_array($pool->id, $formValues['pool_ids'])) :disabled="!allocationEnabled">{{ $pool->name }} · #{{ $pool->id }}</label>@empty<p class="muted">Chưa có kho được gắn trong phạm vi hiện tại.</p>@endforelse</fieldset>
                    <fieldset class="configuration-pool-list"><legend>Kho dự phòng</legend><p class="muted">Dùng khi các kho ưu tiên không đủ keyword phù hợp.</p>@foreach($fallbackPools as $pool)<label class="checkboxline"><input name="fallback_pool_ids[]" value="{{ $pool->id }}" type="checkbox" @checked(in_array($pool->id, $formValues['fallback_pool_ids'])) :disabled="!allocationEnabled">{{ $pool->name }} · #{{ $pool->id }}</label>@endforeach</fieldset>
                </div>
                @include('admin.partials.metadata-filters',['filterField'=>'allocation_filter_form','filterConfig'=>is_array($currentConfig) && is_array($currentConfig['filters'] ?? null) ? $currentConfig['filters'] : [],'filterDisabled'=>'!allocationEnabled'])
                <div x-show="allocationMode === 'manual'" class="configuration-manual">
                    <label>Tìm keyword thủ công<input type="search" x-model="kwQuery" @input.debounce.300ms="loadKeywords()" placeholder="Nhập keyword trong các kho của website" :disabled="!allocationEnabled"></label>
                    <p class="muted">Đã chọn <strong x-text="keywordIds.length"></strong> keyword. Giữ Ctrl hoặc Command để chọn nhiều dòng.</p>
                    <select multiple class="configuration-keyword-select" x-model="keywordIds" :disabled="!allocationEnabled || allocationMode !== 'manual'" aria-label="Keyword thủ công">
                        <template x-for="keyword in visibleKeywords" :key="keyword.id"><option :value="String(keyword.id)" x-text="keyword.original_text + ' · kho #' + keyword.pool_id + (keyword.status && keyword.status !== 'active' ? ' · ' + keyword.status : '')"></option></template>
                    </select>
                    <p class="muted" x-text="keywordError"></p>
                </div>
                <template x-if="allocationEnabled"><div><template x-for="id in keywordIds" :key="id"><input type="hidden" name="keyword_ids[]" :value="id"></template></div></template>
            </div>
        </fieldset>
        <p class="note">Lưu ngoại lệ để dùng cho các trang tạo mới. Trang đang phục vụ giữ công thức và keyword đã lưu. Muốn sửa bộ SEO thông thường, mở Template SEO ở phía trên.</p><div class="formfooter">@if($canEditRule)<button name="intent" value="draft">Lưu ngoại lệ</button><details><summary>Áp dụng lại cho trang hiện có</summary><p>Thao tác nâng cao này dựng lại HTML qua hàng đợi, không chỉ xóa cache. Keyword đã gán được giữ; phạm vi lớn có thể cần chia lượt.</p><button name="intent" value="apply" class="secondary">Lưu và áp dụng lại trong phạm vi này</button></details>@endif<a class="button secondary" href="{{ $ruleListUrl }}">Quay lại danh sách</a></div>
    </form>
    @if($editingRule?->status === 'archived' && $canManageRule)<form method="post" action="{{ route('admin.rules.restore', $editingRule->id) }}">@csrf<input type="hidden" name="row_version" value="{{ $editingRule->row_version }}"><button class="secondary">Khôi phục quy tắc</button></form>@endif
</section>
@endif
@endsection
