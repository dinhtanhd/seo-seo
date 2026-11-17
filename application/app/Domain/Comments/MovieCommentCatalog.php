<?php

namespace App\Domain\Comments;

use App\Domain\Publishing\PublicHtmlCache;
use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MovieCommentCatalog
{
    public const LABEL = '示例评论';

    private function authorize(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
    }

    /** Hash-ring selection uses at most two bounded index scans, with no per-request writes. */
    public function sample(int $siteId, int $pageId, int $limit = 6, string $locale = 'zh-CN'): array
    {
        $limit = min(20, max(0, $limit));
        if ($siteId < 1 || $pageId < 1 || $limit === 0) {
            return [];
        }
        $pivot = hash('sha256', 'movie-comment-selection-v1:'.$siteId.':'.$pageId);
        $query = fn () => DB::table('movie_comment_samples')->where(['source' => 'sample', 'locale' => $locale, 'status' => 'active'])
            ->whereNull('deleted_at')->orderBy('selection_key');
        $rows = $query()->where('selection_key', '>=', $pivot)->limit($limit)->get(['id', 'body']);
        if ($rows->count() < $limit) {
            $rows = $rows->concat($query()->where('selection_key', '<', $pivot)->limit($limit - $rows->count())->get(['id', 'body']));
        }

        return $rows->map(fn ($row): array => ['id' => (int) $row->id, 'text' => $row->body,
            'author' => self::LABEL, 'label' => self::LABEL, 'is_sample' => true, 'source' => 'sample'])->values()->all();
    }

    /** Insert missing originals only; preserve edits, pause and recoverable deletion on rerun. */
    public function seed(int $actorId): array
    {
        $this->authorize($actorId);

        return DB::transaction(function () use ($actorId): array {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $this->authorize($actorId);
            $inserted = 0;
            $rows = [];
            $now = now();
            foreach (MovieCommentCorpus::texts() as $ordinal => $text) {
                $key = MovieCommentCorpus::VERSION.':'.$ordinal;
                $rows[] = ['seed_key' => $key, 'seed_version' => MovieCommentCorpus::VERSION, 'seed_ordinal' => $ordinal,
                    'selection_key' => hash('sha256', $key), 'locale' => 'zh-CN', 'body' => $text,
                    'body_hash' => hash('sha256', $text), 'source' => 'sample', 'status' => 'active',
                    'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now];
                if (count($rows) === 500) {
                    $inserted += DB::table('movie_comment_samples')->insertOrIgnore($rows);
                    $rows = [];
                }
            }
            if ($rows !== []) {
                $inserted += DB::table('movie_comment_samples')->insertOrIgnore($rows);
            }
            $total = DB::table('movie_comment_samples')->where('seed_version', MovieCommentCorpus::VERSION)->count();
            if ($total !== MovieCommentCorpus::COUNT) {
                throw new Conflict('MOVIE_COMMENT_SEED_COLLISION', 'Kho có nội dung/định danh trùng; chưa lưu bộ mẫu không đầy đủ.');
            }
            if ($inserted > 0) {
                $this->invalidate();
            }
            Access::audit('movie_comments.samples.seeded', $actorId, null, ['version' => MovieCommentCorpus::VERSION, 'inserted' => $inserted, 'total' => $total]);

            return ['inserted' => $inserted, 'total' => $total, 'version' => MovieCommentCorpus::VERSION];
        }, 3);
    }

    public function save(array $data, int $actorId): object
    {
        $this->authorize($actorId);
        $data = Validator::make($data, ['id' => 'nullable|integer|min:1', 'row_version' => 'required_with:id|integer|min:1',
            'body' => 'required|string|max:1000', 'locale' => 'required|string|max:16', 'status' => 'required|in:active,paused'])->validate();
        $text = trim((string) \Normalizer::normalize($data['body'], \Normalizer::FORM_C));
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8') || preg_match('/[<>\p{Cc}\p{Cf}]/u', $text)) {
            throw ValidationException::withMessages(['body' => 'Bình luận mẫu là văn bản một đoạn, không chứa HTML hoặc ký tự điều khiển.']);
        }
        if (! preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $data['locale'])) {
            throw ValidationException::withMessages(['locale' => 'Mã ngôn ngữ không hợp lệ.']);
        }

        return DB::transaction(function () use ($data, $text, $actorId): object {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $this->authorize($actorId);
            $current = null;
            if (! empty($data['id'])) {
                $current = DB::table('movie_comment_samples')->where('id', $data['id'])->lockForUpdate()->first() ?? abort(404);
            }
            if ($current && ($current->deleted_at !== null || (int) $current->row_version !== (int) $data['row_version'])) {
                throw new Conflict('MOVIE_COMMENT_VERSION_CONFLICT', 'Bình luận mẫu đã thay đổi hoặc nằm trong thùng rác.');
            }
            $hash = hash('sha256', $text);
            if (DB::table('movie_comment_samples')->where('body_hash', $hash)->when($current, fn ($q) => $q->where('id', '<>', $current->id))->exists()) {
                throw ValidationException::withMessages(['body' => 'Nội dung này đã có trong kho, kể cả thùng rác.']);
            }
            if ($current) {
                $id = $current->id;
                DB::table('movie_comment_samples')->where('id', $id)->update(['body' => $text, 'body_hash' => $hash,
                    'locale' => $data['locale'], 'status' => $data['status'], 'row_version' => $current->row_version + 1, 'updated_at' => now()]);
            } else {
                $key = 'manual:'.Str::uuid();
                $id = DB::table('movie_comment_samples')->insertGetId(['seed_key' => $key, 'selection_key' => hash('sha256', $key),
                    'locale' => $data['locale'], 'body' => $text, 'body_hash' => $hash, 'source' => 'sample',
                    'status' => $data['status'], 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->invalidate();
            Access::audit('movie_comments.sample.saved', $actorId, null, ['sample_id' => $id]);

            return DB::table('movie_comment_samples')->find($id);
        }, 3);
    }

    /** Atomic bulk CAS; deletion is recoverable and restore keeps samples paused. */
    public function mutate(array $data, int $actorId): int
    {
        $this->authorize($actorId);
        $data = Validator::make($data, ['ids' => 'required|array|min:1|max:10000', 'ids.*' => 'required|integer|min:1|distinct',
            'versions' => 'required|array|max:10000', 'versions.*' => 'required|integer|min:1',
            'action' => 'required|in:activate,pause,delete,restore'])->validate();
        $ids = array_map('intval', $data['ids']);

        return DB::transaction(function () use ($data, $ids, $actorId): int {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $this->authorize($actorId);
            $rows = DB::table('movie_comment_samples')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($rows->count() !== count($ids)) {
                throw new Conflict('MOVIE_COMMENT_VERSION_CONFLICT');
            }
            foreach ($rows as $row) {
                if ((int) ($data['versions'][$row->id] ?? 0) !== (int) $row->row_version
                    || (($data['action'] === 'restore') !== ($row->deleted_at !== null))) {
                    throw new Conflict('MOVIE_COMMENT_VERSION_CONFLICT');
                }
            }
            $attributes = ['row_version' => DB::raw('row_version+1'), 'updated_at' => now()];
            if ($data['action'] === 'delete') {
                $attributes += ['deleted_at' => now(), 'status' => 'paused'];
            } elseif ($data['action'] === 'restore') {
                $attributes += ['deleted_at' => null, 'status' => 'paused'];
            } else {
                $attributes['status'] = $data['action'] === 'activate' ? 'active' : 'paused';
            }
            DB::table('movie_comment_samples')->whereIn('id', $ids)->update($attributes);
            $this->invalidate();
            Access::audit('movie_comments.samples.'.$data['action'], $actorId, null, ['sample_ids' => $ids]);

            return count($ids);
        }, 3);
    }

    private function invalidate(): void
    {
        DB::table('system_settings')->where('id', 1)->increment('config_epoch');
        app(PublicHtmlCache::class)->invalidateAll();
    }
}
