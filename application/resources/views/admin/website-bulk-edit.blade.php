@extends('admin.layout')
@section('title', 'Sửa domain hàng loạt')
@section('content')
@php
    $selectionMode = old('selection_mode', 'selected');
    $selectionMode = in_array($selectionMode, ['selected', 'domains', 'filter'], true) ? $selectionMode : 'selected';
    $themeMode = old('theme_mode', 'keep') === 'set' ? 'set' : 'keep';
    $oldIds = old('site_ids', []);
    $selectedIds = is_array($oldIds) ? array_map('strval', array_filter($oldIds, 'is_scalar')) : [];
    $domainInput = old('domains', '');
    $domainInput = is_string($domainInput) ? $domainInput : '';
    $oldKey = old('idempotency_key', $requestKey);
    $formKey = is_string($oldKey) ? $oldKey : $requestKey;
    $selectedTheme = old('theme_id', '');
    $selectedTheme = is_scalar($selectedTheme) ? (string) $selectedTheme : '';
@endphp
<div class="pagehead">
    <div><div class="eyebrow">QUẢN LÝ NHIỀU DOMAIN</div><h1>Sửa domain hàng loạt</h1><p>Đổi giao diện, bật/tắt quảng cáo và xóa cache cho nhiều website phim trong một lượt.</p></div>
    <div class="actions"><a class="button secondary" href="/admin/sites">Website &amp; domain</a><a class="button secondary" href="/admin/advertising">JS quảng cáo dùng chung</a>@if(auth()->user()->is_global_admin && ($websiteDeletionAvailable ?? false))<a class="button secondary" href="/admin/sites/deleted">Website đã xóa</a>@endif</div>
</div>
<section class="card">
    <h2>Tìm domain cần sửa</h2>
    <form method="get" action="/admin/sites/bulk-edit" class="toolbar">
        <label>Tên miền chính hoặc alias<input name="q" value="{{ $filter }}" maxlength="200" placeholder="Ví dụ: xinghe.localhost"></label>
        <button>Lọc danh sách</button><a class="button secondary" href="/admin/sites/bulk-edit">Xóa bộ lọc</a>
    </form>
    <p class="muted">{{ number_format($websites->total()) }} website phim đang hoạt động khớp bộ lọc và thuộc quyền quản lý của bạn. Danh sách hiển thị 50 website mỗi trang.</p>
</section>
<form method="post" action="/admin/sites/bulk-edit" x-data="{ selectionMode: @js($selectionMode), themeMode: @js($themeMode) }">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ $formKey }}">
    <input type="hidden" name="q" value="{{ $filter }}">
    <section class="card">
        <h2>1. Chọn phạm vi domain</h2>
        <label class="checkboxline"><input type="radio" name="selection_mode" value="selected" x-model="selectionMode" @checked($selectionMode === 'selected')>Chọn website bằng ô đánh dấu bên dưới</label>
        <label class="checkboxline"><input type="radio" name="selection_mode" value="domains" x-model="selectionMode" @checked($selectionMode === 'domains')>Dán danh sách domain có sẵn</label>
        <label class="checkboxline"><input type="radio" name="selection_mode" value="filter" x-model="selectionMode" @checked($selectionMode === 'filter')>Chọn tất cả domain khớp bộ lọc hiện tại</label>
        <div x-show="selectionMode === 'domains'">
            <label>Danh sách domain<textarea name="domains" rows="7" maxlength="2097152" spellcheck="false" placeholder="domain-a.com&#10;domain-b.com">{{ $domainInput }}</textarea></label>
            <p class="muted">Mỗi dòng một domain hoặc URL gốc. Có thể dùng alias; nhiều domain cùng website chỉ được sửa một lần. Domain không tồn tại, đã lưu trữ hoặc không có quyền sửa sẽ bị từ chối.</p>
        </div>
        <div x-show="selectionMode === 'filter'">
            <p>Bộ lọc: <strong>{{ $filter !== '' ? $filter : 'Tất cả website phim đang hoạt động được phép sửa' }}</strong> · {{ number_format($websites->total()) }} website.</p>
            <label class="checkboxline"><input type="checkbox" name="confirm_all" value="1" @checked(old('confirm_all') === '1')>Tôi xác nhận chọn tất cả website khớp bộ lọc, kể cả các trang danh sách khác.</label>
        </div>
        <p class="note">Tối đa 10.000 website mỗi lượt. Khi chuyển trang hoặc lọc lại, các ô chọn ở trang hiện tại không được giữ; dùng danh sách domain hoặc chọn tất cả theo bộ lọc để sửa nhiều trang danh sách.</p>
        <div class="tablewrap"><table>
            <thead><tr><th>Chọn</th><th>Domain</th><th>Domain bổ sung</th><th>Cấu hình riêng</th></tr></thead>
            <tbody>
                @forelse($websites as $website)
                    <tr>
                        <td><input type="checkbox" name="site_ids[]" value="{{ $website->id }}" aria-label="Chọn {{ $website->admin_primary_domain }}" @checked(in_array((string) $website->id, $selectedIds, true)) @change="selectionMode = 'selected'"></td>
                        <td><strong class="code">{{ $website->admin_primary_domain }}</strong><div><small>Website #{{ $website->id }}</small></div></td>
                        <td>@foreach(($domains[$website->id] ?? collect())->where('host_ascii', '!=', $website->admin_primary_domain) as $domain)<div class="code">{{ $domain->host_ascii }}</div>@endforeach</td>
                        <td><div class="actions"><a href="/admin/sites/{{ $website->id }}/presentation">Giao diện</a><a href="/admin/sites/{{ $website->id }}/advertising">Quảng cáo</a></div></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">Không có domain đủ điều kiện trong bộ lọc này.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="pagination">{{ $websites->links() }}</div>
    </section>
    <section class="card">
        <h2>2. Chọn thay đổi</h2>
        <div class="formrow">
            <label>Giao diện website<select name="theme_mode" x-model="themeMode"><option value="keep" @selected($themeMode === 'keep')>Giữ nguyên giao diện từng website</option><option value="set" @selected($themeMode === 'set')>Đổi sang giao diện được chọn</option></select></label>
            <label x-show="themeMode === 'set'">Bộ giao diện mới<select name="theme_id"><option value="" @selected($selectedTheme === '')>Mặc định hệ thống</option>@foreach($themes as $theme)<option value="{{ $theme->id }}" @selected($selectedTheme === (string) $theme->id)>{{ $theme->name }}</option>@endforeach</select></label>
        </div>
        <p class="muted">Đổi giao diện áp dụng cho phim chính và fanmulu, xóa cache toàn website. Link được mở sẽ render giao diện mới; nội dung, keyword và URL đang có được giữ nguyên. Chỉ owner hoặc quản trị toàn hệ thống được đổi giao diện.</p>
        <div class="formrow">
            @foreach(['advertising_regular' => 'Quảng cáo ở phim chính', 'advertising_fanmulu' => 'Quảng cáo ở fanmulu'] as $field => $label)
                @php $advertisingValue = old($field, 'keep'); $advertisingValue = is_scalar($advertisingValue) ? (string) $advertisingValue : 'keep'; @endphp
                <label>{{ $label }}<select name="{{ $field }}"><option value="keep" @selected($advertisingValue === 'keep')>Giữ nguyên từng website</option><option value="1" @selected($advertisingValue === '1')>Bật</option><option value="0" @selected($advertisingValue === '0')>Tắt</option></select></label>
            @endforeach
        </div>
        <p class="muted">Mã JS được quản lý chung; lựa chọn ở đây chỉ bật/tắt hai khu vực độc lập trên các domain đã chọn.</p>
        <label class="checkboxline"><input type="checkbox" name="clear_cache" value="1" @checked(old('clear_cache') === '1')>Xóa toàn bộ cache của các website đã chọn</label>
        <p class="muted">Xóa cache không thay nội dung hoặc cấp lại keyword. Trang được dựng và lưu cache khi có người truy cập.</p>
    </section>
    <section class="card">
        <h2>3. Gửi yêu cầu</h2>
        <p>Hệ thống xử lý theo nhóm website và báo kết quả riêng từng domain. Website có cấu hình vừa thay đổi sẽ báo xung đột để bạn kiểm tra trước khi gửi yêu cầu mới.</p>
        <div class="formfooter"><button>Tạo yêu cầu sửa hàng loạt</button>@if(auth()->user()->is_global_admin && ($websiteDeletionAvailable ?? false))<button type="submit" class="danger" formaction="/admin/sites/bulk-edit/delete" formnovalidate>Xóa website đã chọn</button>@endif<a href="/admin/sites">Quay lại danh sách website</a></div>
        @if(auth()->user()->is_global_admin && ($websiteDeletionAvailable ?? false))
            <p class="muted">Xóa website dùng đúng phạm vi đã chọn ở mục 1 và mở bước xác nhận domain trước khi thực hiện. Bạn có thể khôi phục từ mục Website đã xóa.</p>
        @endif
    </section>
</form>
@endsection
