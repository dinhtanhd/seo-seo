<?php

namespace App\Domain\Sites;

use App\Domain\Support\Access;
use App\Domain\Support\CatalogUsageGuard;
use App\Domain\Support\Conflict;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UrlSuffixCatalog
{
    public static function normalizeSuffix(mixed $suffix): string
    {
        if ($suffix === null || $suffix === '') {
            return '';
        }
        if (! is_string($suffix) || preg_match('/^\.[a-z][a-z0-9]{0,11}$/D', $suffix) !== 1) {
            throw new \InvalidArgumentException('Đuôi URL cần để trống hoặc có dạng .html, .asp; chỉ dùng chữ thường và số, tối đa 12 ký tự sau dấu chấm.');
        }

        return $suffix;
    }

    public function choices(): Collection
    {
        return collect([(object) ['id' => null, 'suffix' => '', 'label' => 'Không có đuôi', 'row_version' => 0, 'deleted_at' => null, 'builtin' => true]])
            ->concat(DB::table('url_suffixes')->whereNull('deleted_at')->orderBy('suffix')->get());
    }

    public function assertSelectable(mixed $suffix, mixed $currentSuffix = '', string $field = 'suffix'): void
    {
        $suffix = self::normalizeSuffix($suffix);
        if ($suffix === '' || $suffix === self::normalizeSuffix($currentSuffix)) {
            return;
        }
        if (! DB::table('url_suffixes')->where('suffix', $suffix)->whereNull('deleted_at')->sharedLock()->first(['id'])) {
            throw ValidationException::withMessages([$field => 'Đuôi '.$suffix.' không còn trong danh mục đang dùng. Giữ giá trị hiện tại hoặc chọn đuôi khác.']);
        }
    }

    public function authorizeManagement(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403, 'Chỉ quản trị toàn hệ thống được quản lý danh mục đuôi URL.');
    }

    public function catalog(int $actorId, string $deleted = 'active'): Collection
    {
        $this->authorizeManagement($actorId);

        return DB::table('url_suffixes')->when($deleted === 'active', fn ($query) => $query->whereNull('deleted_at'))
            ->when($deleted === 'trash', fn ($query) => $query->whereNotNull('deleted_at'))->orderBy('suffix')->orderBy('id')->get();
    }

    public function create(array $data, int $actorId): object
    {
        $this->authorizeManagement($actorId);
        $data = $this->fields($data);

        return DB::transaction(function () use ($data, $actorId): object {
            $this->lock($actorId);
            $this->assertUnique($data['suffix']);
            $id = DB::table('url_suffixes')->insertGetId($data + ['row_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            Access::audit('url-suffix.created', $actorId, null, ['id' => $id] + $data);

            return DB::table('url_suffixes')->find($id);
        }, 3);
    }

    public function update(int $id, array $data, int $actorId): object
    {
        $this->authorizeManagement($actorId);
        $fields = $this->fields($data);
        $version = Validator::make($data, ['row_version' => 'required|integer|min:1'])->validate()['row_version'];

        return DB::transaction(function () use ($id, $fields, $version, $actorId): object {
            $this->lock($actorId);
            $row = DB::table('url_suffixes')->whereNull('deleted_at')->lockForUpdate()->find($id) ?? abort(404);
            $this->assertVersion($row, (int) $version);
            $this->assertUnique($fields['suffix'], $id);
            DB::table('url_suffixes')->where('id', $id)->update($fields + ['row_version' => $row->row_version + 1, 'updated_at' => now()]);
            Access::audit('url-suffix.updated', $actorId, null, ['id' => $id, 'previous_suffix' => $row->suffix] + $fields);

            return DB::table('url_suffixes')->find($id);
        }, 3);
    }

    public function delete(int $id, int $rowVersion, int $actorId): object
    {
        return $this->lifecycle($id, $rowVersion, $actorId, false);
    }

    public function restore(int $id, int $rowVersion, int $actorId): object
    {
        return $this->lifecycle($id, $rowVersion, $actorId, true);
    }

    private function fields(array $data): array
    {
        $data = Validator::make($data, ['suffix' => 'required|string|max:13', 'label' => 'required|string|max:120'])->validate();
        try {
            $data['suffix'] = self::normalizeSuffix($data['suffix']);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['suffix' => $error->getMessage()]);
        }

        return $data;
    }

    private function lock(int $actorId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['url-suffix-catalog']);
        $this->authorizeManagement($actorId);
    }

    private function assertUnique(string $suffix, ?int $except = null): void
    {
        if (DB::table('url_suffixes')->where('suffix', $suffix)->when($except !== null, fn ($query) => $query->where('id', '<>', $except))->exists()) {
            throw ValidationException::withMessages(['suffix' => 'Đuôi URL này đã có. Mở mục hiện có để sửa hoặc khôi phục từ thùng rác.']);
        }
    }

    private function assertVersion(object $row, int $version): void
    {
        if ((int) $row->row_version !== $version) {
            throw new Conflict('URL_SUFFIX_VERSION_CONFLICT', 'Danh mục đuôi URL đã thay đổi. Hãy tải lại trước khi lưu.');
        }
    }

    private function lifecycle(int $id, int $rowVersion, int $actorId, bool $restore): object
    {
        $this->authorizeManagement($actorId);
        Validator::make(['row_version' => $rowVersion], ['row_version' => 'required|integer|min:1'])->validate();

        return DB::transaction(function () use ($id, $rowVersion, $actorId, $restore): object {
            $this->lock($actorId);
            $row = DB::table('url_suffixes')->lockForUpdate()->find($id) ?? abort(404);
            $this->assertVersion($row, $rowVersion);
            if (($row->deleted_at === null) === $restore) {
                throw new Conflict('URL_SUFFIX_STATE_CONFLICT', 'Trạng thái đuôi URL vừa thay đổi. Hãy tải lại danh sách.');
            }
            if (! $restore) {
                app(CatalogUsageGuard::class)->assertUnused('suffix', $id);
            }
            DB::table('url_suffixes')->where('id', $id)->update(['deleted_at' => $restore ? null : now(), 'row_version' => $row->row_version + 1, 'updated_at' => now()]);
            Access::audit($restore ? 'url-suffix.restored' : 'url-suffix.deleted', $actorId, null, ['id' => $id, 'suffix' => $row->suffix, 'label' => $row->label]);

            return DB::table('url_suffixes')->find($id);
        }, 3);
    }
}
