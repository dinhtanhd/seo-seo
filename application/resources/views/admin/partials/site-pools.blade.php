@php
    $bindingLabels = ['active'=>'Đang cấp keyword','paused'=>'Tạm dừng cấp mới','archived'=>'Đã gỡ khỏi cấp mới'];
    $createPool = old('form_context') === 'site-pool-create' && is_scalar(old('pool_id')) ? (string) old('pool_id') : (string) ($sitePools->first()->id ?? '');
@endphp
<section class="card">
    <h2>Kho keyword của {{ $siteLabels->label($site) }}</h2>
    <p class="muted">Chọn kho dùng chung, nhóm và điều kiện cho website này. Thay đổi liên kết giữ keyword trên trang đang phục vụ. Nội dung mới tự dùng kho này khi áp dụng.</p>
    <p><a href="/admin/pools">Quản lý kho & nhóm dùng chung</a> · <a href="/admin/keywords">Quản lý keyword</a></p>
    @forelse($websitePoolBindings as $binding)
        <div class="card" style="margin-top:16px;box-shadow:none">
            <div class="sectionhead"><h3>{{ $binding->pool_name }}@if($binding->group_name) · {{ $binding->group_name }}@endif</h3><span class="badge {{ $binding->status }}">{{ $bindingLabels[$binding->status] }}</span></div>
            <p class="muted">Liên kết #{{ $binding->id }} · {{ $binding->page_type ?: 'Mọi kiểu trang' }} · Ưu tiên {{ $binding->priority }}</p>
            @if($binding->pool_deleted_at)<p class="note">Kho đã xóa khỏi cấp mới. Trang live và lịch sử giữ keyword đã chốt.</p>@endif
            @if($canEdit)
                <details><summary>Chỉnh sửa liên kết kho</summary><form method="post" action="/admin/sites/{{ $site->id }}/pools/{{ $binding->id }}">@csrf
                    @include('admin.partials.site-pool-fields', ['binding'=>$binding])
                    <button class="secondary">Lưu liên kết kho</button>
                </form></details>
                @if($binding->status !== 'archived')<form method="post" action="/admin/sites/{{ $site->id }}/pools/{{ $binding->id }}/delete" style="margin-top:12px">@csrf<input type="hidden" name="row_version" value="{{ $binding->row_version }}"><button class="secondary">Gỡ kho khỏi website</button></form>@endif
            @else<p class="muted">Chỉ xem cấu hình liên kết.</p>@endif
        </div>
    @empty<p class="empty">Website chưa gắn kho keyword. Trang cần keyword bắt buộc sẽ chặn xuất bản đến khi có kho và từ phù hợp.</p>@endforelse
</section>
@if($canEdit)
    <section class="card"><h2>Gắn pool vào website</h2>
        @if($sitePools->isNotEmpty())
            <form method="post" action="/admin/sites/{{ $site->id }}/pools" x-data="{ pool: @js($createPool) }">@csrf
                @include('admin.partials.site-pool-fields', ['binding'=>null])
                <button>Gắn kho</button>
            </form>
        @else<p class="empty">Chưa có kho dùng được. <a href="/admin/pools">Tạo kho dùng chung</a> trước.</p>@endif
    </section>
    @include('admin.partials.site-keyword-limits')
@endif
