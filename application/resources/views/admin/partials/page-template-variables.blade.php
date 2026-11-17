@php
    $variableService = app(\App\Domain\Rules\TemplateVariableService::class);
    $variablesReady = method_exists($variableService,'ready') && $variableService->ready();
    $variablePublication = $page->current_publication_id ? $history->firstWhere('id',$page->current_publication_id) : null;
    $frozenVariables = $variablePublication ? (app(\App\Domain\Publishing\SnapshotStorage::class)->decode($variablePublication->snapshot_json)['custom_variables'] ?? []) : [];
    $storedVariables = $variablesReady ? $variableService->pagePins((int)$page->id) : [];
    $variableKeys = array_unique(array_merge(array_keys($frozenVariables),array_keys($storedVariables)));
    $canRerollVariables = auth()->user()->is_global_admin || \Illuminate\Support\Facades\DB::table('site_memberships')->where(['site_id'=>$site->id,'user_id'=>auth()->id()])->whereIn('role',['owner','operator'])->exists();
@endphp
@if($variablesReady)
<section class="card" id="template-variables"><div class="sectionhead"><h2>Biến template của trang</h2>@if(auth()->user()->is_global_admin)<a href="{{ route('admin.template-variables.index') }}">Quản lý biến →</a>@endif</div><p class="muted">Giá trị được chốt riêng cho trang này. Cùng biến trong Title, Description và Keywords dùng cùng giá trị; reload hoặc dựng cache không chọn lại. Trang phát dùng các giá trị đã lưu của phim.</p>
@if(session('template_variable_batch'))<p class="notice"><a href="/admin/batches/{{ session('template_variable_batch') }}?site_id={{ $site->id }}">Theo dõi kết quả áp dụng giá trị mới →</a></p>@endif
@if(count($variableKeys))
<div class="tablewrap"><table><thead><tr><th>Biến</th><th>Giá trị đang phục vụ</th><th>Lựa chọn đã lưu</th><th></th></tr></thead><tbody>
@foreach($variableKeys as $variableKey)
    @php
        $frozenVariable = $frozenVariables[$variableKey] ?? null;
        $storedVariable = $storedVariables[$variableKey] ?? null;
        $variableId = $storedVariable['variable_id'] ?? $frozenVariable['variable_id'] ?? null;
        $variablePending = $frozenVariable && $storedVariable && (($storedVariable['text'] ?? null) !== ($frozenVariable['text'] ?? null) || ($storedVariable['generation'] ?? null) !== ($frozenVariable['generation'] ?? null));
    @endphp
    <tr>
        <td><code>{{ '{'.'{'.$variableKey.'}'.'}' }}</code></td>
        <td>
            @if($frozenVariable)
                <strong>{{ $frozenVariable['text'] }}</strong><br>
                <small>Nguồn {{ ($frozenVariable['source_kind'] ?? '') === 'database' ? 'DB' : 'file' }} · phiên bản #{{ $frozenVariable['version_id'] ?? '—' }} · lần chọn {{ $frozenVariable['generation'] ?? 1 }}</small>
            @else
                <span class="muted">Chưa có bản phục vụ</span>
            @endif
        </td>
        <td>
            @if($storedVariable)
                {{ $storedVariable['text'] }}<br>
                <small>Lần chọn {{ $storedVariable['generation'] ?? 1 }}
                    @if($variablePending)
                        · chờ áp dụng
                    @endif
                </small>
            @else
                <span class="muted">Dùng giá trị của bản đã lưu</span>
            @endif
        </td>
        <td>
            @if($canRerollVariables && $variableId)
                <form method="post" action="{{ route('admin.pages.template-variables.reroll',['id'=>$page->id]) }}">
                    @csrf
                    <input type="hidden" name="row_version" value="{{ $page->row_version }}">
                    <input type="hidden" name="variable_ids[]" value="{{ $variableId }}">
                    <button class="secondary" @disabled($page->publish_locked || in_array($page->status,['archived','gone']))>Chọn lại giá trị</button>
                </form>
            @endif
        </td>
    </tr>
@endforeach
</tbody></table></div>
@if($canRerollVariables)<form method="post" action="{{ route('admin.pages.template-variables.reroll',['id'=>$page->id]) }}">@csrf<input type="hidden" name="row_version" value="{{ $page->row_version }}"><div class="formfooter"><button class="secondary" @disabled($page->publish_locked || in_array($page->status,['archived','gone']))>Chọn lại tất cả biến của trang</button></div></form>@endif
@else<p class="empty">Trang chưa dùng biến tùy chỉnh. Thêm biến vào Template SEO rồi Lưu &amp; áp dụng để chốt giá trị.</p>@endif
</section>
@endif
