@extends('admin.layout')
@section('title', 'Quảng cáo JS · '.$siteLabels->label($site))
@section('content')
@php
    $canManage = (bool) ($canManage ?? false);
    $storedRegular = (bool) data_get($settings, 'regular_enabled', false);
    $storedFanmulu = (bool) data_get($settings, 'fanmulu_enabled', false);
    $oldRegular = old('regular_enabled', $storedRegular);
    $oldFanmulu = old('fanmulu_enabled', $storedFanmulu);
    $regularValue = is_scalar($oldRegular) ? filter_var($oldRegular, FILTER_VALIDATE_BOOL) : $storedRegular;
    $fanmuluValue = is_scalar($oldFanmulu) ? filter_var($oldFanmulu, FILTER_VALIDATE_BOOL) : $storedFanmulu;
    $storedVersion = data_get($settings, 'row_version', 0);
    $oldVersion = old('row_version', $storedVersion);
    $versionValue = is_scalar($oldVersion) ? $oldVersion : $storedVersion;
    $globalEnabled = (bool) ($globalEnabled ?? false);
    $globalHasCode = (bool) ($globalHasCode ?? false);
@endphp
<div class="pagehead">
    <div><div class="eyebrow">WEBSITE #{{ $site->id }}</div><h1>Quảng cáo JS · {{ $siteLabels->label($site) }}</h1><p>Chọn khu vực sử dụng mã quảng cáo dùng chung trên domain này.</p></div>
    <div class="actions"><a class="button secondary" href="/admin/sites/bulk-edit">Sửa hàng loạt</a><a class="button secondary" href="/admin/sites">Website &amp; domain</a><a class="button secondary" href="{{ $site->base_url }}" target="_blank" rel="noopener">Mở website ↗</a></div>
</div>
@include('admin.partials.site-navigation', ['websiteTab' => 'advertising'])
@if(!$globalHasCode)
    <p class="note">Chưa có mã quảng cáo dùng chung. Lựa chọn ở website vẫn được lưu và sẽ có hiệu lực khi mã chung được thêm và bật.</p>
@elseif(!$globalEnabled)
    <p class="note">Mã quảng cáo dùng chung đang tắt. Lựa chọn của website vẫn được giữ và sẽ có hiệu lực khi mã chung được bật.</p>
@endif
<section class="card">
    <h2>Khu vực bật quảng cáo</h2>
    <p>Hai lựa chọn độc lập, áp dụng cho trang chủ, danh mục, chi tiết phim, phát phim và tìm kiếm trong từng khu vực.</p>
    <form method="post" action="/admin/sites/{{ $site->id }}/advertising">
        @csrf
        <input type="hidden" name="row_version" value="{{ $versionValue }}">
        <fieldset @disabled(!$canManage)>
            <div class="grid two">
                <div>
                    <input type="hidden" name="regular_enabled" value="0">
                    <label class="checkboxline"><input type="checkbox" name="regular_enabled" value="1" @checked($regularValue)>Bật quảng cáo ở phim chính</label>
                    <p class="muted">Các trang phim thông thường lấy nội dung từ nguồn đã gắn vào website.</p>
                    @error('regular_enabled')<p class="errornotice">{{ $message }}</p>@enderror
                </div>
                <div>
                    <input type="hidden" name="fanmulu_enabled" value="0">
                    <label class="checkboxline"><input type="checkbox" name="fanmulu_enabled" value="1" @checked($fanmuluValue)>Bật quảng cáo ở fanmulu</label>
                    <p class="muted">Các trang keyword và các trang điều hướng nằm trong khu vực fanmulu.</p>
                    @error('fanmulu_enabled')<p class="errornotice">{{ $message }}</p>@enderror
                </div>
            </div>
            @if($canManage)<div class="formfooter"><button>Lưu lựa chọn quảng cáo</button></div>@endif
        </fieldset>
    </form>
    @unless($canManage)<p class="note">Bạn có quyền xem. Người có quyền quản lý website này có thể lưu lựa chọn quảng cáo.</p>@endunless
</section>
<section class="card">
    <h2>Mã JS đang dùng</h2>
    <p>Website sử dụng mã quảng cáo được quản lý chung. Tắt quảng cáo giữ nguyên nội dung phim, keyword và JS của giao diện hoặc trình phát.</p>
    @if(auth()->user()->is_global_admin)<a class="button secondary" href="/admin/advertising">Mở mã JS &amp; đường dẫn dùng chung</a><a class="button secondary" href="/admin/advertising#tongji-config">Thống kê Tongji</a>@else<p class="muted">Liên hệ quản trị toàn hệ thống để thay mã quảng cáo.</p>@endif
</section>
@endsection
