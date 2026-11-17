@extends('admin.layout')
@section('title','Báo cáo import keyword')
@php
    $counts = json_decode($run->counts, true) ?? [];
    $isPending = in_array($run->status, ['queued', 'running'], true);
    $processed = isset($counts['processed']) ? (int) $counts['processed'] : (int) $run->cursor;
    $total = isset($counts['total']) ? (int) $counts['total'] : null;
    $percent = $total > 0 ? min(100, max(0, (int) floor($processed * 100 / $total))) : null;
    $statusLabel = match ($run->status) {
        'queued' => $processed > 0 ? 'Chờ lượt xử lý tiếp theo' : 'Chờ xử lý',
        'running' => 'Đang xử lý',
        'succeeded' => $run->dry_run ? 'Đã kiểm tra xong' : 'Đã nhập xong',
        'partial' => 'Đã xử lý, có dòng lỗi',
        'failed' => 'Lỗi xử lý',
        default => 'Chưa rõ trạng thái',
    };
    $pendingNote = $run->status === 'running'
        ? 'Hệ thống đang xử lý các dòng tiếp theo.'
        : ($processed === 0 ? 'Yêu cầu đã vào hàng đợi, chưa có dòng nào được xử lý.' : 'Kết quả đã lưu đến dòng '.number_format($processed, 0, ',', '.').'. Các dòng còn lại đang chờ lượt xử lý tiếp theo.');
    $rowLabels = ['new' => 'Mới', 'duplicate' => 'Trùng', 'blank' => 'Dòng trống', 'restored' => 'Khôi phục', 'error' => 'Lỗi'];
@endphp
@if($isPending)
    @push('head')
        <meta http-equiv="refresh" content="5">
    @endpush
@endif
@section('content')
<div class="pagehead">
    <div>
        <div class="eyebrow">IMPORT KEYWORD / JOB #{{ $run->id }}</div>
        <h1>{{ $run->dry_run ? 'Kiểm tra trước khi nhập' : 'Kết quả import keyword' }}</h1>
        <p>{{ $pool->name }} · <span class="badge {{ $run->status }}">{{ $statusLabel }}</span></p>
    </div>
    <div class="actions">
        <a class="button secondary" href="{{ request()->fullUrl() }}">Tải lại kết quả</a>
        <a class="button secondary" href="/admin/keyword-imports/{{ $run->id }}/download">Tải báo cáo CSV</a>
    </div>
</div>

<section class="card" aria-labelledby="keyword-import-progress">
    <h2 id="keyword-import-progress">Tiến độ xử lý</h2>
    <p>Đã xử lý <strong>{{ number_format($processed, 0, ',', '.') }} / {{ $total === null ? 'chưa rõ tổng số' : number_format($total, 0, ',', '.') }}</strong> dòng
        @if($percent !== null)
            · {{ $percent }}%
        @endif
    </p>
    @if($total > 0)
        <progress value="{{ min($processed, $total) }}" max="{{ $total }}" aria-label="Tiến độ xử lý keyword" style="width:100%;height:16px;accent-color:var(--teal)">{{ $percent }}%</progress>
    @endif
    @if($isPending)
        <p class="muted">{{ $pendingNote }} Trang tự cập nhật mỗi 5 giây.</p>
    @endif
</section>

<div class="stats">
    @foreach(['total' => 'Dòng nhận', 'processed' => 'Đã xử lý', 'new' => $run->dry_run ? 'Keyword mới dự kiến' : 'Keyword mới', 'duplicate' => 'Trùng', 'error' => 'Lỗi'] as $key => $label)
        <div class="stat"><div class="label">{{ $label }}</div><div class="number">{{ isset($counts[$key]) ? number_format((int) $counts[$key], 0, ',', '.') : 'Chưa có dữ liệu' }}</div></div>
    @endforeach
</div>

<div class="card">
    <p class="note">
        @if($run->dry_run)
            Đây là bước kiểm tra dữ liệu, chưa thêm keyword vào kho.
            @if(in_array($run->status, ['succeeded', 'partial'], true))
                Xem báo cáo bên dưới, rồi bấm “Xác nhận nhập dữ liệu đã kiểm tra” để nhập vào kho.
            @else
                Sau khi kiểm tra xong, nút xác nhận nhập sẽ xuất hiện tại đây.
            @endif
        @else
            Keyword được lưu theo từng đợt. Các dòng trùng, trống hoặc lỗi không được tính là keyword mới.
        @endif
        Dòng trống: {{ $counts['blank'] ?? 'Chưa có dữ liệu' }} · Khôi phục: {{ $counts['restored'] ?? 'Chưa có dữ liệu' }}.
    </p>
    @if($run->error)
        <div class="errornotice">{{ $run->error }}</div>
    @endif
    <div class="actions">
        @if($run->dry_run && in_array($run->status, ['succeeded', 'partial'], true))
            <form method="post" action="/admin/keyword-imports/{{ $run->id }}/apply">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                <button>Xác nhận nhập dữ liệu đã kiểm tra</button>
            </form>
        @endif
        @if($run->status === 'failed')
            <form method="post" action="/admin/keyword-imports/{{ $run->id }}/retry">
                @csrf
                <button class="secondary">Thử lại từ tiến độ đã lưu</button>
            </form>
        @endif
        <a href="/admin/keyword-imports/{{ $run->id }}/download?errors_only=1">Tải riêng các dòng lỗi</a>
    </div>
</div>

<section class="card">
    <h2>Kết quả từng dòng đã commit</h2>
    <div class="tablewrap">
        <table>
            <thead><tr><th>Dòng</th><th>Keyword chuẩn hóa</th><th>Kết quả</th><th>Lỗi</th></tr></thead>
            <tbody>
                @forelse($rows as $row)
                    <tr><td>{{ $row->row_no }}</td><td>{{ $row->normalized_text ?? '—' }}</td><td><span class="badge {{ $row->status }}">{{ $rowLabels[$row->status] ?? 'Chưa rõ kết quả' }}</span></td><td>{{ $row->error ?? '—' }}</td></tr>
                @empty
                    <tr><td class="empty" colspan="4">Chưa có kết quả từng dòng.
                        @if($isPending)
                            Trang sẽ tự cập nhật khi có kết quả.
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination">{{ $rows->withQueryString()->links() }}</div>
</section>
@endsection
