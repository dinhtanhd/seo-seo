@extends('admin.layout')
@section('title', 'Lịch sử & vận hành')
@section('content')
@php
    $trash = $trash ?? false;
    $canManage = $canManage ?? false;
    $logCutoff = $logCutoff ?? 0;
    $jobCutoff = $jobCutoff ?? 0;
    $activityFilters = request()->only(['site_id', 'q', 'from', 'to', 'audit_page', 'jobs_page']);
    $activityFilters['trash'] = $trash ? '1' : '0';
    $activityBase = request()->except(['trash', 'audit_page', 'jobs_page']);
    $recordAction = $trash ? 'restore' : 'delete';
    $recordSections = [
        ['kind' => 'audit', 'title' => 'Bản ghi thay đổi', 'rows' => $logs, 'cutoff' => $logCutoff],
        ['kind' => 'failed_job', 'title' => 'Jobs lỗi toàn hệ thống', 'rows' => $failed, 'cutoff' => $jobCutoff],
    ];
@endphp
<div class="pagehead">
    <div>
        <div class="eyebrow">LỊCH SỬ / VẬN HÀNH</div>
        <h1>{{ $trash ? 'Thùng rác lịch sử & vận hành' : 'Lịch sử & vận hành' }}</h1>
        <p>{{ $site ? $siteLabels->label($site) : 'Tất cả domain và hoạt động chung được phép xem' }}.</p>
    </div>
    @if($canManage)
        <div class="actions">
            <a class="button {{ $trash ? 'secondary' : '' }}" href="/admin/activity{{ $activityBase ? '?'.http_build_query($activityBase) : '' }}">Đang hiển thị</a>
            <a class="button {{ $trash ? '' : 'secondary' }}" href="/admin/activity?{{ http_build_query($activityBase + ['trash' => 1]) }}">Thùng rác</a>
        </div>
    @endif
</div>
<section class="card">
    @if($canManage)<p class="note">Xóa chuyển bản ghi vào thùng rác để có thể khôi phục. Nội dung website, URL và keyword được giữ nguyên.</p>@endif
    <form method="get" action="/admin/activity" class="toolbar" aria-label="Lọc lịch sử theo domain">
        @if($trash)<input type="hidden" name="trash" value="1">@endif
        <label>Domain<select name="site_id"><option value="">Tất cả domain & hoạt động chung được phép</option>@foreach($siteLabels->sortSites($sites) as $availableSite)<option value="{{ $availableSite->id }}" @selected($site?->id === $availableSite->id)>{{ $siteLabels->label($availableSite) }}{{ $availableSite->status === 'archived' ? ' · đã lưu trữ' : '' }}</option>@endforeach</select></label>
        <label>Tìm hành động / queue<input type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="Ví dụ: source.updated"></label>
        <label>Từ ngày (UTC)<input type="date" name="from" value="{{ request('from') }}"></label>
        <label>Đến ngày (UTC)<input type="date" name="to" value="{{ request('to') }}"></label>
        <button class="secondary">Lọc lịch sử</button>
        <a href="/admin/activity{{ $trash ? '?trash=1' : '' }}">Xóa bộ lọc</a>
    </form>
</section>

@foreach($recordSections as $recordSection)
    @php
        $kind = $recordSection['kind'];
        $rows = $recordSection['rows'];
        $isAudit = $kind === 'audit';
        $cutoff = $recordSection['cutoff'];
        $recordFilters = $isAudit ? $activityFilters : array_diff_key($activityFilters, ['site_id' => true]);
        $total = method_exists($rows, 'total') ? $rows->total() : $rows->count();
        $recordLabel = $isAudit ? 'bản ghi thay đổi' : 'bản ghi job lỗi';
    @endphp
    <section class="card" x-data="{selected: []}">
        <div class="sectionhead">
            <h2>{{ $recordSection['title'] }}{{ $trash ? ' · thùng rác' : '' }}@if($isAudit || $canManage) <span class="muted">({{ number_format($total, 0, ',', '.') }})</span>@endif</h2>
            @if($canManage && $total > 0)
                <form method="post" action="/admin/activity/records" @submit="if (!confirm('{{ $trash ? 'Khôi phục tất cả '.$recordLabel.' trong kết quả lọc?' : 'Chuyển tất cả '.$recordLabel.' trong kết quả lọc vào thùng rác? Có thể khôi phục.' }}')) $event.preventDefault()">
                    @csrf
                    <input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="action" value="{{ $recordAction }}"><input type="hidden" name="scope" value="filtered"><input type="hidden" name="cutoff_id" value="{{ $cutoff }}">
                    @foreach($recordFilters as $field => $value)<input type="hidden" name="{{ $field }}" value="{{ $value }}">@endforeach
                    <button class="{{ $trash ? 'secondary' : 'danger' }}">{{ $trash ? 'Khôi phục tất cả kết quả lọc' : 'Xóa tất cả kết quả lọc' }}</button>
                </form>
            @endif
        </div>
        @if($isAudit || $canManage)
            @if($canManage && $rows->isNotEmpty())
                <form id="{{ $kind }}-selected" method="post" action="/admin/activity/records" class="actions" style="margin-bottom:16px" @submit="if (!confirm('{{ $trash ? 'Khôi phục các '.$recordLabel.' đã chọn?' : 'Chuyển các '.$recordLabel.' đã chọn vào thùng rác? Có thể khôi phục.' }}')) $event.preventDefault()">
                    @csrf
                    <input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="action" value="{{ $recordAction }}"><input type="hidden" name="scope" value="selected"><input type="hidden" name="cutoff_id" value="{{ $cutoff }}">
                    @foreach($recordFilters as $field => $value)<input type="hidden" name="{{ $field }}" value="{{ $value }}">@endforeach
                    <button class="{{ $trash ? 'secondary' : 'danger' }}" :disabled="selected.length === 0">{{ $trash ? 'Khôi phục mục đã chọn' : 'Xóa mục đã chọn' }}</button>
                    <span class="muted"><span x-text="selected.length">0</span> mục đã chọn trên trang.</span>
                </form>
            @endif
            <div class="tablewrap"><table><thead><tr>
                @if($canManage)<th><input type="checkbox" aria-label="Chọn tất cả {{ $recordLabel }} trên trang" @change="selected = $event.target.checked ? @js($rows->pluck('id')->map(fn ($id) => (string) $id)->values()) : []" :checked="selected.length > 0 && selected.length === {{ $rows->count() }}"></th>@endif
                @if($isAudit)<th>Thời gian</th><th>Tài khoản / hành động</th><th>Domain / trang / batch</th><th>Tham chiếu</th>@else<th>ID</th><th>Queue</th><th>Thời điểm lỗi</th>@endif
                @if($canManage)<th>Thao tác</th>@endif
            </tr></thead><tbody>
                @forelse($rows as $record)
                    <tr>
                        @if($canManage)<td><input type="checkbox" form="{{ $kind }}-selected" name="ids[]" value="{{ $record->id }}" x-model="selected" aria-label="Chọn {{ $recordLabel }} {{ $record->id }}"></td>@endif
                        @if($isAudit)
                            <td>{{ $record->created_at }}<br><small class="muted">#{{ $record->id }}</small></td>
                            <td>#{{ $record->actor_id }} · <strong>{{ $record->action }}</strong></td>
                            <td>{{ $record->site_id === null ? 'Toàn hệ thống' : $siteLabels->label($record->site_id) }}<br><small>Trang #{{ $record->page_id ?? '—' }} · batch #{{ $record->batch_id ?? '—' }}</small></td>
                            <td><details><summary>Xem tham chiếu</summary><pre class="code" style="max-width:480px;white-space:pre-wrap;overflow-wrap:anywhere">{{ $record->after_json ?? $record->before_json ?? '—' }}</pre></details></td>
                        @else
                            <td>#{{ $record->id }}</td><td>{{ $record->queue }}</td><td>{{ $record->failed_at }}</td>
                        @endif
                        @if($canManage)<td><button form="{{ $kind }}-row-{{ $record->id }}" class="{{ $trash ? 'secondary' : 'danger' }}">{{ $trash ? 'Khôi phục' : 'Xóa vào thùng rác' }}</button></td>@endif
                    </tr>
                @empty
                    <tr><td class="empty" colspan="{{ ($isAudit ? 4 : 3) + ($canManage ? 2 : 0) }}">{{ $trash ? 'Không có '.$recordLabel.' trong thùng rác khớp bộ lọc.' : 'Không có '.$recordLabel.' khớp bộ lọc trong phạm vi truy cập.' }}</td></tr>
                @endforelse
            </tbody></table></div>
            @if($canManage)
                @foreach($rows as $record)
                    <form id="{{ $kind }}-row-{{ $record->id }}" method="post" action="/admin/activity/records" @submit="if (!confirm('{{ $trash ? 'Khôi phục '.$recordLabel.' này?' : 'Chuyển '.$recordLabel.' này vào thùng rác? Có thể khôi phục.' }}')) $event.preventDefault()">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="action" value="{{ $recordAction }}"><input type="hidden" name="scope" value="selected"><input type="hidden" name="ids[]" value="{{ $record->id }}"><input type="hidden" name="cutoff_id" value="{{ $cutoff }}">
                        @foreach($recordFilters as $field => $value)<input type="hidden" name="{{ $field }}" value="{{ $value }}">@endforeach
                    </form>
                @endforeach
            @endif
            @if(method_exists($rows, 'links'))<div class="pagination">{{ $rows->links() }}</div>@endif
            @if(! $isAudit)<p class="muted">Bộ lọc domain chỉ áp dụng cho bản ghi thay đổi. Jobs lỗi là nhật ký vận hành chung.</p>@endif
        @else
            <p class="muted">Chỉ quản trị toàn hệ thống được xem queue vận hành chung.</p>
        @endif
    </section>
@endforeach
@endsection
