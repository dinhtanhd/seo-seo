<?php

namespace App\Domain\Sites;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WebsiteDisplayNames
{
    public const TABLE = 'website_display_name_settings';

    public static function defaults(): array
    {
        return ['xinghedianying', 'shandianyingyuan', 'yunhaidianying', 'qingfengyingyuan', 'liuguangdianying',
            'xingkongyingyuan', 'yueguangdianying', 'jinseyingyuan', 'lanhaidianying', 'huanxiangyingyuan'];
    }

    /** Normalize a saved list or a bulk request without reading or changing persisted settings. */
    public static function normalize(mixed $input, string $field = 'display_names'): array
    {
        if (is_string($input)) {
            if (strlen($input) > 1048576 || ! mb_check_encoding($input, 'UTF-8')) {
                self::fail($field, 'Danh sách tên cần là UTF-8 và tối đa 1 MB.');
            }
            if (preg_match('/(?![\r\n])[\p{Cc}\p{Cf}]/u', $input)) {
                self::fail($field, 'Tên website không được có ký tự điều khiển.');
            }
            $input = preg_split('/\R/u', $input) ?: [];
        }
        if (! is_array($input) || ! array_is_list($input) || count($input) > 10000) {
            self::fail($field, 'Nhập danh sách tên, mỗi dòng một tên website.');
        }
        $names = [];
        $seen = [];
        foreach ($input as $value) {
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $value)
                || str_contains($value, '<') || str_contains($value, '>')) {
                self::fail($field, 'Tên website cần là văn bản Unicode, không có HTML hoặc ký tự điều khiển.');
            }
            $value = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value);
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 200) {
                self::fail($field, 'Mỗi tên website tối đa 200 ký tự.');
            }
            if (! isset($seen[$value])) {
                $names[] = $value;
                $seen[$value] = true;
            }
            if (count($names) > 1000) {
                self::fail($field, 'Danh sách tối đa 1.000 tên website khác nhau.');
            }
        }
        if ($names === []) {
            self::fail($field, 'Nhập ít nhất một tên website.');
        }

        return $names;
    }

    public function get(int $actorId): array
    {
        $this->authorize($actorId);

        return $this->settings(DB::table(self::TABLE)->where('id', 1)->first() ?? abort(503, 'Danh sách tên website chưa được khởi tạo.'));
    }

    /** Saving names affects future bulk inputs only; no website or queued operation is renamed. */
    public function save(mixed $names, int $rowVersion, int $actorId): array
    {
        $this->authorize($actorId);
        $names = self::normalize($names);
        if ($rowVersion < 1) {
            self::fail('row_version', 'Tải lại danh sách tên trước khi lưu.');
        }

        return DB::transaction(function () use ($names, $rowVersion, $actorId): array {
            $row = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first() ?? abort(503, 'Danh sách tên website chưa được khởi tạo.');
            $this->authorize($actorId);
            if ((int) $row->row_version !== $rowVersion) {
                throw new Conflict('WEBSITE_DISPLAY_NAMES_CHANGED', 'Danh sách tên vừa được sửa. Tải lại trước khi lưu.');
            }
            DB::table(self::TABLE)->where('id', 1)->update(['names_json' => Json::encode($names), 'row_version' => $rowVersion + 1,
                'updated_by' => $actorId, 'updated_at' => now()]);
            Access::audit('website-display-names.saved', $actorId, null, ['names_count' => count($names), 'row_version' => $rowVersion + 1]);

            return $this->settings(DB::table(self::TABLE)->where('id', 1)->first());
        }, 3);
    }

    private function settings(object $row): array
    {
        return ['names' => Json::decode($row->names_json), 'row_version' => (int) $row->row_version,
            'updated_by' => $row->updated_by === null ? null : (int) $row->updated_by, 'updated_at' => $row->updated_at,
            'using_defaults' => $row->updated_by === null];
    }

    private function authorize(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403, 'Chỉ quản trị toàn hệ thống được quản lý danh sách tên website.');
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
