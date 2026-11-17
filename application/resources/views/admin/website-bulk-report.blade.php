@extends('admin.layout')
@section('title', 'Kết quả thêm website hàng loạt')
@section('content')
@php
    $statusLabels = ['queued' => 'Đang chờ', 'running' => 'Đang tạo', 'ready' => 'Sẵn sàng', 'error' => 'Cần xử lý'];
    $isProcessing = (int) ($counts['queued'] ?? 0) + (int) ($counts['running'] ?? 0) > 0;
@endphp
<div @if($isProcessing) x-data x-init="setTimeout(() => window.location.reload(), 5000)" @endif>
<div class="pagehead"><div><div class="eyebrow">KẾT QUẢ THÊM DOMAIN</div><h1>Tạo website hàng loạt</h1><p>{{ number_format($counts['total'] ?? $operation->total) }} domain · {{ $isProcessing ? 'Đang tự xử lý, trang cập nhật mỗi 5 giây.' : 'Đã xử lý danh sách.' }}</p></div><div class="actions"><a class="button secondary" href="/admin/sites/bulk-create/{{ $operation->id }}">Tải lại tiến độ</a><a class="button secondary" href="/admin/sites">Website & domain</a><a class="button" href="/admin/sites/bulk-create">Thêm danh sách mới</a></div></div>
<p class="note">{{ !empty($options['random_seo']) ? 'Bộ SEO được chọn ngẫu nhiên một lần cho từng domain.' : 'Bộ SEO lấy từ bộ cấu hình đã chọn cho từng domain.' }} @if(($options['source_selection'] ?? null) === 'random_once')Mỗi website nhận một nguồn phim trong danh sách của bộ cấu hình. @endif Lựa chọn hiển thị bên dưới đã được lưu; thử lại giữ nguyên nguồn phim, tên hiển thị, đường dẫn, bộ SEO và giao diện của từng website.</p>
<section class="card"><div class="formrow">@foreach(['ready' => 'Sẵn sàng', 'running' => 'Đang tạo', 'queued' => 'Đang chờ', 'error' => 'Cần xử lý'] as $state => $label)<div><span class="muted">{{ $label }}</span><h2>{{ number_format($counts[$state] ?? 0) }}</h2></div>@endforeach</div>@if(!empty($distribution))<details><summary>Phân phối bộ cấu hình</summary><div class="tablewrap"><table><thead><tr><th>Bộ cấu hình</th><th>Số domain</th></tr></thead><tbody>@foreach($distribution as $presetName => $total)<tr><td>{{ $presetName }}</td><td>{{ number_format($total) }}</td></tr>@endforeach</tbody></table></div></details>@endif @if(($counts['error'] ?? 0) > 0)<form method="post" action="/admin/sites/bulk-create/{{ $operation->id }}/retry">@csrf<button class="secondary">Thử lại các dòng lỗi</button></form>@endif</section>
<section class="card">
    <h2>Kết quả từng domain</h2>
    <div class="tablewrap"><table>
        <thead><tr><th>#</th><th>Domain</th><th>Bộ cấu hình</th><th>Nguồn phim đã chọn</th><th>Giao diện HTML/CSS</th><th>Bộ SEO đã chọn</th><th>Trạng thái</th><th>Thao tác & kết quả</th></tr></thead>
        <tbody>
        @foreach($items as $item)
            <tr>
                <td>{{ $item->position }}</td>
                <td><strong class="code">{{ $item->domain }}</strong><div><small class="code">{{ $item->base_url }}</small></div>
                    @php
                        $frozenPresentation = is_array($item->presentation_settings ?? null) ? $item->presentation_settings : [];
                        $frozenRoutes = is_array($frozenPresentation['routes'] ?? null) ? $frozenPresentation['routes'] : [];
                        $frozenSuffixes = is_array($frozenPresentation['suffixes'] ?? null) ? $frozenPresentation['suffixes'] : [];
                        $frozenFanmulu = is_array($item->fanmulu_settings ?? null) ? $item->fanmulu_settings : [];
                        $frozenRule = is_array($frozenFanmulu['url_rule'] ?? null) ? $frozenFanmulu['url_rule'] : [];
                        $frozenMovieKey = is_string($frozenPresentation['movie_key'] ?? null) ? $frozenPresentation['movie_key'] : 'source_id';
                        $frozenCategoryKey = is_string($frozenPresentation['category_key'] ?? null) ? $frozenPresentation['category_key'] : 'id';
                        $frozenAlphabet = is_string($frozenRule['alphabet'] ?? null) ? $frozenRule['alphabet'] : 'alnum';
                        $movieKeyLabels = ['source_id'=>'ID nguồn', 'pinyin_id'=>'Pinyin có dấu gạch nối + ID', 'pinyin_compact_id'=>'Pinyin liền + ID'];
                        $categoryKeyLabels = ['id'=>'ID danh mục', 'pinyin_id'=>'Pinyin có dấu gạch nối + ID', 'pinyin_compact_id'=>'Pinyin liền + ID'];
                    @endphp
                    <details style="margin-top:8px"><summary>Giá trị đã chốt</summary>
                        <p><strong>Tên hiển thị:</strong> {{ $item->name }}</p>
                        @if($frozenRoutes !== [])
                            <dl>
                                @foreach(['home'=>'Trang chủ', 'list'=>'Danh mục', 'detail'=>'Chi tiết phim', 'play'=>'Phát phim', 'search'=>'Tìm kiếm'] as $routeType=>$routeLabel)
                                    @if(is_string($frozenRoutes[$routeType] ?? null))
                                        @php
                                            $frozenRouteSuffix = is_string($frozenSuffixes[$routeType] ?? null) ? $frozenSuffixes[$routeType] : '';
                                            $frozenRoutePath = $routeType === 'home' && $frozenRoutes[$routeType] === '/' && $frozenRouteSuffix !== '' ? '/index' : $frozenRoutes[$routeType];
                                        @endphp
                                        <dt>{{ $routeLabel }}</dt><dd class="code" style="margin-left:0;overflow-wrap:anywhere">{{ $frozenRoutePath }}{{ $routeType !== 'home' ? '/*' : '' }}{{ $frozenRouteSuffix }}</dd>
                                    @endif
                                @endforeach
                            </dl>
                            <p><strong>URL phim:</strong> {{ $movieKeyLabels[$frozenMovieKey] ?? 'Chưa ghi nhận' }}<br><strong>URL danh mục:</strong> {{ $categoryKeyLabels[$frozenCategoryKey] ?? 'Chưa ghi nhận' }}</p>
                        @else
                            <p class="muted">Chưa ghi nhận cấu hình URL cho đợt cũ.</p>
                        @endif
                        <p><strong>Fanmulu:</strong> {{ !empty($frozenFanmulu['enabled']) ? 'Đã bật' : 'Đang tắt' }}</p>
                        @if(is_string($frozenFanmulu['prefix'] ?? null))<p class="code" style="overflow-wrap:anywhere">{{ $frozenFanmulu['prefix'] }}/*{{ is_string($frozenFanmulu['suffix'] ?? null) ? $frozenFanmulu['suffix'] : '' }}</p>@endif
                        @if(($frozenRule['mode'] ?? '') === 'tokens' && is_string($frozenRule['template'] ?? null))
                            <p><strong>Mẫu URL fanmulu:</strong> <code>{{ $frozenRule['template'] }}</code></p>
                        @elseif(($frozenRule['mode'] ?? '') === 'random')
                            <p><strong>Mã fanmulu:</strong> Ngẫu nhiên {{ is_scalar($frozenRule['length'] ?? null) ? $frozenRule['length'] : '—' }} ký tự · {{ ['letters'=>'chữ cái', 'digits'=>'chữ số', 'alnum'=>'chữ và số'][$frozenAlphabet] ?? 'theo cấu hình' }}</p>
                        @elseif(($frozenRule['mode'] ?? '') === 'pattern')
                            <p><strong>Mã fanmulu:</strong> Ngày và phần ngẫu nhiên theo cấu hình đã chốt.</p>
                        @else
                            <p><strong>Mã fanmulu:</strong> Kiểu cũ theo bộ cấu hình.</p>
                        @endif
                    </details>
                </td>
                <td>{{ $item->preset_name }}<div><small>{{ !empty($item->fanmulu_settings['enabled']) ? 'Phim chính + fanmulu' : 'Chỉ phim chính' }}</small></div>@if(!empty($item->fanmulu_settings['enabled']))<div><small class="code">{{ $item->fanmulu_settings['prefix'] }}/*{{ $item->fanmulu_settings['suffix'] }}</small></div>@endif</td>
                <td>
                    @forelse($item->source_choices as $sourceChoice)
                        <div>{{ $sourceChoice['name'] ?? 'Chưa ghi nhận tên nguồn' }}<br><small>Nguồn #{{ $sourceChoice['id'] }}</small></div>
                    @empty
                        <span class="muted">Chưa ghi nhận nguồn cho đợt cũ.</span>
                    @endforelse
                </td>
                <td>{{ $item->theme_name ?: 'Chưa ghi nhận giao diện' }}@if(!empty($item->theme_id))<div><small>Giao diện #{{ $item->theme_id }}</small></div>@endif</td>
                <td>
                    <strong>{{ $item->seo_template_name ?? 'Chưa ghi nhận tên bộ SEO' }}</strong>
                    @if(!empty($item->seo_template_id))<div><small>Bộ SEO #{{ $item->seo_template_id }}</small></div>@endif
                    @if(!empty($item->seo_template_version_no))<div><small>Phiên bản v{{ $item->seo_template_version_no }}</small></div>@endif
                    @if(!empty($item->seo_template_version_id))
                        <div><small>ID phiên bản đã chốt #{{ $item->seo_template_version_id }}</small></div>
                    @else
                        <div><small>Chưa ghi nhận phiên bản SEO cho đợt cũ.</small></div>
                    @endif
                </td>
                <td><span class="badge {{ $item->status === 'ready' ? 'active' : ($item->status === 'error' ? 'error' : 'pending') }}">{{ $statusLabels[$item->status] ?? $item->status }}</span></td>
                <td>
                    @if($item->status === 'ready' && $item->site_id)
                        <div class="actions"><a class="button" href="{{ $item->base_url }}" target="_blank" rel="noopener">Mở website ↗</a><a class="button secondary" href="/admin/sites/{{ $item->site_id }}/edit">Chỉnh sửa</a><a href="/admin/sites/{{ $item->site_id }}/fanmulu">Fanmulu</a><a href="/admin/sites/{{ $item->site_id }}/cache">Cache</a></div>
                    @elseif($item->status === 'error')
                        <p>{{ $item->error_message ?: 'Có lỗi khi tạo domain này. Bấm thử lại hoặc kiểm tra bộ cấu hình.' }}</p>
                        @if($item->error_code)<div><small class="code">{{ $item->error_code }}</small></div>@endif
                        @if($item->site_id)<a href="/admin/sites/{{ $item->site_id }}/edit">Mở cấu hình website →</a>@endif
                    @else
                        <span class="muted">{{ $item->status === 'running' ? 'Đang tạo cấu hình và nội dung cho domain.' : 'Sẽ được xử lý tự động.' }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="pagination">{{ $items->links() }}</div>
    <p class="muted">Website sẵn sàng không cần qua bước preview hoặc xuất bản thủ công. Khi mở phim lần đầu, chế độ theo yêu cầu tạo trang và lưu cache theo bộ cấu hình đã gắn.</p>
</section>
</div>
@endsection
