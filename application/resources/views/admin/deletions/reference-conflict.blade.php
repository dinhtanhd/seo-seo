@extends('admin.layout')
@section('title','Cấu hình chọn mục đã xóa')
@section('content')
<div class="pagehead"><div><h1>Chưa lưu được cấu hình</h1><p>Một mục được chọn đã bị xóa trước khi cấu hình được lưu. Dữ liệu của lần lưu này được giữ nguyên.</p></div></div>
<section class="card"><p>Khôi phục mục trong thùng rác hoặc tải lại biểu mẫu để chọn mục khác.</p><a class="button" href="/admin/deletions?status=trash">Mở thùng rác</a></section>
@endsection
