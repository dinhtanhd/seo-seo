<!DOCTYPE html>
<html lang="vi">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title', 'Trạm SEO') · Trạm SEO</title>@vite(['resources/css/app.css','resources/js/app.js'])@stack('head')</head>
<body x-data="{ menuOpen: false }" @keydown.escape="menuOpen = false">
<div class="shell">
    <button type="button" class="menu-backdrop" x-show="menuOpen" x-cloak @click="menuOpen = false" aria-label="Đóng menu" tabindex="-1"></button>
    <aside id="admin-navigation" class="sidebar" :class="{ 'open': menuOpen }">
        <button type="button" class="menu-close" @click="menuOpen = false" aria-label="Đóng menu điều hướng">×</button>
        <a href="/admin" class="brand"><span class="brandmark">T</span> Trạm SEO</a><p class="brandline">QUẢN LÝ NỘI DUNG & WEBSITE</p>
        @php
            $scope = $site ? '?site_id='.$site->id : '';
            $primaryNavigation = [
                '/admin' => ['label' => 'Tổng quan', 'patterns' => ['admin']],
                '/admin/sites' => ['label' => 'Website & domain', 'patterns' => ['admin/sites', 'admin/sites/*', 'admin/site-groups', 'admin/url-suffixes*']],
                '/admin/site-presets' => ['label' => 'Bộ cấu hình website', 'patterns' => ['admin/site-presets', 'admin/site-presets/*']],
                '/admin/keywords' => ['label' => 'Kho keyword', 'patterns' => ['admin/keywords', 'admin/keywords/*', 'admin/keyword-imports/*']],
                '/admin/pools' => ['label' => 'Pool & nhóm', 'patterns' => ['admin/pools', 'admin/pools/*', 'admin/groups/*']],
                '/admin/templates' => ['label' => 'Template SEO', 'patterns' => ['admin/templates', 'admin/templates/*']],
                '/admin/template-variables' => ['label' => 'Biến template', 'patterns' => ['admin/template-variables*', 'admin/template-variable-sets*']],
                '/admin/themes' => ['label' => 'Giao diện phim', 'patterns' => ['admin/themes', 'admin/themes/*']],
                '/admin/movies' => ['label' => 'Kho phim', 'patterns' => ['admin/movies', 'admin/movies/*']],
                '/admin/movie-comments' => ['label' => 'Kho bình luận mẫu', 'patterns' => ['admin/movie-comments*']],
                '/admin/sources' => ['label' => 'Nguồn nội dung', 'patterns' => ['admin/sources', 'admin/sources/*', 'admin/ingest-runs/*']],
                '/admin/advertising' => ['label' => 'Quảng cáo & thống kê', 'patterns' => ['admin/advertising', 'admin/advertising/*']],
                '/admin/pages' => ['label' => 'Trang & tác phẩm', 'patterns' => ['admin/pages', 'admin/pages/*']],
                '/admin/fanmulu' => ['label' => 'Phim chính & fanmulu', 'patterns' => ['admin/fanmulu', 'admin/sites/*/fanmulu']],
                '/admin/sites/bulk-edit' => ['label' => 'Sửa domain hàng loạt', 'patterns' => ['admin/sites/bulk-edit', 'admin/sites/bulk-edit/*']],
                '/admin/bulk' => ['label' => 'Sửa trang hàng loạt', 'patterns' => ['admin/bulk', 'admin/bulk/*']],
                '/admin/batches' => ['label' => 'Lịch sử áp dụng', 'patterns' => ['admin/batches', 'admin/batches/*']],
                '/admin/baidu' => ['label' => 'Baidu & trạng thái index', 'patterns' => ['admin/baidu', 'admin/baidu/*']],
                '/admin/activity' => ['label' => 'Lịch sử & vận hành', 'patterns' => ['admin/activity', 'admin/activity/*']],
                '/admin/rules' => ['label' => 'Ngoại lệ nâng cao', 'patterns' => ['admin/rules', 'admin/rules/*']],
                '/admin/deletions' => ['label' => 'Xóa & thùng rác', 'patterns' => ['admin/deletions', 'admin/deletions/*']],
            ];
            $globalNavigationLabels = [
                'admin/keywords*' => 'Kho keyword dùng chung',
                'admin/site-presets*' => 'Bộ cấu hình website dùng chung',
                'admin/keyword-imports/*' => 'Nhập keyword',
                'admin/pools*' => 'Pool & nhóm dùng chung',
                'admin/rules*' => 'Ngoại lệ SEO & keyword nâng cao',
                'admin/deletions*' => 'Xóa & thùng rác',
                'admin/templates*' => 'Template SEO dùng chung',
                'admin/template-variables*' => 'Biến template dùng chung',
                'admin/template-variable-sets*' => 'Bộ giá trị biến template',
                'admin/themes*' => 'Kho giao diện phim dùng chung',
                'admin/movies*' => 'Kho phim dùng chung',
                'admin/movie-comments*' => 'Kho bình luận mẫu dùng chung',
                'admin/sources*' => 'Nguồn nội dung dùng chung',
                'admin/advertising*' => 'Quảng cáo & thống kê dùng chung',
                'admin/account' => 'Tài khoản quản trị',
                'admin/bulk*' => 'Thao tác nhiều domain',
                'admin/activity*' => 'Lịch sử & vận hành',
                'admin/pages*' => 'Trang & tác phẩm',
                'admin/fanmulu' => 'Phim chính & fanmulu theo domain',
                'admin/batches*' => 'Lịch sử áp dụng',
                'admin/baidu*' => 'Baidu & trạng thái index',
                'admin' => 'Tổng quan domain',
            ];
            $globalNavigationLabel = collect($globalNavigationLabels)->first(fn ($label, $pattern) => request()->is($pattern));
            $fixedDomainContext = $site && request()->is('admin/sites/*/edit', 'admin/sites/*/presentation', 'admin/sites/*/cache', 'admin/sites/*/fanmulu', 'admin/sites/*/advertising', 'admin/pages/*', 'admin/batches/*');
        @endphp
        <nav aria-label="Điều hướng chính">
            @foreach($primaryNavigation as $url => $entry)
                @php($isCurrent = request()->is(...$entry['patterns']) && ($url !== '/admin/sites' || ! request()->is('admin/sites/*/fanmulu', 'admin/sites/bulk-edit', 'admin/sites/bulk-edit/*')))
                <a href="{{ $url }}" class="{{ $isCurrent ? 'current' : '' }}" @if($isCurrent) aria-current="page" @endif>{{ $entry['label'] }}</a>
            @endforeach
        </nav>
        <footer>Nội dung và keyword<br>được lưu ổn định</footer>
    </aside>
    <div class="main">
        <header class="topbar">
            <button class="menu-button secondary" @click="menuOpen = !menuOpen" aria-label="Mở menu" aria-controls="admin-navigation" :aria-expanded="menuOpen">☰</button>
            @if($fixedDomainContext)
                <a class="management-context" href="/admin/sites/{{ $site->id }}/edit">{{ $siteLabels->label($site) }} <small>· #{{ $site->id }}</small></a>
            @else
                <span class="management-context">{{ $managementLabel ?? $globalNavigationLabel ?? 'Quản lý domain' }}</span>
            @endif
            <div class="account"><a href="/admin/account">{{ auth()->user()->name }}</a><form method="post" action="/logout">@csrf<button class="secondary" style="font-size:11px;padding:7px 10px">Đăng xuất</button></form></div>
        </header>
        <main class="main-inner">
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if(session('shared_apply_result'))
                @php($sharedResult = session('shared_apply_result'))
                @php($sharedDomainLabels = $siteLabels->labels(array_merge(array_keys($sharedResult['batch_ids']), array_keys($sharedResult['errors']))))
                <section class="notice" role="status"><strong>{{ $sharedResult['site_count'] }} domain · {{ $sharedResult['page_count'] }} trang đang dùng</strong><p>Yêu cầu được xử lý tự động. Theo dõi kết quả tại từng domain:</p><div class="actions">@foreach($sharedResult['batch_ids'] as $sharedSiteId => $sharedBatchId)<a href="/admin/sites/{{ $sharedSiteId }}/cache">Cache & kết quả {{ $sharedDomainLabels->get($sharedSiteId, 'Website #'.$sharedSiteId) }}</a>@endforeach</div>@if($sharedResult['site_count'] === 0)<p>Chưa có trang đang phục vụ dùng cấu hình này. Trang mới sẽ dùng cấu hình khi được tạo.</p>@endif @foreach($sharedResult['errors'] as $sharedSiteId => $sharedError)<p>{{ $sharedDomainLabels->get($sharedSiteId, 'Website #'.$sharedSiteId) }}: {{ $sharedError }}</p>@endforeach</section>
            @endif
            @if($errors->any())<div class="errornotice" role="alert"><strong>Chưa lưu được thao tác</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
