@extends('admin.layout')
@section('title','Bộ Template SEO')
@section('content')
@php
    $bundleTemplates = $templates->filter(fn ($template) => \App\Domain\Rules\SeoTemplateBundle::isBundle(json_decode($template->definition_json, true) ?? []))
        ->sortByDesc(fn ($template) => $movieStarterTemplates->has($template->id));
    $legacyTemplates = $templates->reject(fn ($template) => \App\Domain\Rules\SeoTemplateBundle::isBundle(json_decode($template->definition_json, true) ?? []));
    $canEditTemplate = (bool) auth()->user()->is_global_admin;
@endphp
<div class="pagehead">
    <div><div class="eyebrow">SEO / CÁC BỘ TEMPLATE</div><h1>Bộ Template SEO</h1><p>Mỗi bộ chứa cấu hình SEO cho trang chủ, danh sách, chi tiết, xem phim và tìm kiếm. Khi tạo website, chọn một bộ để áp dụng cho tất cả các kiểu trang.</p></div>
    @if($canEditTemplate)<div class="actions"><a class="button" href="/admin/templates/create">Tạo bộ Template SEO</a><a class="button secondary" href="/admin/deletions?kind=template">Xóa &amp; khôi phục hàng loạt</a><form method="post" action="{{ route('admin.templates.movie-starters') }}">@csrf<button class="secondary" @disabled(!$movieStarterLibraryReady)>Thêm 12 bộ mẫu SEO phim</button></form></div>@endif
</div>
<p class="note">Bộ Template SEO quy định tiêu đề, mô tả và các vị trí chèn keyword. Mở bộ cần sửa rồi bấm Lưu & áp dụng để cập nhật các website đang dùng. Giao diện HTML/CSS được quản lý tại <a href="/admin/themes">Giao diện phim</a>.</p>
<p class="note">Thư viện mẫu SEO phim gồm 12 cách viết tiêu đề, mô tả và keyword cho đủ năm kiểu trang, có biến năm, khu vực, đạo diễn, diễn viên và ngôn ngữ từ nguồn phim. Thêm mẫu chỉ lưu vào kho; bạn chọn mẫu khi cấu hình domain hoặc tạo bộ cấu hình website. Tham khảo <a href="https://ziyuan.baidu.com/college/articleinfo?id=2726" target="_blank" rel="noopener noreferrer">hướng dẫn tiêu đề của Baidu</a> và <a href="https://ziyuan.baidu.com/college/articleinfo?id=3405" target="_blank" rel="noopener noreferrer">hướng dẫn tối ưu tìm kiếm</a>. Các mẫu không phải chứng nhận của Baidu và không cam kết thứ hạng.</p>
<p class="muted"><strong>{{ number_format($bundleTemplates->count()) }} bộ Template SEO</strong> · {{ number_format($movieStarterTemplates->count()) }} mẫu tham khảo website phim lớn.</p>
@if(!$canEditTemplate)<p class="note">Bạn đang xem template dùng chung. Chỉ quản trị toàn hệ thống được chỉnh sửa.</p>@endif
<div class="grid two">
    @forelse($bundleTemplates as $template)
        <section class="card" id="template-{{ $template->id }}">
            <div class="sectionhead"><h2>{{ $template->name }}</h2><span class="badge">Bộ 5 kiểu trang</span></div>
            <p class="muted">#{{ $template->id }} · Phiên bản {{ $template->version_no }} · {{ $websiteCounts[$template->id] ?? 0 }} website đang chọn</p>
            <p>Trang chủ · Danh sách · Chi tiết · Xem phim · Tìm kiếm</p>
            @if($starter = $movieStarterTemplates->get($template->id))
                <p class="muted">{{ $starter['description'] }}</p>
                @if(!empty($starter['reference_brand']))<p>Tham khảo: @if(!empty($starter['reference_url']))<a href="{{ $starter['reference_url'] }}" target="_blank" rel="noopener noreferrer">{{ $starter['reference_brand'] }}</a>@else{{ $starter['reference_brand'] }}@endif · mẫu độc lập cho dữ liệu website của bạn.</p>@endif
                <details><summary>Công thức T/K/D trong bộ</summary>
                    @php
                        $currentDefinition = json_decode($template->definition_json, true) ?? [];
                    @endphp
                    @foreach(\App\Domain\Rules\SeoTemplateBundle::labels() as $role => $roleLabel)
                        <h3>{{ $roleLabel }}</h3><dl>
                        @foreach(['title' => 'T · Title', 'meta_keywords' => 'K · Meta keywords', 'description' => 'D · Description'] as $slot => $slotLabel)
                            @php
                                $slotConfiguration = $currentDefinition['page_templates'][$role]['slots'][$slot] ?? null;
                                $slotExpression = is_string($slotConfiguration) ? $slotConfiguration : (is_array($slotConfiguration) && is_string($slotConfiguration['template'] ?? null) ? $slotConfiguration['template'] : null);
                                $slotDisabled = $slotConfiguration === false || (is_array($slotConfiguration) && ($slotConfiguration['enabled'] ?? true) === false);
                            @endphp
                            <dt>{{ $slotLabel }}</dt>
                            <dd>
                                @if($slotDisabled)
                                    Đang tắt
                                @elseif($slotExpression !== null)
                                    <code>{{ $slotExpression }}</code>
                                @else
                                    Kế thừa cấu hình mặc định
                                @endif
                            </dd>
                        @endforeach
                        </dl>
                    @endforeach
                </details>
                <details><summary>T/K/D tham chiếu từ website gốc</summary>
                    @php
                        $reference = is_array($starter['reference'] ?? null) ? $starter['reference'] : [];
                        $referenceFetched = in_array($reference['status'] ?? '', ['ok', 'success', 'collected', 'fetched'], true)
                            || collect(['title', 'keywords', 'description'])->contains(fn ($field) => is_string($reference[$field] ?? null) && trim($reference[$field]) !== '');
                    @endphp
                    @if(is_string($reference['url'] ?? null) && \App\Domain\Rules\Html::safeUrl($reference['url']))
                        <p>Nguồn đối chiếu: <a href="{{ $reference['url'] }}" target="_blank" rel="noopener noreferrer">{{ $reference['url'] }}</a></p>
                    @endif
                    @if(!empty($reference['device']) || !empty($reference['page_kind']))
                        <p class="muted">Thiết bị: {{ ['desktop' => 'Máy tính', 'mobile' => 'Điện thoại'][$reference['device'] ?? ''] ?? ($reference['device'] ?? 'Chưa xác định') }} · Kiểu trang: {{ $reference['page_kind'] ?? 'Chưa xác định' }}</p>
                    @endif
                    <p class="muted">Dữ liệu thu thập để đối chiếu. Công thức của bộ dùng tên website và dữ liệu phim của bạn.</p><dl>
                    @foreach(['title' => 'T · Title', 'keywords' => 'K · Meta keywords', 'description' => 'D · Description'] as $field => $fieldLabel)
                        <dt>{{ $fieldLabel }}</dt><dd>{{ is_string($reference[$field] ?? null) && trim($reference[$field]) !== '' ? $reference[$field] : ($referenceFetched ? 'Không công bố trong HTML đã lấy' : 'Chưa lấy được') }}</dd>
                    @endforeach
                    </dl>@if(!empty($reference['checked_at']))<small>Kiểm tra: {{ $reference['checked_at'] }}</small>@endif
                </details>
            @endif
            <div class="actions"><a class="button secondary" href="/admin/templates/{{ $template->id }}/edit">{{ $canEditTemplate ? 'Chỉnh sửa bộ' : 'Xem cấu hình bộ' }}</a><a href="/admin/sites">Chọn cho website</a>@if($canEditTemplate)<a href="/admin/site-presets/create?template_id={{ $template->id }}">Dùng trong bộ cấu hình</a>@if(($template->deleted_at ?? null) === null)<a class="button danger" href="/admin/deletions/template/{{ $template->id }}">Xóa</a>@endif @endif</div>
        </section>
    @empty
        <p class="empty">Chưa có bộ Template SEO. Tạo một bộ gồm năm kiểu trang để chọn khi tạo website.</p>
    @endforelse
</div>
@if($legacyTemplates->isNotEmpty())
    <details class="card" @if(str_starts_with((string) old('form_context'), 'template:') || request('template_id')) open @endif>
        <summary>Mẫu cũ · {{ $legacyTemplates->count() }} mẫu</summary>
        <p class="muted">Các mẫu cũ vẫn hoạt động và giữ lịch sử. Dùng Lưu & áp dụng để cập nhật các trang đang phục vụ; tạo bộ mới để quản lý năm kiểu trang cùng một nơi.</p>
        @foreach($legacyTemplates as $template)
            @php $formContext = 'template:'.$template->id; @endphp
            <details class="card configuration-editor" id="template-{{ $template->id }}" @if(old('form_context') === $formContext || (int) request('template_id') === $template->id) open @endif>
                <summary>{{ $template->name }} · Mẫu cũ · v{{ $template->version_no }} · #{{ $template->id }}</summary>
                <form action="/admin/templates" method="post">
                    @csrf
                    <input type="hidden" name="id" value="{{ $template->id }}"><input type="hidden" name="row_version" value="{{ old('form_context') === $formContext && is_scalar(old('row_version')) ? old('row_version') : $template->row_version }}">
                    @include('admin.partials.template-configuration',['definition'=>json_decode($template->definition_json,true) ?? [],'templateName'=>$template->name,'canEditTemplate'=>$canEditTemplate,'submitLabel'=>'Lưu phiên bản mẫu cũ'])
                </form>
                @if($canEditTemplate && ($template->deleted_at ?? null) === null)<a class="button danger" href="/admin/deletions/template/{{ $template->id }}">Xóa</a>@endif
            </details>
        @endforeach
    </details>
@endif
@if($canEditTemplate && old('form_context') === 'template:new')
    <section class="card configuration-editor" id="template-create">
        <h2>Tạo mẫu cũ · khôi phục biểu mẫu chưa lưu</h2>
        <form action="/admin/templates" method="post">
            @csrf
            @include('admin.partials.template-configuration',['definition'=>\App\Domain\Support\TemplateConfigurationForm::defaults(),'templateName'=>'','canEditTemplate'=>true,'formContext'=>'template:new','submitLabel'=>'Lưu mẫu cũ'])
        </form>
    </section>
@endif
@endsection
