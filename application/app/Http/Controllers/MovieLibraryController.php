<?php

namespace App\Http\Controllers;

use App\Domain\Ingestion\MovieLibraryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class MovieLibraryController extends Controller
{
    public function index(Request $request, MovieLibraryService $service): View
    {
        $this->authorizeManagement($request);
        $filters = $this->filters($request);
        $movies = $service->listingQuery($filters, $request->user()->id)->orderByDesc('c.id')->paginate(50)->appends($filters);
        $recentOperations = DB::table('movie_library_operations')->where('actor_id', $request->user()->id)->orderByDesc('created_at')->orderByDesc('id')
            ->limit(10)->get(['id', 'action', 'status', 'total', 'created_at']);

        return view('admin.movies.index', compact('movies', 'filters', 'recentOperations') + ['sources' => $this->sources(), 'requestKey' => (string) Str::uuid()] + $this->context());
    }

    public function create(Request $request): View
    {
        $this->authorizeManagement($request);

        return view('admin.movies.editor', $this->editorContext(null, []) + $this->context());
    }

    public function edit(Request $request, int $id, MovieLibraryService $service): View
    {
        $this->authorizeManagement($request);
        $detail = $service->find($id, $request->user()->id);

        return view('admin.movies.editor', $detail + $this->editorContext($detail['item'], $detail['record']) + $this->context());
    }

    public function store(Request $request, MovieLibraryService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $movie = $service->save(null, $this->input($request, false), $request->user()->id);

        return redirect('/admin/movies/'.$movie->id.'/edit')->with('status', 'Phim mới được lưu vào kho chung; chưa tự tạo trang trên các website.');
    }

    public function update(Request $request, int $id, MovieLibraryService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $service->save($id, $this->input($request, true), $request->user()->id);

        return redirect('/admin/movies/'.$id.'/edit')->with('status', 'Đã lưu dữ liệu phim. Các trang đang dùng và cho phép cập nhật tự nhận dữ liệu mới, cache được làm mới; URL và keyword đã gán giữ nguyên.');
    }

    public function status(Request $request, int $id, MovieLibraryService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate(['status' => 'required|in:active,archived', 'row_version' => 'required|integer|min:1']);
        $service->changeStatus($id, $data['status'], (int) $data['row_version'], $request->user()->id);

        return redirect('/admin/movies/'.$id.'/edit')->with('status', $data['status'] === 'archived'
            ? 'Đã lưu trữ phim trong kho; giữ các URL đang phục vụ và keyword đã gán.'
            : 'Đã khôi phục phim trong kho. Các trang website đã lưu trữ không tự khôi phục theo.');
    }

    public function bulk(Request $request, MovieLibraryService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate(['selection_mode' => 'required|in:selected,ids,filter', 'movie_ids' => 'nullable|array|max:10000',
            'movie_ids.*' => 'required|integer|min:1|distinct', 'movie_ids_text' => 'nullable|string|max:1048576',
            'confirm_all' => 'nullable|boolean', 'action' => 'required|in:archive,restore,unlock_crawl,refresh', 'idempotency_key' => 'required|uuid']);
        $filters = $this->filters($request);
        if ($data['selection_mode'] === 'filter') {
            if (! $request->boolean('confirm_all')) {
                throw ValidationException::withMessages(['confirm_all' => 'Xác nhận chọn tất cả phim khớp bộ lọc trước khi gửi yêu cầu.']);
            }
            $ids = $service->listingQuery($filters, $request->user()->id)->orderBy('c.id')->limit(10001)->pluck('c.id')->map(fn ($id) => (int) $id)->all();
        } elseif ($data['selection_mode'] === 'ids') {
            $tokens = preg_split('/[\s,;]+/u', trim($data['movie_ids_text'] ?? ''), flags: PREG_SPLIT_NO_EMPTY);
            $ids = [];
            foreach ($tokens as $token) {
                $value = filter_var($token, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($value === false) {
                    throw ValidationException::withMessages(['movie_ids_text' => 'Chỉ nhập ID số của phim, phân cách bằng xuống dòng, dấu phẩy hoặc khoảng trắng.']);
                }
                $ids[$value] = $value;
            }
            $ids = array_values($ids);
        } else {
            $ids = array_map('intval', $data['movie_ids'] ?? []);
        }
        if ($ids === [] || count($ids) > 10000) {
            throw ValidationException::withMessages(['movie_ids' => 'Chọn từ 1 đến 10.000 phim cho một lượt. Có thể chia thành nhiều lượt.']);
        }
        $operation = $service->submitBulk($ids, $data['action'], $request->user()->id, $data['idempotency_key']);

        return redirect('/admin/movies/operations/'.$operation->id)->with('status', 'Đã nhận yêu cầu xử lý kho phim theo nhóm. Theo dõi từng dòng bên dưới; chưa xác nhận toàn bộ đã xử lý xong.');
    }

    public function report(Request $request, string $id, MovieLibraryService $service): View
    {
        $this->authorizeManagement($request);
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:1000000', 'status' => 'nullable|in:pending,completed,conflict,failed']);
        $report = $service->report($id, $request->user()->id);
        $itemFilter = $data['status'] ?? '';
        $report['items'] = DB::table('movie_library_items as i')->where('i.operation_id', $id)
            ->leftJoin('content_items as c', 'c.id', '=', 'i.content_item_id')->leftJoin('content_revisions as r', 'r.id', '=', 'c.current_revision_id')
            ->select('i.*', 'c.external_id')->selectRaw("r.payload_json->>'title' AS title")
            ->when($itemFilter !== '', fn ($query) => $query->where('i.status', $itemFilter))->orderBy('i.id')->paginate(50)->appends(['status' => $itemFilter]);

        return view('admin.movies.report', $report + compact('itemFilter') + $this->context());
    }

    public function retry(Request $request, string $id, MovieLibraryService $service): RedirectResponse
    {
        $this->authorizeManagement($request);
        $service->retry($id, $request->user()->id);

        return redirect('/admin/movies/operations/'.$id)->with('status', 'Đã yêu cầu thử lại các dòng lỗi. Phim đã xử lý và dòng xung đột được giữ nguyên; xung đột cần yêu cầu mới.');
    }

    private function filters(Request $request): array
    {
        $data = $request->validate(['q' => 'nullable|string|max:200', 'source_id' => 'nullable|integer|min:1',
            'category_external_id' => 'nullable|string|max:255', 'year' => 'nullable|string|max:20',
            'status' => 'nullable|in:active,archived,all', 'library_status' => 'nullable|in:active,archived,all',
            'manual_update_locked' => 'nullable|in:0,1', 'page' => 'nullable|integer|min:1|max:1000000']);

        return ['q' => trim($data['q'] ?? ''), 'source_id' => $data['source_id'] ?? '',
            'category_external_id' => trim($data['category_external_id'] ?? ''), 'year' => trim($data['year'] ?? ''),
            'status' => $data['library_status'] ?? $data['status'] ?? 'active', 'manual_update_locked' => $data['manual_update_locked'] ?? ''];
    }

    private function input(Request $request, bool $editing): array
    {
        if ($request->filled('episodes_payload')) {
            if (is_string($request->input('episodes_payload')) && strlen($request->input('episodes_payload')) > 8388608) {
                throw ValidationException::withMessages(['episodes_payload' => 'Danh sách tập vượt quá 8 MB. Giảm dữ liệu trong một lượt lưu.']);
            }
            $payload = $request->validate(['episodes_payload' => 'required|string|json|max:8388608']);
            if (! str_starts_with(ltrim($payload['episodes_payload']), '[')) {
                throw ValidationException::withMessages(['episodes' => 'Danh sách tập phải là một danh sách hợp lệ. Tải lại form rồi thử lưu lại.']);
            }
            try {
                $episodes = json_decode($payload['episodes_payload'], true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['episodes_payload' => 'Danh sách tập không hợp lệ hoặc có cấu trúc quá sâu. Tải lại form rồi thử lưu lại.']);
            }
            if (! is_array($episodes) || ! array_is_list($episodes)) {
                throw ValidationException::withMessages(['episodes' => 'Danh sách tập không hợp lệ. Tải lại form và thử lưu lại.']);
            }
            $request->merge(['episodes' => $episodes]);
        }
        $data = $request->validate(['row_version' => $editing ? 'required|integer|min:1' : 'nullable|integer|min:1',
            'source_id' => 'nullable|integer|min:1', 'external_id' => 'nullable|string|max:255', 'title' => 'required|string|max:1000',
            'genre' => 'nullable|string|max:1000', 'category_external_id' => 'nullable|string|max:255', 'category_name' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:1000', 'year' => 'nullable|string|max:20', 'language' => 'nullable|string|max:1000',
            'actors' => 'nullable|string|max:20000', 'directors' => 'nullable|string|max:20000', 'remarks' => 'nullable|string|max:1000',
            'poster_url' => 'nullable|string|max:4096', 'source_url' => 'nullable|string|max:4096', 'body_html' => 'nullable|string|max:1048576',
            'manual_update_locked' => 'nullable|boolean', 'episodes_present' => 'nullable|boolean', 'episodes' => 'nullable|array|max:10000', 'episodes.*' => 'array',
            'episodes.*.title' => 'nullable|string|max:1000', 'episodes.*.source_link' => 'nullable|string|max:4096',
            'episodes.*.provider' => 'nullable|string|max:255', 'episodes.*.external_id' => 'nullable|string|max:255']);
        foreach (['actors', 'directors'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = array_values(array_filter(array_map('trim', preg_split('/[\r\n\/]+/u', $data[$field] ?? '')), fn ($name) => $name !== ''));
            }
        }
        $data['manual_update_locked'] = $request->has('manual_update_locked') ? $request->boolean('manual_update_locked') : true;
        if ($request->has('episodes') || $request->boolean('episodes_present')) {
            $data['episodes'] = array_values(array_filter($data['episodes'] ?? [], fn ($episode) => trim((string) ($episode['title'] ?? '')) !== '' || trim((string) ($episode['source_link'] ?? '')) !== ''));
        }
        unset($data['episodes_present']);

        return $data;
    }

    private function editorContext(?object $item, array $record): array
    {
        $values = [];
        foreach (['title', 'genre', 'category_external_id', 'category_name', 'area', 'year', 'language', 'actors', 'directors', 'remarks', 'poster_url', 'source_url', 'body_html'] as $field) {
            $value = $record[$field] ?? '';
            if (in_array($field, ['actors', 'directors'], true) && is_array($value)) {
                $value = implode("\n", array_filter($value, 'is_scalar'));
            }
            $oldValue = old($field, $value);
            $values[$field] = is_scalar($oldValue) ? (string) $oldValue : '';
        }
        $episodes = old('episodes', $record['episodes'] ?? []);
        $episodeRows = [];
        foreach (is_array($episodes) ? $episodes : [] as $episode) {
            if (! is_array($episode)) {
                continue;
            }
            $row = [];
            foreach (['external_id', 'title', 'source_link', 'provider'] as $field) {
                $row[$field] = is_scalar($episode[$field] ?? null) ? (string) $episode[$field] : '';
            }
            $episodeRows[] = $row;
        }
        $lockedValue = old('manual_update_locked', $item?->manual_update_locked ?? true);
        $manualLocked = is_scalar($lockedValue) ? filter_var($lockedValue, FILTER_VALIDATE_BOOL) : true;
        $sourceLabel = $item ? DB::table('source_connectors')->where('id', $item->source_connector_id)->value('name') : null;

        return compact('item', 'values', 'episodeRows', 'manualLocked', 'sourceLabel') + ['sources' => $this->sources(), 'page_count' => 0, 'live_page_count' => 0];
    }

    private function sources(): Collection
    {
        return DB::table('source_connectors')->orderBy('name')->get(['id', 'name', 'kind', 'status', 'deleted_at']);
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()->is_global_admin, 403, 'Chỉ quản trị toàn hệ thống được quản lý kho phim dùng chung.');
    }

    private function context(): array
    {
        return ['site' => null, 'sites' => collect(), 'scope' => '', 'globalManagement' => true, 'managementLabel' => 'Kho phim dùng chung'];
    }
}
