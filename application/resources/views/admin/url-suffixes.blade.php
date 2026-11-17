@extends('admin.layout')
@section('title', 'Danh mục đuôi URL')
@section('content')
<div x-data>
<div class="pagehead"><div><div class="eyebrow">CÀI ĐẶT DÙNG CHUNG</div><h1>Danh mục đuôi URL</h1><p>Quản lý các đuôi để chọn cho trang chủ, danh sách, chi tiết, phát phim và tìm kiếm. Mỗi website lưu giá trị riêng.</p></div><div class="actions"><button type="button" @click="$refs.create.showModal()">Thêm đuôi URL</button><a class="button secondary" href="/admin/sites">← Website & domain</a></div></div>
<p class="note">Sửa hoặc xóa một mục chỉ đổi danh mục lựa chọn. Các website đang dùng và snapshot lịch sử giữ nguyên URL. Muốn đổi URL của website, mở Giao diện & đường dẫn rồi Lưu & áp dụng.</p>
<section class="card">
    <form method="get" action="/admin/url-suffixes" class="toolbar"><label>Hiển thị<select name="deleted"><option value="active" @selected($deleted==='active')>Chưa xóa</option><option value="trash" @selected($deleted==='trash')>Thùng rác</option><option value="all" @selected($deleted==='all')>Tất cả</option></select></label><button class="secondary">Lọc danh sách</button></form>
    <h2>{{ $suffixes->count() }} đuôi tùy chỉnh</h2>
    <div class="tablewrap"><table><thead><tr><th>Đuôi URL</th><th>Tên gợi nhớ</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
        <tr><td><strong>Không có đuôi</strong></td><td>Để trống · có sẵn trong hệ thống</td><td><span class="badge active">Luôn dùng được</span></td><td><span class="muted">Không sửa hoặc xóa</span></td></tr>
        @foreach($suffixes as $suffix)
            <tr><td><code>{{ $suffix->suffix }}</code><small class="site-directory-meta">#{{ $suffix->id }} · v{{ $suffix->row_version }}</small></td><td>{{ $suffix->label }}</td><td><span class="badge {{ $suffix->deleted_at ? 'archived' : 'active' }}">{{ $suffix->deleted_at ? 'Đã xóa' : 'Đang dùng trong danh mục' }}</span></td><td x-data><div class="crud-row-actions">
                @if($suffix->deleted_at)
                    <form method="post" action="/admin/url-suffixes/{{ $suffix->id }}/restore">@csrf<input type="hidden" name="row_version" value="{{ $suffix->row_version }}"><button class="secondary">Khôi phục</button></form>
                @else
                    <button type="button" class="secondary" aria-label="Sửa đuôi {{ $suffix->suffix }}" @click="$refs.edit.showModal()">Sửa</button><button type="button" class="danger" aria-label="Xóa đuôi {{ $suffix->suffix }}" @click="$refs.remove.showModal()">Xóa</button>
                @endif
            </div>
            @unless($suffix->deleted_at)
            <dialog class="crud-modal" x-ref="edit" aria-labelledby="suffix-edit-{{ $suffix->id }}" @if(old('form_context')==='suffix:'.$suffix->id) x-init="$el.showModal()" @endif><div class="crud-dialog-head"><h2 id="suffix-edit-{{ $suffix->id }}">Sửa đuôi URL</h2><button class="secondary" type="button" @click="$refs.edit.close()" aria-label="Đóng">×</button></div><div class="crud-dialog-body"><form method="post" action="/admin/url-suffixes/{{ $suffix->id }}">@csrf<input type="hidden" name="form_context" value="suffix:{{ $suffix->id }}"><input type="hidden" name="row_version" value="{{ old('form_context')==='suffix:'.$suffix->id ? old('row_version',$suffix->row_version) : $suffix->row_version }}"><label>Đuôi URL<input name="suffix" value="{{ old('form_context')==='suffix:'.$suffix->id ? old('suffix') : $suffix->suffix }}" maxlength="13" pattern="\.[a-z][a-z0-9]{0,11}" placeholder=".html" required></label><label>Tên gợi nhớ<input name="label" value="{{ old('form_context')==='suffix:'.$suffix->id ? old('label') : $suffix->label }}" maxlength="120" required></label><p class="note">Đuôi đã chọn tại website và lịch sử giữ nguyên. Tên mới chỉ dùng để hiển thị trong danh mục.</p><div class="crud-dialog-actions"><button>Lưu thay đổi</button><button type="button" class="secondary" @click="$refs.edit.close()">Hủy</button></div></form></div></dialog>
            <dialog class="crud-modal" x-ref="remove" aria-labelledby="suffix-remove-{{ $suffix->id }}"><div class="crud-dialog-head"><h2 id="suffix-remove-{{ $suffix->id }}">Xóa đuôi URL</h2><button type="button" class="secondary" @click="$refs.remove.close()" aria-label="Đóng">×</button></div><div class="crud-dialog-body"><p>Chuyển <strong>{{ $suffix->suffix }}</strong> vào thùng rác? Website đang dùng vẫn giữ đuôi này; các lựa chọn mới không dùng được cho đến khi khôi phục.</p><form method="post" action="/admin/url-suffixes/{{ $suffix->id }}/delete">@csrf<input type="hidden" name="row_version" value="{{ $suffix->row_version }}"><div class="crud-dialog-actions"><button class="danger">Chuyển vào thùng rác</button><button type="button" class="secondary" @click="$refs.remove.close()">Hủy</button></div></form></div></dialog>
            @endunless
            </td></tr>
        @endforeach
    </tbody></table></div>
</section>
<dialog class="crud-modal" x-ref="create" aria-labelledby="suffix-create" @if(old('form_context')==='suffix:new') x-init="$el.showModal()" @endif><div class="crud-dialog-head"><h2 id="suffix-create">Thêm đuôi URL</h2><button type="button" class="secondary" @click="$refs.create.close()" aria-label="Đóng">×</button></div><div class="crud-dialog-body"><form method="post" action="/admin/url-suffixes">@csrf<input type="hidden" name="form_context" value="suffix:new"><label>Đuôi URL<input name="suffix" value="{{ old('form_context')==='suffix:new' ? old('suffix') : '' }}" maxlength="13" pattern="\.[a-z][a-z0-9]{0,11}" placeholder=".html" required></label><small>Dấu chấm rồi chữ thường và số; bắt đầu bằng chữ, tối đa 12 ký tự sau dấu chấm.</small><label>Tên gợi nhớ<input name="label" value="{{ old('form_context')==='suffix:new' ? old('label') : '' }}" maxlength="120" placeholder="Trang HTML" required></label><div class="crud-dialog-actions"><button>Thêm vào danh mục</button><button type="button" class="secondary" @click="$refs.create.close()">Hủy</button></div></form></div></dialog>
</div>
@endsection
