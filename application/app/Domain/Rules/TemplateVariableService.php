<?php

namespace App\Domain\Rules;

use App\Domain\Support\Access;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use App\Domain\Support\SchemaAvailability;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TemplateVariableService
{
    public function ready(): bool
    {
        return app(SchemaAvailability::class)->hasTable('template_variables');
    }

    public function sets(): Collection
    {
        if (! $this->ready()) {
            return collect();
        }

        return DB::table('template_variable_sets as s')->leftJoin('template_variable_set_versions as v', 'v.id', '=', 's.current_version_id')
            ->orderBy('s.name')->get(['s.*', 'v.values_json', 'v.version_no'])->map(fn (object $row): object => $this->decorate($row));
    }

    public function variables(bool $activeOnly = false): Collection
    {
        if (! $this->ready()) {
            return collect();
        }

        return DB::table('template_variables as x')->leftJoin('template_variable_versions as v', 'v.id', '=', 'x.current_version_id')
            ->when($activeOnly, fn ($query) => $query->where('x.status', 'active'))->orderBy('x.slug')
            ->get(['x.*', 'v.values_json', 'v.version_no'])->map(fn (object $row): object => $this->decorate($row));
    }

    public function normalizeValues(string|array $input): array
    {
        if (is_string($input) && (strlen($input) > 10 * 1024 * 1024 || substr_count($input, "\n") > 20000)) {
            throw ValidationException::withMessages(['values' => 'Danh sách quá lớn; tối đa 10 MB và 10.000 giá trị.']);
        }
        $rows = is_string($input) ? preg_split('/\r\n|\r|\n/', $input) : $input;
        if (! array_is_list($rows) || count($rows) > 20000) {
            throw ValidationException::withMessages(['values' => 'Danh sách cần tối đa 10.000 dòng.']);
        }
        $unique = [];
        foreach ($rows as $index => $value) {
            if (! is_string($value)) {
                throw ValidationException::withMessages(['values' => 'Dòng '.($index + 1).' phải là văn bản.']);
            }
            $value = $this->text($value, 'values', 1000);
            if ($value !== '') {
                $unique[$value] = $value;
                if (count($unique) > 10000) {
                    throw ValidationException::withMessages(['values' => 'Danh sách cần tối đa 10.000 giá trị khác nhau.']);
                }
            }
        }

        return array_values($unique);
    }

    public function importFile(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'File không hợp lệ hoặc lớn hơn 10 MB.']);
        }
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['txt', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => 'Chọn file TXT hoặc CSV UTF-8.']);
        }
        $input = file_get_contents($file->getRealPath());
        if (! mb_check_encoding($input, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'File cần dùng UTF-8.']);
        }
        $input = preg_replace('/^\x{FEFF}/u', '', $input);
        if ($extension === 'txt') {
            return $this->normalizeValues($input);
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $input);
        rewind($stream);
        $rows = [];
        $column = 0;
        $first = true;
        try {
            while (($fields = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($first) {
                    $first = false;
                    $header = array_search('text', $fields, true);
                    $header = $header === false ? array_search('value', $fields, true) : $header;
                    if ($header !== false) {
                        $column = $header;

                        continue;
                    }
                }
                $rows[] = $fields[$column] ?? '';
                if (count($rows) > 10000) {
                    throw ValidationException::withMessages(['file' => 'Tối đa 10.000 dòng.']);
                }
            }
        } finally {
            fclose($stream);
        }

        return $this->normalizeValues($rows);
    }

    public function saveSet(array $data, int $actorId): object
    {
        $this->authorize($actorId);
        $name = $this->text($data['name'] ?? '', 'name', 200);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Nhập tên bộ giá trị.']);
        }
        $values = $this->normalizeValues($data['values'] ?? []);

        return DB::transaction(function () use ($data, $name, $values, $actorId): object {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $set = isset($data['id']) ? DB::table('template_variable_sets')->where('id', $data['id'])->lockForUpdate()->first() : null;
            $this->cas($set, $data);
            if (DB::table('template_variable_sets')->where('name', $name)->when($set, fn ($query) => $query->where('id', '<>', $set->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Tên bộ giá trị đã được sử dụng.']);
            }
            $id = $set?->id ?? DB::table('template_variable_sets')->insertGetId(['name' => $name, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            $version = DB::table('template_variable_set_versions')->insertGetId(['set_id' => $id,
                'version_no' => (DB::table('template_variable_set_versions')->where('set_id', $id)->max('version_no') ?? 0) + 1,
                'values_json' => Json::encode($values), 'hash' => Json::hash($values), 'created_by' => $actorId]);
            DB::table('template_variable_sets')->where('id', $id)->update(['name' => $name, 'current_version_id' => $version,
                'row_version' => $set ? DB::raw('row_version+1') : 1, 'updated_at' => now()]);
            foreach (DB::table('template_variables')->where(['source_kind' => 'database', 'set_id' => $id])->orderBy('id')->lockForUpdate()->get() as $variable) {
                $this->version($variable, $values, $version, $actorId);
            }
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            Access::audit('template-variable-set.saved', $actorId, null, ['set_id' => $id, 'version_id' => $version, 'value_count' => count($values)]);

            return DB::table('template_variable_sets')->find($id);
        }, 3);
    }

    public function saveVariable(array $data, int $actorId): object
    {
        $this->authorize($actorId);
        $slug = $data['slug'] ?? '';
        if (! is_string($slug) || ! preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $slug)) {
            throw ValidationException::withMessages(['slug' => 'Tên biến gồm chữ thường a-z, số và dấu _, bắt đầu bằng chữ; tối đa 60 ký tự.']);
        }
        $name = $this->text($data['name'] ?? $slug, 'name', 200);
        $source = $data['source_kind'] ?? '';
        $status = $data['status'] ?? 'active';
        if (! in_array($source, ['file', 'database'], true) || ! in_array($status, ['active', 'paused'], true)) {
            throw ValidationException::withMessages(['source_kind' => 'Nguồn hoặc trạng thái biến không hợp lệ.']);
        }
        $fallback = isset($data['fallback']) ? $this->text($data['fallback'], 'fallback', 1000) : null;

        return DB::transaction(function () use ($data, $slug, $name, $source, $status, $fallback, $actorId): object {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $variable = isset($data['id']) ? DB::table('template_variables')->where('id', $data['id'])->lockForUpdate()->first() : null;
            $this->cas($variable, $data);
            if ($variable && $variable->slug !== $slug) {
                throw ValidationException::withMessages(['slug' => 'Tên biến đã tạo được giữ cố định để template và lịch sử tiếp tục hoạt động.']);
            }
            if (! $variable && DB::table('template_variables')->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['slug' => 'Tên biến đã tồn tại.']);
            }
            $setVersion = null;
            $setId = null;
            if ($source === 'database') {
                $set = DB::table('template_variable_sets')->where(['id' => $data['set_id'] ?? 0, 'status' => 'active'])->first();
                if (! $set) {
                    throw ValidationException::withMessages(['set_id' => 'Chọn bộ giá trị đang hoạt động trong DB.']);
                }
                $setId = $set->id;
                $setVersion = $set->current_version_id;
                $values = Json::decode(DB::table('template_variable_set_versions')->where('id', $setVersion)->value('values_json'));
            } else {
                $values = array_key_exists('values', $data) ? $this->normalizeValues($data['values'])
                    : ($variable && $variable->source_kind === 'file' ? Json::decode(DB::table('template_variable_versions')->where('id', $variable->current_version_id)->value('values_json')) : []);
            }
            if ($values === [] && ($fallback === null || $fallback === '') && $status === 'active') {
                throw ValidationException::withMessages(['values' => 'Nhập ít nhất một giá trị hoặc điền giá trị dự phòng.']);
            }
            $id = $variable?->id ?? DB::table('template_variables')->insertGetId(['slug' => $slug, 'name' => $name, 'source_kind' => $source, 'set_id' => $setId,
                'fallback' => $fallback, 'status' => $status, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('template_variables')->where('id', $id)->update(['name' => $name, 'source_kind' => $source, 'set_id' => $setId, 'fallback' => $fallback, 'status' => $status, 'updated_at' => now()]);
            $variable = DB::table('template_variables')->find($id);
            $versionId = $this->version($variable, $values, $setVersion, $actorId, $variable->current_version_id !== null);
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            Access::audit('template-variable.saved', $actorId, null, ['variable_id' => $id, 'version_id' => $versionId, 'source_kind' => $source, 'value_count' => count($values)]);

            return DB::table('template_variables')->find($id);
        }, 3);
    }

    public function referenced(array $resolved): array
    {
        $tokens = [];
        $scan = function (array $slots) use (&$tokens): void {
            foreach ($slots as $slot => $entry) {
                if (str_starts_with((string) $slot, 'allocation.') || in_array($slot, ['managed_body', 'related_links'], true)
                    || (is_array($entry) && ($entry['enabled'] ?? true) === false)) {
                    continue;
                }
                $expression = is_string($entry) ? $entry : (is_array($entry) ? ($entry['template'] ?? null) : null);
                if (! is_string($expression)) {
                    continue;
                }
                preg_match_all('/\{\{\s*(var\.[a-z][a-z0-9_]{0,59})\s*\}\}/u', $expression, $matches);
                foreach ($matches[1] as $token) {
                    $tokens[$token] = true;
                }
            }
        };
        $scan($resolved['slots'] ?? []);
        foreach ($resolved['seo_template_bundle']['definition']['page_templates'] ?? [] as $profile) {
            if (is_array($profile)) {
                $scan($profile['slots'] ?? []);
            }
        }
        $result = array_keys($tokens);
        sort($result);

        return $result;
    }

    public function assertKnownTokens(string $expression): void
    {
        $tokens = $this->referenced(['slots' => ['title' => $expression]]);
        if ($tokens === []) {
            return;
        }
        if (! $this->ready()) {
            throw ValidationException::withMessages(['template' => 'Mục Biến template chưa sẵn sàng.']);
        }
        $slugs = DB::table('template_variables')->whereIn('slug', array_map(fn ($token) => substr($token, 4), $tokens))->pluck('slug')->all();
        foreach ($tokens as $token) {
            if (! in_array(substr($token, 4), $slugs, true)) {
                throw ValidationException::withMessages(['template' => 'Biến '.$token.' chưa được tạo trong Biến template.']);
            }
        }
    }

    public function configurationFingerprint(array $resolved): array
    {
        $tokens = $this->referenced($resolved);
        if ($tokens === []) {
            return [];
        }
        if (! $this->ready()) {
            return ['unavailable' => $tokens];
        }

        return ['tokens' => $tokens, 'variables' => DB::table('template_variables')->whereIn('slug', array_map(fn ($token) => substr($token, 4), $tokens))
            ->orderBy('id')->get(['id', 'slug', 'current_version_id', 'row_version', 'status', 'fallback'])->map(fn ($row) => (array) $row)->all()];
    }

    public function values(array $pins): array
    {
        $values = [];
        foreach ($pins as $token => $pin) {
            if (is_string($token) && is_array($pin) && is_string($pin['text'] ?? null)) {
                $values[$token] = $pin['text'];
            }
        }

        return $values;
    }

    public function pagePins(int $pageId): array
    {
        if (! $this->ready()) {
            return [];
        }
        $pins = [];
        foreach ($this->storedPins($pageId)->orderBy('v.slug')->get() as $pin) {
            $pins['var.'.$pin->slug] = $this->pin($pin, $pin);
        }

        return $pins;
    }

    public function previewForPage(object $page, array $resolved, array $frozen = [], bool $firstFanmulu = false): array
    {
        $tokens = $this->referenced($resolved);
        if ($tokens === []) {
            return [];
        }
        if (! $this->ready()) {
            throw new Conflict('TEMPLATE_VARIABLES_UNAVAILABLE');
        }
        $pins = $this->pagePins($page->id);
        $result = [];
        foreach (DB::table('template_variables as variable')->leftJoin('template_variable_versions as version', 'version.id', '=', 'variable.current_version_id')
            ->whereIn('variable.slug', array_map(fn ($token) => substr($token, 4), $tokens))->orderBy('variable.id')
            ->get(['variable.*', 'version.values_json']) as $variable) {
            $token = 'var.'.$variable->slug;
            if (isset($frozen[$token]) || isset($pins[$token])) {
                $result[$token] = $frozen[$token] ?? $pins[$token];

                continue;
            }
            if ($firstFanmulu && $variable->status !== 'active') {
                throw new Conflict('TEMPLATE_VARIABLE_PAUSED', 'Biến '.$variable->slug.' đang tạm dừng.');
            }
            $values = Json::decode($variable->values_json);
            $text = $values === [] ? $variable->fallback : $values[hexdec(substr(hash('sha256', $page->id.':'.$variable->id), 0, 6)) % count($values)];
            if ($text === null || $text === '') {
                throw new Conflict('TEMPLATE_VARIABLE_EMPTY', 'Biến '.$variable->slug.' chưa có giá trị.');
            }
            $result[$token] = ['variable_id' => (int) $variable->id, 'version_id' => (int) $variable->current_version_id,
                'generation' => $firstFanmulu ? 1 : 0, 'text' => $text, 'source_kind' => $variable->source_kind, 'set_id' => $variable->set_id];
        }
        foreach ($tokens as $token) {
            if (! isset($result[$token])) {
                throw new Conflict('TEMPLATE_VARIABLE_UNKNOWN', 'Biến '.$token.' chưa được tạo.');
            }
        }

        return $result;
    }

    public function planForPage(object $page, array $resolved, array $frozen = []): array
    {
        // New fanmulu pins are stable per page and become durable with activation.
        if (($page->page_kind ?? 'regular') === 'fanmulu' && $page->current_publication_id === null) {
            return $this->previewForPage($page, $resolved, $frozen, firstFanmulu: true);
        }
        $tokens = $this->referenced($resolved);
        if ($tokens === []) {
            return [];
        }
        if (! $this->ready()) {
            throw new Conflict('TEMPLATE_VARIABLES_UNAVAILABLE');
        }

        return DB::transaction(function () use ($page, $tokens, $frozen): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['template-variable-page:'.$page->id]);
            $variables = DB::table('template_variables')->whereIn('slug', array_map(fn ($token) => substr($token, 4), $tokens))->orderBy('id')->get();
            $pins = $this->storedPins($page->id)->whereIn('p.variable_id', $variables->pluck('id'))->get()->keyBy('variable_id');
            $result = [];
            foreach ($variables as $variable) {
                $token = 'var.'.$variable->slug;
                $stored = $pins->get($variable->id);
                if (isset($frozen[$token]) && ! ($stored?->pending ?? false)) {
                    $result[$token] = $frozen[$token];
                } elseif ($stored) {
                    $result[$token] = $this->pin($stored, $variable);
                } else {
                    $result[$token] = $this->choose($page, $variable, null);
                }
            }
            foreach ($tokens as $token) {
                if (! isset($result[$token])) {
                    throw new Conflict('TEMPLATE_VARIABLE_UNKNOWN', 'Biến '.$token.' chưa được tạo.');
                }
            }
            ksort($result);

            return $result;
        }, 3);
    }

    public function reroll(int $pageId, int $actorId, int $expectedPageRowVersion, ?array $variableIds = null): array
    {
        $page = DB::table('pages')->find($pageId) ?? abort(404);
        Access::site($actorId, (int) $page->site_id, true);

        return DB::transaction(function () use ($pageId, $actorId, $expectedPageRowVersion, $variableIds, $page): array {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $site = DB::table('sites')->where('id', $page->site_id)->lockForUpdate()->first();
            $page = DB::table('pages')->where('id', $pageId)->lockForUpdate()->first();
            Access::site($actorId, (int) $page->site_id, true);
            if ($page->row_version !== $expectedPageRowVersion) {
                throw new Conflict('PAGE_VERSION_CONFLICT', 'Trang đã thay đổi, hãy tải lại trước khi chọn lại giá trị.');
            }
            if ($page->publish_locked || ! in_array($page->status, ['draft', 'live'], true) || $site->status !== 'active') {
                throw new Conflict('PAGE_NOT_PUBLISHABLE', 'Trang hoặc website đang khóa/dừng phục vụ.');
            }
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['template-variable-page:'.$pageId]);
            $stored = DB::table('page_template_variable_values')->where('page_id', $pageId)
                ->when($variableIds !== null, fn ($query) => $query->whereIn('variable_id', $variableIds))->orderBy('variable_id')->get();
            if ($stored->isEmpty() || ($variableIds !== null && count(array_unique($variableIds)) !== $stored->count())) {
                throw ValidationException::withMessages(['variable_ids' => 'Chọn biến đã được cấp cho trang này.']);
            }
            $result = [];
            foreach ($stored as $value) {
                $variable = DB::table('template_variables')->find($value->variable_id);
                $result['var.'.$variable->slug] = $this->choose($page, $variable, $value);
            }
            DB::table('pages')->where('id', $pageId)->update(['row_version' => DB::raw('row_version+1'), 'updated_at' => now()]);
            Access::audit('template-variable.rerolled', $actorId, $page->site_id, ['variables' => $result], $pageId);

            return $result;
        }, 3);
    }

    public function activateSnapshot(object $page, array $pins): void
    {
        if (! $this->ready()) {
            return;
        }
        DB::table('page_template_variable_values')->where(['page_id' => $page->id, 'pending' => true])->update(['pending' => false]);
        $rows = [];
        $updatedAt = now();
        foreach ($pins as $pin) {
            $rows[$pin['variable_id']] = ['page_id' => $page->id, 'variable_id' => $pin['variable_id'],
                'version_id' => $pin['version_id'], 'generation' => $pin['generation'], 'text' => $pin['text'], 'pending' => false, 'updated_at' => $updatedAt];
        }
        if ($rows !== []) {
            DB::table('page_template_variable_values')->upsert(array_values($rows), ['page_id', 'variable_id'], ['version_id', 'generation', 'text', 'pending', 'updated_at']);
        }
    }

    private function choose(object $page, object $variable, ?object $previous): array
    {
        if ($variable->status !== 'active') {
            throw new Conflict('TEMPLATE_VARIABLE_PAUSED', 'Biến '.$variable->slug.' đang tạm dừng.');
        }
        $versionId = $variable->current_version_id;
        $next = $previous === null ? DB::selectOne(<<<'SQL'
WITH next_value AS (
UPDATE template_variable_decks
SET position = position + 1, updated_at = ?
WHERE site_id = ? AND version_id = ?
    AND position < CASE WHEN jsonb_typeof(order_json) = 'array' THEN jsonb_array_length(order_json) ELSE 0 END
RETURNING order_json ->> (position - 1) AS text
)
INSERT INTO page_template_variable_values (page_id, variable_id, version_id, generation, text, pending, updated_at)
SELECT ?, ?, ?, 1, text, true, ? FROM next_value
ON CONFLICT (page_id, variable_id) DO UPDATE
SET version_id = EXCLUDED.version_id, generation = EXCLUDED.generation, text = EXCLUDED.text,
    pending = EXCLUDED.pending, updated_at = EXCLUDED.updated_at
RETURNING text
SQL, [now(), $page->site_id, $versionId, $page->id, $variable->id, $versionId, now()], false) : null;
        if ($next !== null) {
            DB::connection()->recordsHaveBeenModified();

            return ['variable_id' => (int) $variable->id, 'version_id' => (int) $versionId, 'generation' => 1,
                'text' => $next->text, 'source_kind' => $variable->source_kind, 'set_id' => $variable->set_id];
        }
        $version = DB::table('template_variable_versions')->find($versionId);
        $values = Json::decode($version?->values_json);
        if ($values === []) {
            if ($variable->fallback === null || $variable->fallback === '') {
                throw new Conflict('TEMPLATE_VARIABLE_EMPTY', 'Biến '.$variable->slug.' chưa có giá trị.');
            }
            $text = $variable->fallback;
        } else {
            DB::table('template_variable_decks')->insertOrIgnore(['site_id' => $page->site_id, 'version_id' => $versionId,
                'order_json' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            $deck = DB::table('template_variable_decks')->where(['site_id' => $page->site_id, 'version_id' => $versionId])->lockForUpdate()->first();
            $order = Json::decode($deck->order_json);
            $position = (int) $deck->position;
            $cycle = (int) $deck->cycle;
            if ($position >= count($order)) {
                $order = $values;
                for ($index = count($order) - 1; $index > 0; $index--) {
                    $other = random_int(0, $index);
                    [$order[$index], $order[$other]] = [$order[$other], $order[$index]];
                }
                $position = 0;
                $cycle++;
            }
            if ($previous && count($order) > 1 && $order[$position] === $previous->text) {
                $other = null;
                for ($index = $position + 1; $index < count($order); $index++) {
                    if ($order[$index] !== $previous->text) {
                        $other = $index;
                        break;
                    }
                }
                if ($other !== null) {
                    [$order[$position], $order[$other]] = [$order[$other], $order[$position]];
                } else {
                    $order = array_values(array_filter($values, fn ($value) => $value !== $previous->text));
                    $order[] = $previous->text;
                    $position = 0;
                    $cycle++;
                }
            }
            $text = $order[$position];
            DB::table('template_variable_decks')->where('id', $deck->id)->update(['order_json' => Json::encode($order), 'position' => $position + 1, 'cycle' => $cycle, 'updated_at' => now()]);
        }

        return $this->savePin($page, $variable, $text, ($previous?->generation ?? 0) + 1);
    }

    private function savePin(object $page, object $variable, string $text, int $generation): array
    {
        DB::table('page_template_variable_values')->upsert([
            ['page_id' => $page->id, 'variable_id' => $variable->id, 'version_id' => $variable->current_version_id,
                'generation' => $generation, 'text' => $text, 'pending' => true, 'updated_at' => now()],
        ], ['page_id', 'variable_id'], ['version_id', 'generation', 'text', 'pending', 'updated_at']);

        return ['variable_id' => (int) $variable->id, 'version_id' => (int) $variable->current_version_id, 'generation' => $generation,
            'text' => $text, 'source_kind' => $variable->source_kind, 'set_id' => $variable->set_id];
    }

    private function version(object $variable, array $values, ?int $setVersion, int $actorId, bool $increment = true): int
    {
        $id = DB::table('template_variable_versions')->insertGetId(['variable_id' => $variable->id,
            'version_no' => (DB::table('template_variable_versions')->where('variable_id', $variable->id)->max('version_no') ?? 0) + 1,
            'source_kind' => $variable->source_kind, 'set_version_id' => $setVersion, 'values_json' => Json::encode($values), 'hash' => Json::hash($values), 'created_by' => $actorId]);
        DB::table('template_variables')->where('id', $variable->id)->update(['current_version_id' => $id,
            'row_version' => $increment ? DB::raw('row_version+1') : 1, 'updated_at' => now()]);

        return $id;
    }

    private function pin(object $pin, object $variable): array
    {
        return ['variable_id' => (int) $pin->variable_id, 'version_id' => (int) $pin->version_id, 'generation' => (int) $pin->generation,
            'text' => $pin->text, 'source_kind' => $pin->pinned_source_kind ?? $variable->source_kind, 'set_id' => $pin->pinned_set_id];
    }

    private function storedPins(int $pageId): Builder
    {
        return DB::table('page_template_variable_values as p')
            ->join('template_variables as v', 'v.id', '=', 'p.variable_id')
            ->leftJoin('template_variable_versions as version', 'version.id', '=', 'p.version_id')
            ->leftJoin('template_variable_set_versions as set_version', 'set_version.id', '=', 'version.set_version_id')
            ->where('p.page_id', $pageId)
            ->select(['p.*', 'v.slug', 'v.source_kind', 'v.set_id', 'version.source_kind as pinned_source_kind', 'set_version.set_id as pinned_set_id']);
    }

    private function decorate(object $row): object
    {
        $row->values = Json::decode($row->values_json);
        $row->value_count = count($row->values);
        unset($row->values_json);

        return $row;
    }

    private function text(mixed $text, string $field, int $limit): string
    {
        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages([$field => 'Giá trị cần là văn bản UTF-8 hợp lệ.']);
        }
        $text = preg_replace('/^\x{FEFF}/u', '', $text);
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        if ($text === false || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', $text)
            || preg_match('/<\/?[a-z][^>]*>/iu', $text)) {
            throw ValidationException::withMessages([$field => 'Giá trị không được có HTML hoặc ký tự điều khiển.']);
        }
        $text = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $text));
        if (mb_strlen($text) > $limit) {
            throw ValidationException::withMessages([$field => 'Giá trị vượt '.$limit.' ký tự.']);
        }

        return $text;
    }

    private function cas(?object $record, array $data): void
    {
        if (isset($data['id']) && ! $record) {
            abort(404);
        }
        if ($record && (! isset($data['row_version']) || (int) $record->row_version !== (int) $data['row_version'])) {
            throw new Conflict('TEMPLATE_VARIABLE_VERSION_CONFLICT', 'Dữ liệu đã thay đổi, hãy tải lại trước khi lưu.');
        }
    }

    private function authorize(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
        abort_unless($this->ready(), 503, 'Mục Biến template chưa sẵn sàng.');
    }
}
