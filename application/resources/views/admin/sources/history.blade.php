@extends('admin.layout')
@section('title', 'Lịch sử nhận nội dung')
@section('content')
<div class="pagehead"><div><div class="eyebrow">VẬN HÀNH NGUỒN</div><h1>Lịch sử nhận nội dung</h1><p>Theo dõi các lượt crawl và nhập file; dữ liệu cũ được giữ cả khi gỡ nguồn.</p></div><a class="button secondary" href="/admin/sources">← Danh sách nguồn</a></div>
@include('admin.sources.run-history')
@endsection
