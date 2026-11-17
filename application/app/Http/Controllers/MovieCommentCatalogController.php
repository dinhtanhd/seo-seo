<?php

namespace App\Http\Controllers;

use App\Domain\Comments\MovieCommentCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class MovieCommentCatalogController extends Controller
{
    private function context(Request $request): array
    {
        abort_unless($request->user()->is_global_admin, 403);

        return ['site' => null, 'sites' => collect(), 'globalManagement' => true, 'managementLabel' => 'Kho bình luận mẫu dùng chung'];
    }

    public function index(Request $request): View
    {
        $context = $this->context($request);
        $data = $request->validate(['q' => 'nullable|string|max:200', 'status' => 'nullable|in:active,paused',
            'trash' => 'nullable|boolean', 'per_page' => 'nullable|integer|in:25,50,100']);
        $trash = $request->boolean('trash');
        $rows = DB::table('movie_comment_samples')->where('source', 'sample')
            ->when($trash, fn ($q) => $q->whereNotNull('deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->when(! empty($data['q']), fn ($q) => $q->where('body', 'ilike', '%'.$data['q'].'%'))
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderBy('id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString();

        return view('admin.movie-comments.index', $context + compact('rows', 'trash'));
    }

    public function create(Request $request): View
    {
        return view('admin.movie-comments.form', $this->context($request) + ['sample' => null]);
    }

    public function edit(Request $request, int $id): View
    {
        $context = $this->context($request);
        $sample = DB::table('movie_comment_samples')->whereNull('deleted_at')->find($id) ?? abort(404);

        return view('admin.movie-comments.form', $context + compact('sample'));
    }

    public function save(Request $request, MovieCommentCatalog $catalog): RedirectResponse
    {
        $this->context($request);
        $catalog->save($request->only(['id', 'row_version', 'body', 'locale', 'status']), $request->user()->id);

        return redirect('/admin/movie-comments')->with('status', 'Đã lưu bình luận mẫu; cache HTML được làm mới, URL và keyword giữ nguyên.');
    }

    public function mutate(Request $request, MovieCommentCatalog $catalog): RedirectResponse
    {
        $this->context($request);
        $count = $catalog->mutate($request->only(['ids', 'versions', 'action']), $request->user()->id);
        $message = match ($request->input('action')) {
            'delete' => 'Đã chuyển '.$count.' bình luận mẫu vào thùng rác.',
            'restore' => 'Đã khôi phục '.$count.' bình luận mẫu ở trạng thái tạm dừng.',
            'activate' => 'Đã bật '.$count.' bình luận mẫu.',
            default => 'Đã tạm dừng '.$count.' bình luận mẫu.',
        };

        return redirect('/admin/movie-comments'.($request->boolean('return_trash') ? '?trash=1' : ''))->with('status', $message);
    }

    public function seed(Request $request, MovieCommentCatalog $catalog): RedirectResponse
    {
        $this->context($request);
        $result = $catalog->seed($request->user()->id);

        return redirect('/admin/movie-comments')->with('status', 'Đã thêm '.$result['inserted'].' mẫu. Bộ gốc có '.$result['total'].' bình luận; các dòng đã sửa, tạm dừng hoặc xóa được giữ nguyên.');
    }
}
