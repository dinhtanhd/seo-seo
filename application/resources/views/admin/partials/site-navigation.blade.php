@if($site)
    @php
        $websiteTabs = [
            'settings' => ['label' => 'Cấu hình', 'url' => '/admin/sites/'.$site->id.'/edit?tab=settings'],
            'pools' => ['label' => 'Kho keyword', 'url' => '/admin/sites/'.$site->id.'/edit?tab=pools'],
            'sources' => ['label' => 'Nguồn nội dung', 'url' => '/admin/sites/'.$site->id.'/edit?tab=sources'],
            'categories' => ['label' => 'Danh mục', 'url' => '/admin/sites/'.$site->id.'/edit?tab=categories'],
        ];
        if ($site->content_type === 'movie') {
            $websiteTabs['presentation'] = ['label' => 'Giao diện & đường dẫn', 'url' => '/admin/sites/'.$site->id.'/presentation'];
            $websiteTabs['fanmulu'] = ['label' => 'Phim chính & fanmulu', 'url' => '/admin/sites/'.$site->id.'/fanmulu'];
            $websiteTabs['advertising'] = ['label' => 'Quảng cáo JS', 'url' => '/admin/sites/'.$site->id.'/advertising'];
        }
        $websiteTabs['cache'] = ['label' => 'Quản lý cache', 'url' => '/admin/sites/'.$site->id.'/cache'];
    @endphp
    <nav class="site-editor-tabs" aria-label="Các phần quản lý website">
        @foreach($websiteTabs as $key => $entry)
            <a class="{{ ($websiteTab ?? 'settings') === $key ? 'current' : '' }}" href="{{ $entry['url'] }}" @if(($websiteTab ?? 'settings') === $key) aria-current="page" @endif>{{ $entry['label'] }}</a>
        @endforeach
    </nav>
@endif
