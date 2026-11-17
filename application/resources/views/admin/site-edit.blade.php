@extends('admin.layout')
@section('title', $site ? 'Website · '.$siteLabels->label($site) : 'Tạo website')
@section('content')
@php
    $statusLabels = ['active' => 'Hoạt động', 'paused' => 'Tạm dừng', 'archived' => 'Lưu trữ', 'pending' => 'Chờ xác nhận'];
    $roleLabels = ['owner' => 'Owner · quản lý website', 'operator' => 'Operator · sửa và xuất bản', 'viewer' => 'Viewer · chỉ đọc'];
    $primaryDomain = $domains->firstWhere('is_primary', true);
    $aliases = $domains->where('is_primary', false);
    $tab = $tab ?? 'settings';
@endphp
<div class="pagehead">
    <div><div class="eyebrow">{{ $site ? 'WEBSITE #'.$site->id : 'WEBSITE MỚI' }}</div><h1>{{ $site ? $siteLabels->label($site) : 'Tạo website' }}</h1><p>{{ $site ? 'Cấu hình website, kho keyword được dùng, nguồn và danh mục nội dung.' : 'Tạo website và hostname chính, sau đó chọn kho keyword, nguồn và giao diện.' }}</p></div>
    <div class="actions"><a class="button secondary" href="/admin/sites">← Danh sách website</a>@if($site)<a class="button secondary" href="{{ $site->base_url }}" target="_blank" rel="noopener">Mở website ↗</a><a href="/admin/sites/{{ $site->id }}/cache">Quản lý cache</a><a href="/admin/pages?site_id={{ $site->id }}">Trang & tác phẩm</a>@endif</div>
</div>
@if(!$site && auth()->user()->is_global_admin)
<section class="card"><h2>Tạo website phim từ bộ cấu hình</h2><p>Chọn bộ cấu hình có sẵn để tự gắn giao diện, Template SEO, nguồn phim và kho keyword. Có thể nhập một domain hoặc nhiều domain trong cùng một lần.</p><a class="button" href="/admin/sites/bulk-create">Tạo website phim từ bộ cấu hình</a></section>
@endif
@include('admin.partials.site-navigation', ['websiteTab' => $tab])
@if(!$canEdit)
    <p class="note" role="status">Bạn đang xem cấu hình. Quản trị viên có quyền quản lý website này mới được lưu thay đổi.</p>
@endif
@if($tab === 'settings' || !$site)
    <div class="grid two site-editor-grid">
        <section class="card">
            <h2>{{ $site ? 'Cấu hình website' : 'Thông tin website mới' }}</h2>
            <form method="post" action="/admin/sites">
                @csrf
                <input type="hidden" name="id" value="{{ $site?->id }}">
                <input type="hidden" name="row_version" value="{{ old('row_version', $site?->row_version) }}">
                <fieldset class="site-editor-fieldset" @disabled(!$canEdit)>
                    <div class="formrow"><div><label>Tên hiển thị trên website<input name="name" value="{{ old('name', $site?->name) }}" maxlength="200" required></label><small>Tên thương hiệu hiển thị trên trang public và có thể dùng trong tiêu đề SEO. Admin quản lý website theo domain.</small></div><div><label>Locale<input name="locale" value="{{ old('locale', $site?->locale ?? 'zh-CN') }}" maxlength="16" required></label></div></div>
                    <div class="formrow"><div><label>Hostname<input name="domain" value="{{ old('domain', $primaryDomain?->host_ascii) }}" placeholder="movie.localhost" maxlength="253" required></label></div><div><label>Canonical base URL<input name="base_url" value="{{ old('base_url', $site?->base_url) }}" placeholder="http://movie.localhost:8080" maxlength="500" required></label></div></div>
                    <div class="formrow"><div><label>Loại nội dung<select name="content_type"><option value="movie" @selected(old('content_type', $site?->content_type ?? 'movie') === 'movie')>Phim</option><option value="novel" @selected(old('content_type', $site?->content_type ?? 'movie') === 'novel')>Tiểu thuyết</option></select></label></div><div><label>Trạng thái<select name="status">@foreach(['active', 'paused', 'archived'] as $status)<option value="{{ $status }}" @selected(old('status', $site?->status ?? 'active') === $status)>{{ $statusLabels[$status] }}</option>@endforeach</select></label></div></div>
                    <label>Bộ Template SEO<select name="template_id"><option value="">Mặc định hệ thống</option>@foreach($templates as $template)<option value="{{ $template->id }}" @selected((string) old('template_id', $site?->template_id) === (string) $template->id)>{{ $template->name }}{{ ($template->schema_version ?? 1) < 2 ? ' · Mẫu cũ' : '' }}{{ $template->status !== 'active' ? ' · '.$template->status : '' }}</option>@endforeach</select><small>Trang mới và trang kế thừa tự dùng bộ này cho năm kiểu trang. Trang đã chọn mẫu riêng vẫn giữ lựa chọn đó. <a href="/admin/templates">Quản lý các bộ</a>@if(auth()->user()->is_global_admin) · <a href="/admin/templates/create">Tạo bộ mới</a>@endif.</small></label>
                </fieldset>
                @if(!$site)<p class="muted">Website phim mới có trang chủ, 4 danh mục cha và 24 danh mục con. Trang chủ mở được sau khi tạo; nhập phim để hiển thị nội dung.</p>@endif
                <p class="note">Đổi domain/canonical giữ snapshot live và host cũ làm alias. Bấm Lưu & áp dụng để canonical mới có hiệu lực; DNS/TLS được cấu hình riêng. Canonical hiện tại: {{ $site?->base_url ?? 'chưa có' }}.</p>
                @if($canEdit)<button name="intent" value="apply">{{ $site ? 'Lưu & áp dụng' : 'Tạo & áp dụng' }}</button><details><summary>Nâng cao</summary><button name="intent" value="draft" class="secondary">Chỉ lưu cấu hình</button></details>@endif
            </form>
        </section>
        <section class="card">
            <h2>Domain & giao diện</h2>
            @if($site)
                <div class="site-domain-line"><strong>{{ $primaryDomain?->host_ascii ?? 'Chưa có domain chính' }}</strong>@if($primaryDomain)<span class="badge {{ $primaryDomain->status }}">{{ $statusLabels[$primaryDomain->status] ?? $primaryDomain->status }}</span><small>Domain chính</small>@endif</div>
                <p><a class="code site-canonical" href="{{ $site->base_url }}" target="_blank" rel="noopener">{{ $site->base_url }}</a></p>
                <h3>Alias hiện có</h3>
                @forelse($aliases as $alias)<div class="site-domain-line"><span class="code">{{ $alias->host_ascii }}</span><span class="badge {{ $alias->status }}">{{ $statusLabels[$alias->status] ?? $alias->status }}</span></div>@empty<p class="muted">Chưa có alias.</p>@endforelse
                <hr class="divider">
                <h3>Giao diện hiện tại</h3>
                <p>{{ $site->theme_id ? ($themes->firstWhere('id', $site->theme_id)?->name ?? '#'.$site->theme_id) : 'Mặc định hệ thống' }}</p>
                @if($site->content_type === 'movie')<a class="button secondary" href="/admin/sites/{{ $site->id }}/presentation?site_id={{ $site->id }}">Giao diện & đường dẫn</a><p class="muted">Chọn folder, năm kiểu trang và prefix URL tại một nơi.</p>@endif
            @else
                <p class="muted">Sau khi tạo website phim, mở Giao diện & đường dẫn để chọn bộ giao diện và năm kiểu trang.</p>
            @endif
        </section>
    </div>
@elseif($tab === 'pools')
    @include('admin.partials.site-pools')
@elseif($tab === 'sources')
    @include('admin.partials.site-sources')
@elseif($tab === 'members')
    <section class="card">
        <div class="sectionhead"><h2>Thành viên của {{ $siteLabels->label($site) }}</h2><span class="muted">{{ $members->count() }} thành viên</span></div>
        <div class="tablewrap"><table><thead><tr><th>Tài khoản</th><th>Quyền website</th>@if($canEdit)<th>Thu hồi phiên / quyền</th>@endif</tr></thead><tbody>
            @forelse($members as $member)
                <tr><td><strong>{{ $member->name }}</strong><br><small>{{ $member->email }}</small></td><td>{{ $roleLabels[$member->role] ?? $member->role }}</td>@if($canEdit)<td><form method="post" action="/admin/memberships/revoke">@csrf<input type="hidden" name="site_id" value="{{ $site->id }}"><input type="hidden" name="user_id" value="{{ $member->user_id }}"><label class="checkboxline"><input type="checkbox" name="revoke_membership" value="1">Thu hồi cả quyền website</label><button class="secondary">Thu hồi phiên</button></form></td>@endif</tr>
            @empty<tr><td colspan="{{ $canEdit ? 3 : 2 }}" class="empty">Chưa có thành viên.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    @if($canEdit)
        <section class="card"><h2>Thêm / đổi quyền thành viên</h2><form method="post" action="/admin/memberships">@csrf<input type="hidden" name="site_id" value="{{ $site->id }}"><div class="formrow"><div><label>Email<input name="email" type="email" value="{{ old('email') }}" required></label></div><div><label>Tên tài khoản<input name="name" value="{{ old('name') }}" maxlength="200" required></label></div></div><div class="formrow"><div><label>Quyền website<select name="role">@foreach($roleLabels as $role => $label)<option value="{{ $role }}" @selected(old('role', 'viewer') === $role)>{{ $label }}</option>@endforeach</select></label></div><div><label>Mật khẩu cho tài khoản mới<input type="password" name="password" minlength="12" autocomplete="new-password"></label></div></div><p class="muted">Email đã tồn tại sẽ dùng tài khoản hiện có. Chỉ tài khoản mới cần mật khẩu tối thiểu 12 ký tự.</p><button>Lưu thành viên</button></form></section>
    @endif
@elseif($tab === 'categories')
    @if($movieCategoryPresetState)
        <section class="card"><h2>Cây danh mục phim có sẵn</h2><p>4 danh mục cha, 24 danh mục con. Các danh mục đã có được giữ ID, tên và external key; mục con đang ở gốc được sắp xếp vào đúng cha. Mục có cha khác hoặc trùng tên sẽ được giữ lại và báo để kiểm tra.</p>
            @foreach(\App\Domain\Sites\MovieCategoryPresetService::tree() as $rootName => $children)<p><strong>{{ $rootName }}</strong> → {{ implode(' · ', $children) }}</p>@endforeach
            <p class="muted">Nếu đã có 悬疑电影 ở gốc, mục này được xếp dưới 电影. Nút này chỉ sửa cây danh mục; sau đó vào Nguồn nội dung để ghép type API và chọn dữ liệu cần lấy.</p>
            @if(auth()->user()->is_global_admin)<form method="post" action="/admin/sites/{{ $site->id }}/categories/movie-preset">@csrf<input type="hidden" name="config_epoch" value="{{ $movieCategoryPresetState['config_epoch'] }}"><input type="hidden" name="category_fingerprint" value="{{ $movieCategoryPresetState['category_fingerprint'] }}"><button>Tạo cây danh mục phim</button></form>@endif
            @if(session('movie_category_preset_result.conflicts'))
                @php($presetConflictLabels = ['PARENT_UNAVAILABLE' => 'Danh mục cha cần được kiểm tra trước', 'PRESET_KEY_CHANGED' => 'Danh mục từ bộ mẫu đã được sửa riêng', 'AMBIGUOUS_NAME' => 'Có nhiều danh mục cùng tên', 'OTHER_PARENT' => 'Danh mục đã thuộc một cha khác'])
                <p class="errornotice" role="alert">Các mục cần kiểm tra thủ công:</p><ul>@foreach(session('movie_category_preset_result.conflicts') as $conflict)<li>{{ $conflict['name'] }} · {{ $presetConflictLabels[$conflict['reason']] ?? 'Cần kiểm tra danh mục hiện có' }}@if(isset($conflict['category_id'])) · #{{ $conflict['category_id'] }}@endif</li>@endforeach</ul>
            @endif
        </section>
    @endif
    <section class="card"><div class="sectionhead"><h2>Danh mục của {{ $siteLabels->label($site) }}</h2><div class="actions"><span class="muted">{{ $categories->count() }} danh mục</span>@if($canEdit)<a class="button secondary" href="/admin/deletions?kind=category">Xóa &amp; khôi phục hàng loạt</a>@endif</div></div><div class="tablewrap"><table><thead><tr><th>Danh mục</th><th>Danh mục cha</th><th>Genre</th>@if($canEdit)<th>Chỉnh sửa</th>@endif</tr></thead><tbody>
        @forelse($categories as $category)
            <tr><td><strong>{{ $category->name }}</strong><br><small>#{{ $category->id }} · {{ $category->external_key ?: 'chưa có external key' }}</small></td><td>{{ $category->parent_id ? ($categories->firstWhere('id', $category->parent_id)?->name ?? '#'.$category->parent_id) : 'Danh mục gốc' }}</td><td>{{ $category->genre ?: '—' }}</td>
                @if($canEdit)<td><details><summary>Sửa danh mục</summary><form method="post" action="/admin/categories">@csrf<input type="hidden" name="id" value="{{ $category->id }}"><input type="hidden" name="site_id" value="{{ $site->id }}"><div class="formrow"><div><label>Tên danh mục<input name="name" value="{{ (string) old('id') === (string) $category->id ? old('name', $category->name) : $category->name }}" required></label></div><div><label>Genre<input name="genre" value="{{ (string) old('id') === (string) $category->id ? old('genre', $category->genre) : $category->genre }}"></label></div></div><div class="formrow"><div><label>External key<input name="external_key" value="{{ (string) old('id') === (string) $category->id ? old('external_key', $category->external_key) : $category->external_key }}"></label></div><div><label>Danh mục cha<select name="parent_id"><option value="">Danh mục gốc</option>@foreach($categories->where('id', '!=', $category->id) as $parent)<option value="{{ $parent->id }}" @selected((string) ((string) old('id') === (string) $category->id ? old('parent_id', $category->parent_id) : $category->parent_id) === (string) $parent->id)>{{ $parent->name }}</option>@endforeach</select></label></div></div><button>Lưu danh mục</button></form></details>@if(($category->deleted_at ?? null) === null)<a class="button danger" href="/admin/deletions/category/{{ $category->id }}">Xóa</a>@endif</td>@endif
            </tr>
        @empty<tr><td colspan="{{ $canEdit ? 4 : 3 }}" class="empty">Chưa có danh mục.</td></tr>@endforelse
    </tbody></table></div></section>
    @if($canEdit)
        <section class="card"><h2>Tạo danh mục</h2><form method="post" action="/admin/categories">@csrf<input type="hidden" name="site_id" value="{{ $site->id }}"><div class="formrow"><div><label>Tên danh mục<input name="name" value="{{ !old('id') ? old('name') : '' }}" required></label></div><div><label>Genre filter<input name="genre" value="{{ !old('id') ? old('genre') : '' }}"></label></div></div><div class="formrow"><div><label>External key<input name="external_key" value="{{ !old('id') ? old('external_key') : '' }}"></label></div><div><label>Danh mục cha<select name="parent_id"><option value="">Danh mục gốc</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(!old('id') && (string) old('parent_id') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label></div></div><button>Tạo danh mục</button></form></section>
    @endif
@endif
@endsection
