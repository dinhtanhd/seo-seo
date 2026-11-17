@extends('admin.layout')
@section('title', 'Chưa thể xóa')
@section('content')
@php
    $reasons = is_array($usage['reasons'] ?? null) ? $usage['reasons'] : [];
@endphp
<div class="pagehead"><div><div class="eyebrow">KIỂM TRA NƠI SỬ DỤNG</div><h1>Chưa thể xóa</h1><p>Mục #{{ $itemId }} đang được sử dụng. Dữ liệu được giữ nguyên.</p></div><a class="button secondary" href="/admin/deletions?kind={{ $kind }}">Xóa &amp; thùng rác</a></div>
<section class="card"><h2>Cần xử lý các liên kết đang sử dụng trước</h2><p>Kiểm tra nơi liên quan bên dưới. Sau khi gỡ sử dụng phù hợp, mở lại trang kiểm tra trước khi xóa.</p>
    @forelse($reasons as $reason)
        @php
            $reason = is_array($reason) ? $reason : ['label' => is_scalar($reason) ? (string) $reason : 'Cần kiểm tra dữ liệu liên quan'];
            $count = $reason['count'] ?? null;
            $knownCount = is_int($count) || (is_string($count) && ctype_digit($count));
            $samples = is_array($reason['samples'] ?? null) ? $reason['samples'] : [];
        @endphp
        <div class="card"><h3>{{ $reason['label'] ?? 'Dữ liệu liên quan' }}</h3><p>{{ $knownCount ? (!empty($reason['has_more']) ? 'Ít nhất ' : '').number_format((int) $count).' mục' : 'Số lượng chưa xác định' }}</p>
            @if($samples !== [])<ul>@foreach($samples as $sample)
                @php
                    $sample = is_array($sample) ? $sample : ['label' => is_scalar($sample) ? (string) $sample : 'Mục liên quan'];
                    $sampleUrl = is_string($sample['url'] ?? null) && App\Domain\Rules\Html::safeUrl($sample['url'], true) ? $sample['url'] : null;
                @endphp
                <li>@if($sampleUrl)<a href="{{ $sampleUrl }}">{{ $sample['label'] ?? 'Mở mục liên quan' }}</a>@else{{ $sample['label'] ?? 'Mục liên quan' }}@endif @if(!empty($sample['site_id']))<small>· {{ $siteLabels->label((int) $sample['site_id']) }}</small>@endif</li>
            @endforeach</ul>@endif
            @if(!empty($reason['has_more']))<p class="muted">Còn các mục liên quan khác. Mở nơi quản lý tương ứng để kiểm tra.</p>@endif
        </div>
    @empty<p class="note">Chưa có danh sách chi tiết để hiển thị. Mở trang kiểm tra để tải lại thông tin sử dụng.</p>@endforelse
    <div class="actions"><a class="button" href="/admin/deletions/{{ $kind }}/{{ $itemId }}">Mở kiểm tra trước khi xóa</a><a class="button secondary" href="/admin/deletions?kind={{ $kind }}">Quay lại danh sách</a></div>
</section>
@endsection
