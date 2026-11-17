@extends('admin.layout')
@section('title', $item ? 'Sửa phim · '.$values['title'] : 'Thêm phim vào kho')
@section('content')
@php
    $rowVersion = old('row_version', $item?->row_version ?? 1);
    $rowVersion = is_scalar($rowVersion) ? $rowVersion : ($item?->row_version ?? 1);
    $sourceValue = old('source_id', '');
    $sourceValue = is_scalar($sourceValue) ? (string) $sourceValue : '';
    $externalValue = old('external_id', '');
    $externalValue = is_scalar($externalValue) ? (string) $externalValue : '';
@endphp
<div class="pagehead"><div><div class="eyebrow">KHO PHIM DÙNG CHUNG</div><h1>{{ $item ? 'Sửa thông tin phim' : 'Thêm phim' }}</h1><p>{{ $item ? 'ID kho #'.$item->id.' · '.$sourceLabel : 'Thêm dữ liệu phim, thông tin diễn viên và các tập phát bằng form.' }}</p></div><div class="actions">@if($item && ($item->deleted_at ?? null) === null)<a class="button danger" href="/admin/deletions/movie/{{ $item->id }}">Xóa</a>@endif<a class="button secondary" href="/admin/movies">← Kho phim</a><a class="button secondary" href="/admin/sources">Nguồn nội dung</a></div></div>
@if($item)
    <section class="card"><div class="sectionhead"><h2>Phim trong kho</h2><span class="badge {{ $item->library_status === 'archived' ? 'archived' : 'active' }}">{{ $item->library_status === 'archived' ? 'Đã lưu trữ' : 'Đang sử dụng' }}</span></div><p>{{ number_format($page_count) }} trang website dùng phim này · {{ number_format($live_page_count) }} trang đang phục vụ.</p><p class="note">Lưu dữ liệu phim tự cập nhật các trang đang dùng và cho phép cập nhật, gồm trang chủ, danh mục, chi tiết, phát phim và fanmulu. Cache được làm mới; URL và keyword đã gán giữ nguyên. Không cần xuất bản thủ công.</p></section>
@else
    <p class="note">Phim mới được lưu vào kho chung; chưa tự tạo trang trên các website.</p>
@endif
<form method="post" action="{{ $item ? '/admin/movies/'.$item->id : '/admin/movies' }}" x-data="{ episodes: @js($episodeRows), episodesReady: false }" x-init="episodesReady = true">
    @csrf
    <input type="hidden" name="episodes_payload" value="" disabled :disabled="!episodesReady" :value="episodesReady ? JSON.stringify(episodes) : ''">
    @if($item)<input type="hidden" name="row_version" value="{{ $rowVersion }}">@endif
    <section class="card">
        <h2>Định danh nguồn</h2>
        @if($item)
            <div class="formrow"><label>Nguồn phim<input value="{{ $sourceLabel }} · #{{ $item->source_connector_id }}" readonly></label><label>ID phim bên nguồn<input value="{{ $item->external_id }}" readonly></label></div>
            <p class="muted">Định danh nguồn được giữ cố định để tránh tạo trùng phim khi crawl. Các thông tin bên dưới có thể sửa.</p>
        @else
            <div class="formrow"><label>Nguồn<select name="source_id"><option value="">Nguồn nhập tay dùng chung · tự tạo nếu chưa có</option>@foreach($sources->where('status', 'active')->whereNull('deleted_at') as $source)<option value="{{ $source->id }}" @selected($sourceValue === (string) $source->id)>{{ $source->name }}</option>@endforeach</select></label><label>ID bên nguồn<input name="external_id" maxlength="255" value="{{ $externalValue }}" placeholder="Để trống để tự tạo mã phim nhập tay"></label></div>
            <p class="muted">Chọn nguồn API khi bạn cần nhập phim thuộc nguồn đó. Nếu chưa dùng nguồn nào, để hệ thống dùng nguồn nhập tay; không cần JSON.</p>
        @endif
    </section>
    <section class="card">
        <h2>Thông tin phim</h2>
        <label>Tên phim<input name="title" required maxlength="1000" value="{{ $values['title'] }}" placeholder="Tên phim gốc"></label>
        <div class="formrow"><label>Thể loại / nhãn<input name="genre" maxlength="1000" value="{{ $values['genre'] }}" placeholder="剧情 / 动作"></label><label>ID thể loại bên nguồn<input name="category_external_id" maxlength="255" value="{{ $values['category_external_id'] }}"></label><label>Tên thể loại bên nguồn<input name="category_name" maxlength="255" value="{{ $values['category_name'] }}"></label></div>
        <div class="formrow"><label>Khu vực<input name="area" maxlength="1000" value="{{ $values['area'] }}" placeholder="中国大陆"></label><label>Năm<input name="year" maxlength="20" value="{{ $values['year'] }}" placeholder="2026"></label><label>Ngôn ngữ<input name="language" maxlength="1000" value="{{ $values['language'] }}" placeholder="汉语普通话"></label></div>
        <div class="formrow"><label>Diễn viên<textarea name="actors" rows="4" maxlength="20000" placeholder="Mỗi dòng một diễn viên">{{ $values['actors'] }}</textarea></label><label>Đạo diễn<textarea name="directors" rows="4" maxlength="20000" placeholder="Mỗi dòng một đạo diễn">{{ $values['directors'] }}</textarea></label></div>
        <label>Trạng thái tập / ghi chú<input name="remarks" maxlength="1000" value="{{ $values['remarks'] }}" placeholder="更新至第12集 / HD"></label>
        <div class="formrow"><label>URL poster<input name="poster_url" type="url" maxlength="4096" value="{{ $values['poster_url'] }}" placeholder="https://…"></label><label>URL trang nguồn<input name="source_url" type="url" maxlength="4096" value="{{ $values['source_url'] }}" placeholder="https://…"></label></div>
        <label>Giới thiệu / nội dung phim<textarea name="body_html" rows="10" maxlength="1048576">{{ $values['body_html'] }}</textarea><small>Có thể dùng HTML nội dung cơ bản. Hệ thống làm sạch thẻ và liên kết không an toàn khi lưu.</small></label>
    </section>
    <section class="card">
        <div class="sectionhead"><h2>Danh sách tập / nguồn phát</h2><button type="button" class="secondary" @click="episodes.push({ title: '', source_link: '', provider: '', external_id: '' })">Thêm tập</button></div>
        <input type="hidden" name="episodes_present" value="0" :value="episodesReady ? '1' : '0'">
        <p class="muted">Mỗi dòng là một tập và một nguồn phát. Có thể dùng nhiều dòng cùng tên tập với các nhà cung cấp khác nhau. Chỉ nhập URL HTTP/HTTPS hợp lệ, ví dụ liên kết m3u8.</p>
        <noscript><p class="note">Cần bật JavaScript để sửa danh sách tập. Nếu lưu các trường khác, danh sách tập hiện có được giữ nguyên.</p></noscript>
        <p x-show="episodes.length === 0" class="muted">Chưa có tập phim. Bấm Thêm tập để nhập.</p>
        <template x-for="(episode, index) in episodes" :key="index">
            <div class="card" style="padding:16px;margin:12px 0">
                <div class="formrow"><label>Tên tập<input x-model="episode.title" maxlength="1000" placeholder="第1集"></label><label>Nhà cung cấp<input x-model="episode.provider" maxlength="255" placeholder="Ví dụ: m3u8"></label></div>
                <label>URL phát<input x-model="episode.source_link" type="url" maxlength="4096" placeholder="https://…/index.m3u8"></label>
                <button type="button" class="danger" @click="episodes.splice(index, 1)">Bỏ tập khỏi danh sách</button>
            </div>
        </template>
    </section>
    <section class="card">
        <h2>Giữ bản sửa tay</h2>
        <input type="hidden" name="manual_update_locked" value="0">
        <label class="checkboxline"><input type="checkbox" name="manual_update_locked" value="1" @checked($manualLocked)>Khóa crawl để nguồn không ghi đè thông tin đã sửa tay</label>
        <p class="muted">Mặc định bật khi sửa thủ công. Bỏ chọn để cho phép các lần cập nhật từ nguồn thay dữ liệu trong kho.</p>
        <div class="formfooter"><button>{{ $item ? 'Lưu thông tin phim' : 'Thêm phim vào kho' }}</button><a href="/admin/movies">Quay lại kho phim</a></div>
    </section>
</form>
@if($item)
    <section class="card">
        <h2>Cập nhật từ nguồn</h2>
        @if($item->manual_update_locked)<p class="note">Phim đang khóa crawl. Bỏ khóa và lưu, hoặc dùng Mở khóa crawl trước khi cập nhật từ nguồn.</p>@endif
        <form method="post" action="/admin/movies/bulk">@csrf<input type="hidden" name="selection_mode" value="selected"><input type="hidden" name="movie_ids[]" value="{{ $item->id }}"><input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}"><div class="actions"><button name="action" value="refresh" @disabled($item->library_status === 'archived')>Cập nhật từ nguồn</button><button class="secondary" name="action" value="unlock_crawl">Mở khóa crawl</button></div></form>
        <p class="muted">Lượt cập nhật có báo cáo riêng. Phim nhập tay không có API phù hợp sẽ báo rõ không thể lấy lại dữ liệu.</p>
    </section>
    <section class="card">
        <h2>{{ $item->library_status === 'archived' ? 'Khôi phục trong kho' : 'Lưu trữ trong kho' }}</h2>
        <p>Lưu trữ trong kho; giữ các URL đang phục vụ. Phim lưu trữ không được dùng mới hoặc ghi đè bởi crawl. Khôi phục kho không tự khôi phục các trang website đã lưu trữ.</p>
        <form method="post" action="/admin/movies/{{ $item->id }}/status">@csrf<input type="hidden" name="row_version" value="{{ $item->row_version }}"><input type="hidden" name="status" value="{{ $item->library_status === 'archived' ? 'active' : 'archived' }}"><button class="{{ $item->library_status === 'archived' ? 'secondary' : 'danger' }}">{{ $item->library_status === 'archived' ? 'Khôi phục phim trong kho' : 'Lưu trữ phim · giữ URL đang phục vụ' }}</button></form>
    </section>
@endif
@endsection
