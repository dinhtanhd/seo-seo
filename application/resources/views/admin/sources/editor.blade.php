@extends('admin.layout')
@section('title', $source ? 'Chỉnh sửa nguồn' : 'Thêm nguồn nội dung')
@section('content')
@php
    $adapterLabels = ['maccms'=>'API MacCMS / Shandian','shandian'=>'API Shandian','json'=>'Nhập file JSON','csv'=>'Nhập file CSV','html'=>'Trang HTML · selector','fixture'=>'Fixture · seed / test / demo'];
    $statusLabels = ['active'=>'Đang hoạt động','paused'=>'Tạm dừng','archived'=>'Lưu trữ'];
@endphp
<div class="pagehead"><div><div class="eyebrow">CÀI ĐẶT NGUỒN DÙNG CHUNG</div><h1>{{ $source ? 'Chỉnh sửa nguồn' : 'Thêm nguồn nội dung' }}</h1><p>{{ $source ? $source->name.' · #'.$source->id : 'Tạo cấu hình kết nối một lần, sau đó gắn vào website cần sử dụng.' }}</p></div><a class="button secondary" href="/admin/sources">← Danh sách nguồn</a></div>
<section class="card source-editor"><h2>{{ $source ? 'Thông tin và kết nối nguồn' : 'Tạo nguồn dùng chung' }}</h2>
<form method="post" action="{{ $source ? '/admin/sources/'.$source->id.'/configure' : '/admin/sources' }}" x-data="tramSourceConfiguration(@js($createKind),@js($sourceFormValues))">@csrf
@if($source)<input type="hidden" name="row_version" value="{{ $isOldSourceForm && is_scalar(old('row_version')) ? old('row_version') : $source->row_version }}">@endif
<div class="formrow"><label>Tên nguồn<input name="name" required maxlength="200" value="{{ $isOldSourceForm && is_string(old('name')) ? old('name') : ($source->name ?? '') }}" placeholder="Ví dụ: Shandian · phim"></label>
@if($source)<label>Adapter<input value="{{ $adapterLabels[$createKind] }}" disabled><small>Giữ adapter để bảo toàn định danh và lịch sử. Tạo nguồn mới khi đổi loại kết nối.</small></label>@else<label>Adapter<select name="kind" x-model="kind">@foreach($adapterLabels as $kind=>$label)<option value="{{ $kind }}" @selected($createKind===$kind)>{{ $label }}</option>@endforeach</select></label>@endif</div>
@if($source)<label>Trạng thái nguồn<select name="status">@foreach($statusLabels as $state=>$label)<option value="{{ $state }}" @selected(($isOldSourceForm && is_string(old('status')) ? old('status') : $source->status)===$state)>{{ $label }}</option>@endforeach</select></label>@endif
@include('admin.sources.schedule')
@include('admin.sources.configuration')
<div class="crud-dialog-actions"><button>{{ $source ? 'Lưu thay đổi' : 'Tạo nguồn' }}</button><a class="button secondary" href="/admin/sources">Hủy</a></div>
</form></section>
<p class="muted">Lưu kết nối không đổi keyword đã gán hoặc chính sách phục vụ phim. Chọn website, thể loại và tự áp dụng tại Website & domain → Nguồn nội dung.</p>
@endsection
