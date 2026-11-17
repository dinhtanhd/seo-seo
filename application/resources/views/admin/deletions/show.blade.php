@extends('admin.layout')
@section('title', 'Kiểm tra trước khi xóa')
@section('content')
@php
    $isDeleted = ($item->deleted_at ?? null) !== null;
    $canDelete = is_bool($usage['can_delete'] ?? null) ? $usage['can_delete'] : null;
    $reasons = is_array($usage['reasons'] ?? null) ? $usage['reasons'] : [];
@endphp
<div class="pagehead"><div><div class="eyebrow">XÓA &amp; THÙNG RÁC</div><h1>{{ $isDeleted ? 'Khôi phục mục dữ liệu' : 'Kiểm tra trước khi xóa' }}</h1><p>{{ $item->label }} · #{{ $item->id }}</p></div><a class="button secondary" href="/admin/deletions?kind={{ $kind }}&amp;status={{ $isDeleted ? 'trash' : 'active' }}">← Danh sách</a></div>
<section class="card">
    <h2>{{ $item->label }}</h2>
    @if($isDeleted)<p class="note">Mục này đang trong thùng rác. Khôi phục giữ nguyên ID và lịch sử; hệ thống sẽ kiểm tra trạng thái phù hợp khi thực hiện.</p>
    @elseif($canDelete === true)<p class="notice">Không có nơi sử dụng đang chặn thao tác. Hệ thống sẽ kiểm tra lại khi bạn xác nhận xóa.</p>
    @elseif($canDelete === false)<p class="errornotice">Chưa thể xóa vì vẫn còn dữ liệu hoặc cấu hình liên quan. Kiểm tra danh sách bên dưới.</p>
    @else<p class="note">Chưa xác định được đầy đủ nơi sử dụng. Thao tác xóa đang khóa; tải lại trang để kiểm tra lại.</p>@endif
    <div class="actions"><a class="button secondary" href="/admin/deletions/{{ $kind }}/{{ $item->id }}">Kiểm tra lại</a></div>
</section>
<section class="card"><h2>Nơi đang sử dụng / điều kiện liên quan</h2>
    @forelse($reasons as $reason)
        @php
            $reason = is_array($reason) ? $reason : ['label' => is_scalar($reason) ? (string) $reason : 'Cần kiểm tra dữ liệu liên quan'];
            $count = $reason['count'] ?? null;
            $knownCount = is_int($count) || (is_string($count) && ctype_digit($count));
            $samples = is_array($reason['samples'] ?? null) ? $reason['samples'] : [];
            $reasonUrl = is_string($reason['url'] ?? null) && App\Domain\Rules\Html::safeUrl($reason['url'], true) ? $reason['url'] : null;
        @endphp
        <div class="card"><h3>{{ $reason['label'] ?? 'Dữ liệu liên quan' }}</h3><p>{{ $knownCount ? (!empty($reason['has_more']) ? 'Tìm thấy ít nhất ' : '').number_format((int) $count).' mục' : 'Số lượng chưa xác định' }}</p>
            @if($samples !== [])<ul>@foreach($samples as $sample)
                @php
                    $sample = is_array($sample) ? $sample : ['label' => is_scalar($sample) ? (string) $sample : 'Mục liên quan'];
                    $sampleUrl = is_string($sample['url'] ?? null) && App\Domain\Rules\Html::safeUrl($sample['url'], true) ? $sample['url'] : null;
                @endphp
                <li>@if($sampleUrl)<a href="{{ $sampleUrl }}">{{ $sample['label'] ?? 'Mở mục liên quan' }}</a>@else{{ $sample['label'] ?? 'Mục liên quan' }}@endif @if(!empty($sample['site_id']))<small>· {{ $siteLabels->label((int) $sample['site_id']) }}</small>@endif</li>
            @endforeach</ul>@endif
            @if($reasonUrl)<div class="actions"><a class="button secondary" href="{{ $reasonUrl }}">{{ $reason['link_label'] ?? 'Mở nơi quản lý' }}</a></div>@endif
            @if(!empty($reason['has_more']))<p class="muted">Còn các mục liên quan khác. Mở nơi quản lý tương ứng để kiểm tra.</p>@endif
        </div>
    @empty<p class="muted">{{ $canDelete === true ? 'Không có mục đang sử dụng được báo cáo.' : 'Chưa có danh sách chi tiết để hiển thị.' }}</p>@endforelse
</section>
<section class="card"><h2>{{ $isDeleted ? 'Xác nhận khôi phục' : 'Xác nhận xóa' }}</h2><p>{{ $isDeleted ? 'Mục được đưa trở lại kho theo trạng thái cho phép. Các trang và lịch sử cũ được giữ nguyên.' : 'Mục sẽ chuyển vào thùng rác. Lịch sử và các bản nội dung đã lưu được giữ; không có thao tác ép xóa.' }}</p>
    <form method="post" action="/admin/deletions/{{ $kind }}/{{ $item->id }}/{{ $isDeleted ? 'restore' : 'delete' }}">@csrf<input type="hidden" name="row_version" value="{{ $item->row_version }}"><button class="{{ $isDeleted ? 'secondary' : 'danger' }}" @disabled(!$isDeleted && $canDelete !== true)>{{ $isDeleted ? 'Khôi phục mục này' : 'Xác nhận xóa vào thùng rác' }}</button></form>
</section>
@endsection
