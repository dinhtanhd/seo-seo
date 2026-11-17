@extends('admin.layout')
@section('title','Biến template')
@section('content')
<div class="pagehead"><div><div class="eyebrow">SEO / BIẾN TÙY CHỈNH</div><h1>Biến template</h1><p>Mỗi trang chọn ngẫu nhiên một giá trị riêng rồi giữ cố định. Cùng biến trong Title, Description và Keywords của một trang luôn dùng cùng giá trị.</p></div><div class="actions"><a class="button" href="{{ route('admin.template-variables.create') }}">Tạo biến</a><a class="button secondary" href="{{ route('admin.template-variable-sets.create') }}">Tạo bộ giá trị trong DB</a><a href="/admin/templates">Template SEO →</a></div></div>
<p class="note">Nguồn file TXT/CSV được nhập vào DB thành phiên bản bền vững. Nguồn DB chọn một bộ giá trị đã lưu bên dưới, có thể dùng chung cho nhiều biến. Lựa chọn ngẫu nhiên không lặp cho đến khi dùng hết danh sách trên một website; hết danh sách mới sang lượt mới. Reload, xóa/dựng cache và sửa công thức không tự chọn lại.</p>
<section class="card"><div class="sectionhead"><h2>Biến đã tạo</h2><span class="badge">{{ $variables->count() }} biến</span></div><div class="tablewrap"><table><thead><tr><th>Biến chèn vào template</th><th>Nguồn</th><th>Giá trị</th><th>Trạng thái</th><th></th></tr></thead><tbody>
@forelse($variables as $variable)
    <tr><td><strong>{{ $variable->name }}</strong><br><code>{{ '{'.'{var.'.$variable->slug.'}'.'}' }}</code><br><small>#{{ $variable->id }} · phiên bản nguồn #{{ $variable->current_version_id }}</small></td><td>{{ $variable->source_kind === 'database' ? 'Bộ giá trị trong DB' : 'File / danh sách nhập' }}@if($variable->set_id)<br><small>Bộ #{{ $variable->set_id }}</small>@endif</td><td>{{ number_format($variable->value_count) }} giá trị<br><small>{{ implode(' · ', array_slice($variable->values, 0, 3)) }}</small></td><td><span class="badge {{ $variable->status === 'active' ? 'live' : 'paused' }}">{{ $variable->status === 'active' ? 'Đang dùng' : 'Tạm dừng chọn mới' }}</span></td><td><a href="{{ route('admin.template-variables.edit',['id'=>$variable->id]) }}">Chỉnh sửa →</a></td></tr>
@empty
    <tr><td colspan="5" class="empty">Chưa có biến. Tạo biến như <code>@{{var.watch}}</code> từ file hoặc bộ giá trị trong DB.</td></tr>
@endforelse
</tbody></table></div></section>
<section class="card"><div class="sectionhead"><h2>Bộ giá trị trong DB</h2><a href="{{ route('admin.template-variable-sets.create') }}">Tạo bộ mới →</a></div><p class="muted">Nhập danh sách trực tiếp hoặc tải file một lần. Sau đó chọn bộ này khi tạo biến có nguồn DB. Sửa danh sách tạo phiên bản mới, các trang đã chốt tiếp tục giữ giá trị của chúng.</p><div class="tablewrap"><table><thead><tr><th>Tên bộ</th><th>Số giá trị</th><th>Mẫu</th><th></th></tr></thead><tbody>
@forelse($sets as $valueSet)
    <tr><td>{{ $valueSet->name }}<br><small>#{{ $valueSet->id }} · phiên bản #{{ $valueSet->current_version_id }}</small></td><td>{{ number_format($valueSet->value_count) }}</td><td>{{ implode(' · ',array_slice($valueSet->values,0,3)) }}</td><td><a href="{{ route('admin.template-variable-sets.edit',['id'=>$valueSet->id]) }}">Chỉnh sửa →</a></td></tr>
@empty
    <tr><td colspan="4" class="empty">Chưa có bộ giá trị trong DB.</td></tr>
@endforelse
</tbody></table></div></section>
@endsection
