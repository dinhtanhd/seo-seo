@extends('admin.layout')
@section('title', $theme ? $theme->name : 'Tạo giao diện phim')
@section('content')
@php($hasReviewedRuntime = App\Domain\Themes\ReviewedThemeRuntime::enabled(['runtime' => App\Domain\Support\Json::decode($version?->runtime_json ?? null)]))
<div class="pagehead"><div><div class="eyebrow">GIAO DIỆN PHIM / {{ $theme ? '#'.$theme->id : 'TẠO MỚI' }}</div><h1>{{ $theme?->name ?? 'Tạo giao diện phim' }}</h1><p>Chỉnh HTML/CSS rồi Lưu & áp dụng cho {{ number_format($affectedPageCount ?? 0) }} trang đang phục vụ trên {{ number_format($affectedSiteCount ?? 0) }} website dùng giao diện này. Trang nháp được giữ lại.</p></div><div class="actions">@if(auth()->user()->is_global_admin)<button type="submit" form="movie-theme-editor" name="intent" value="apply">Lưu & áp dụng</button>@if($theme && ($theme->deleted_at ?? null) === null)<a class="button danger" href="/admin/deletions/theme/{{ $theme->id }}">Xóa</a>@endif @endif<a class="button secondary" href="/admin/themes">← Kho giao diện</a></div></div>
<div x-data="tramThemeEditor(@js(old('views',$views)), @js(old('css',$css)))">
    <form id="movie-theme-editor" method="post" action="{{ $theme ? '/admin/themes/'.$theme->id : '/admin/themes' }}">
        @csrf
        @if($site)<input type="hidden" name="site_id" value="{{ $site->id }}">@endif
        @if($theme)<input type="hidden" name="row_version" value="{{ $theme->row_version }}"><input type="hidden" name="edit_version_id" value="{{ $version->id }}">@endif
        <section class="card">
            <div class="formrow"><label>Tên giao diện<input name="name" value="{{ old('name',$theme?->name) }}" required maxlength="200" @readonly(!auth()->user()->is_global_admin)></label><label>Mã giao diện<input name="slug" value="{{ old('slug',$theme?->slug) }}" required minlength="2" maxlength="80" pattern="[a-z][a-z0-9_-]{1,79}" @readonly($theme || !auth()->user()->is_global_admin)></label></div>
            <p class="note">HTML chỉ chứa phần body, mỗi trang có đúng một H1. Head, canonical và meta SEO được hệ thống ghép vào khi render. @if($hasReviewedRuntime)CSS/JS đã duyệt được tải bằng từng file riêng theo manifest; cây file và ZIP giữ nguyên đường dẫn của bộ giao diện.@else CSS được ghép vào trang khi render.@endif</p><label>Mô tả<input name="description" value="{{ old('description',$theme?->description) }}" maxlength="1000" @readonly(!auth()->user()->is_global_admin)></label>
            <div class="theme-tabs" role="tablist" aria-label="Các trang giao diện">
                @foreach($viewLabels + ['css'=>'CSS'] as $key=>$label)
                    <button type="button" class="secondary" :class="{selected: activeTab === '{{ $key }}'}" @click="activeTab='{{ $key }}'; selectedField='{{ $key }}'" :aria-selected="activeTab === '{{ $key }}'" role="tab">{{ $label }}</button>
                @endforeach
            </div>
            @foreach($viewLabels as $key=>$label)
                <div x-show="activeTab === '{{ $key }}'" x-cloak><label>HTML {{ mb_strtolower($label) }} · {{ isset($views['list']) ? 'html/' : '' }}{{ $key }}.html<textarea name="views[{{ $key }}]" x-ref="{{ $key }}" x-model="views.{{ $key }}" @focus="selectedField='{{ $key }}'" class="code theme-code" spellcheck="false" required @readonly(!auth()->user()->is_global_admin)></textarea></label></div>
            @endforeach
            <div x-show="activeTab === 'css'" x-cloak><p class="muted">@if($hasReviewedRuntime)Đây là CSS bổ sung. Các file CSS nguồn trong cây giao diện vẫn được tải riêng, đúng thứ tự manifest. @endif CSS chỉ dùng biến asset trong gói giao diện; các biến phim/SEO dùng ở HTML.</p><label>CSS dùng chung cho bộ giao diện<textarea name="css" x-ref="css" x-model="css" @focus="selectedField='css'" class="code theme-code" spellcheck="false" @readonly(!auth()->user()->is_global_admin)></textarea></label></div>
            <div class="formfooter">
                @if(auth()->user()->is_global_admin)<button name="intent" value="apply">Lưu & áp dụng</button>@else<p class="muted">Bạn được đọc giao diện. Chỉ quản trị toàn hệ thống được sửa kho HTML/CSS.</p>@endif
                @if($version)<span class="muted">Đang đọc v{{ $version->version_no }} · {{ $version->created_at }} · {{ substr($version->hash,0,16) }}…</span>@endif
            </div>
            @if(auth()->user()->is_global_admin)<details><summary>Nâng cao · chỉ lưu trong kho</summary><p>Tạo phiên bản để chỉnh tiếp, chưa gửi yêu cầu cập nhật website.</p><button class="secondary" name="intent" value="draft">Chỉ lưu phiên bản</button></details>@endif
        </section>
    </form>
    @include('admin.themes.tree')
    @include('admin.themes.reference')
</div>
@if($theme)
<details class="card"><summary>Xem thử giao diện (nâng cao)</summary>
    <form method="get" action="/admin/themes/{{ $theme->id }}" class="toolbar">
        <label>Tên miền dữ liệu xem thử<select name="site_id" onchange="this.form.elements.page_id.value = ''">@foreach($sites as $previewSite)<option value="{{ $previewSite->id }}" @selected($site?->id === $previewSite->id)>{{ $siteLabels->label($previewSite) }}</option>@endforeach</select></label>
        <label>Phiên bản<select name="version_id">@foreach($versions as $item)<option value="{{ $item->id }}" @selected($version?->id === $item->id)>v{{ $item->version_no }} · {{ $item->created_at }}</option>@endforeach</select></label>
        <label>Trang dữ liệu<select name="page_id"><option value="">Dữ liệu website hiện tại</option>@foreach($pages as $page)<option value="{{ $page->id }}" @selected((int) request('page_id') === $page->id)>{{ $page->canonical_path }}</option>@endforeach</select></label>
        <label>Kiểu trang<select name="view">@foreach(['home'=>'Trang chủ','list'=>'Danh sách phim','detail'=>'Chi tiết phim','play'=>'Phát phim','search'=>'Tìm kiếm'] as $key=>$label)<option value="{{ $key }}" @selected(request('view','detail') === $key || ($key === 'list' && request('view') === 'category'))>{{ $label }}</option>@endforeach</select></label>
        <button class="secondary">Xem trước phiên bản đã lưu</button>
    </form>
    <p class="note">Tên miền này chỉ chọn dữ liệu xem thử. Khung xem thử dùng dữ liệu website đã chọn và phiên bản đã lưu. Nút Lưu & áp dụng ở đầu trang cập nhật những website đang dùng giao diện; kết quả và lỗi được báo riêng.</p>
    @if($previewUrl)<div x-data="{ previewWidth: 'desktop' }"><div class="actions"><button type="button" class="secondary" @click="previewWidth='desktop'">Preview desktop</button><button type="button" class="secondary" @click="previewWidth='mobile'">Preview mobile 390 px</button></div><iframe class="theme-preview" :style="previewWidth === 'mobile' ? 'max-width:390px;margin-left:auto;margin-right:auto' : ''" title="Xem trước giao diện phim" src="{{ $previewUrl }}" sandbox="{{ $hasReviewedRuntime ? 'allow-scripts' : '' }}" referrerpolicy="no-referrer" loading="lazy"></iframe></div>@else<p class="muted">Chọn website và trang phim để xem trước.</p>@endif
    <div class="actions">@if($site)<a class="button secondary" href="/admin/sites/{{ $site->id }}/presentation{{ $scope }}">Giao diện & đường dẫn website</a>@endif<a class="button secondary" href="/admin/pages{{ $scope }}">Override từng trang (nâng cao)</a><a href="/admin/themes/{{ $theme->id }}/export?version_id={{ $version->id }}&amp;site_id={{ $site?->id }}">Xuất folder ZIP v{{ $version->version_no }}</a></div>
</details>
@if(auth()->user()->is_global_admin)
<section class="card"><h2>Nhập phiên bản mới từ ZIP</h2><form action="/admin/themes/{{ $theme->id }}/import" method="post" enctype="multipart/form-data">@csrf<input type="hidden" name="row_version" value="{{ $theme->row_version }}">@if($site)<input type="hidden" name="site_id" value="{{ $site->id }}">@endif<label>Gói ZIP cho giao diện này<input type="file" name="file" accept=".zip,application/zip" required></label><p class="muted">Cập nhật có kiểm tra version, giữ các phiên bản cũ. Gói phải có cùng mã giao diện, được kiểm tra đầy đủ trước khi lưu.</p><button class="secondary">Nhập thành phiên bản mới</button></form></section><div class="grid two"><section class="card"><h2>Nhân bản giao diện</h2><form action="/admin/themes/{{ $theme->id }}/clone" method="post">@csrf @if($site)<input type="hidden" name="site_id" value="{{ $site->id }}">@endif<div class="formrow"><label>Tên bản sao<input name="name" required maxlength="200" value="{{ $theme->name }} — Bản sao"></label><label>Mã bản sao<input name="slug" required minlength="2" maxlength="80" pattern="[a-z][a-z0-9_-]{1,79}" placeholder="movie-theme-copy"></label></div><button class="secondary">Tạo giao diện độc lập</button></form></section><section class="card"><h2>Trạng thái thư viện</h2><p class="muted">Archive giữ lịch sử phiên bản và snapshot live. Giao diện archive không xuất hiện trong lựa chọn mới.</p><form action="/admin/themes/{{ $theme->id }}/status" method="post">@csrf<input type="hidden" name="row_version" value="{{ $theme->row_version }}"><input type="hidden" name="status" value="{{ $theme->status === 'active' ? 'archived' : 'active' }}"><button class="{{ $theme->status === 'active' ? 'danger' : 'secondary' }}">{{ $theme->status === 'active' ? 'Archive giao diện' : 'Khôi phục giao diện' }}</button></form></section></div>
@endif
@endif
@endsection
