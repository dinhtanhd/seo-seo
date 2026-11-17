@php
    $previousSnapshot = json_decode($item->expected_snapshot_json ?? '{}', true) ?? [];
    $nextSnapshot = $proposal['snapshot'] ?? [];
    $previousRouting = $previousSnapshot['routing'] ?? [];
    $nextRouting = $nextSnapshot['routing'] ?? [];
    $routeLabels = ['home'=>'Trang chủ','list'=>'Danh sách','detail'=>'Chi tiết','play'=>'Phát phim','search'=>'Tìm kiếm'];
@endphp
@if($nextRouting)
<h3 style="margin-top:22px">Đường dẫn & giao diện sẽ xuất bản</h3>
<div class="diff"><div><small>TRƯỚC · snapshot live khi tạo preview</small><p class="code">{{ $previousSnapshot['page']['path'] ?? 'Chưa có bản live' }}</p><p>Giao diện #{{ $previousSnapshot['theme']['id'] ?? $previousSnapshot['theme']['theme_id'] ?? 'hệ thống' }} · phiên bản #{{ $previousSnapshot['theme']['version_id'] ?? '—' }}</p></div><div><small>SAU · proposal đã lưu</small><p class="code">{{ $nextSnapshot['page']['path'] ?? '—' }}</p><p>Giao diện #{{ $nextSnapshot['theme']['id'] ?? $nextSnapshot['theme']['theme_id'] ?? 'hệ thống' }} · phiên bản #{{ $nextSnapshot['theme']['version_id'] ?? '—' }}</p></div></div>
<div class="tablewrap"><table><thead><tr><th>Kiểu trang</th><th>Prefix trước</th><th>Prefix sau</th><th>File HTML sau</th></tr></thead><tbody>@foreach($routeLabels as $key=>$label)<tr><td>{{ $label }}</td><td><code>{{ $previousRouting['routes'][$key] ?? 'Chưa cấu hình' }}</code></td><td><code>{{ $nextRouting['routes'][$key] ?? '—' }}</code></td><td><code>{{ $nextRouting['views'][$key] ?? $key }}.html</code></td></tr>@endforeach</tbody></table></div>
@if(isset($previousSnapshot['page']['path']) && ($previousSnapshot['page']['path'] !== ($nextSnapshot['page']['path'] ?? null)))<p class="note">Đường dẫn trang thay đổi: xuất bản sẽ chuyển URL có kiểm tra trùng và giữ redirect cho URL cũ. Bản live hiện tại vẫn phục vụ đến khi mục này xuất bản thành công.</p>@endif
@endif
