@extends('admin.layout')
@section('title',$valueSet ? 'Sửa bộ giá trị · '.$valueSet->name : 'Tạo bộ giá trị trong DB')
@section('content')
<div class="pagehead"><div><div class="eyebrow">BIẾN TEMPLATE / NGUỒN DATABASE</div><h1>{{ $valueSet ? 'Sửa bộ giá trị trong DB' : 'Tạo bộ giá trị trong DB' }}</h1><p>Bộ giá trị được lưu trong database và có thể dùng cho nhiều biến.</p></div><a class="button secondary" href="{{ route('admin.template-variables.index') }}">← Biến template</a></div>
@php $formString = fn ($key,$fallback='') => is_scalar(old($key)) ? (string)old($key) : $fallback; @endphp
<form method="post" enctype="multipart/form-data" action="{{ $valueSet ? route('admin.template-variable-sets.save',['id'=>$valueSet->id]) : route('admin.template-variable-sets.store') }}">@csrf
    @if($valueSet)<input type="hidden" name="row_version" value="{{ $formString('row_version',(string)$valueSet->row_version) }}">@endif
    <section class="card"><label>Tên bộ giá trị<input name="name" value="{{ $formString('name',$valueSet?->name ?? '') }}" maxlength="200" placeholder="Các cách diễn đạt xem phim" required></label><label>Nhập từ file TXT/CSV<input type="file" name="values_file" accept=".txt,.csv,text/plain,text/csv"><small>Tối đa 10 MB. TXT mỗi dòng một giá trị; CSV dùng cột text hoặc value. Nếu có file, dữ liệu trong file thay danh sách bên dưới.</small></label><label>Danh sách giá trị trong DB<textarea name="values" rows="14" placeholder="在线观看&#10;在线播放&#10;立即观看">{{ is_string(old('values')) ? old('values') : implode("\n",$valueSet?->values ?? []) }}</textarea><small>Mỗi dòng một giá trị. Tối đa 10.000 giá trị, 1.000 ký tự mỗi giá trị; gộp dữ liệu trùng.</small></label></section>
    <p class="note">Mỗi lần thay danh sách tạo một phiên bản mới. Các trang đã chọn giá trị giữ nguyên; trang mới và thao tác chọn lại dùng nguồn hiện tại.</p>
    <div class="formfooter"><button>Lưu bộ giá trị</button><a class="button secondary" href="{{ route('admin.template-variables.create') }}">Tạo biến dùng bộ này →</a></div>
</form>
@endsection
