<?php

namespace App\Domain\Rules;

use App\Domain\Keywords\KeywordService;
use App\Domain\Support\Access;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfigService
{
    public function saveRule(array $data, int $actorId): object
    {
        $scope = $data['scope_type'] ?? 'site';
        $siteId = $data['site_id'] ?? null;
        if ($scope === 'global') {
            if (! DB::table('users')->where('id', $actorId)->value('is_global_admin')) {
                abort(403);
            }
        } else {
            Access::site($actorId, (int) $siteId, true);
        }
        $config = $data['config_json'] ?? null;
        if (is_string($config)) {
            $config = json_decode($config, true, 32, JSON_THROW_ON_ERROR);
        }
        $slot = $data['slot'] ?? 'title';
        if (! in_array($slot, ['title', 'description', 'meta_keywords', 'h1', 'intro', 'related_links', 'managed_body', 'allocation.primary', 'allocation.secondary', 'allocation.anchor']) && ! preg_match('/^h2\.[a-z0-9_-]+$/', $slot)) {
            throw new \InvalidArgumentException('Slot không hợp lệ');
        }
        if (is_array($config)) {
            if (str_starts_with($slot, 'allocation.')) {
                if (array_diff(array_keys($config), ['mode', 'enabled', 'required', 'pool_ids', 'fallback_pool_ids', 'count', 'filters', 'keyword_ids', 'manual_ids'])) {
                    throw new \InvalidArgumentException('Allocation policy có field không hỗ trợ');
                }
                if (! in_array($config['mode'] ?? 'weighted', ['manual', 'sequential', 'weighted', 'random-once'])) {
                    throw new \InvalidArgumentException('Mode không hợp lệ');
                }
                if (filter_var($config['count'] ?? 1, FILTER_VALIDATE_INT) === false || ($config['count'] ?? 1) < 0 || ($config['count'] ?? 1) > 20) {
                    throw new \InvalidArgumentException('Count phải là số nguyên 0–20');
                }
                foreach (['enabled', 'required'] as $flag) {
                    if (array_key_exists($flag, $config) && ! is_bool($config[$flag])) {
                        throw new \InvalidArgumentException($flag.' phải là boolean');
                    }
                }
                foreach (['pool_ids', 'fallback_pool_ids', 'keyword_ids', 'manual_ids'] as $field) {
                    if (isset($config[$field]) && (! is_array($config[$field]) || ! array_is_list($config[$field]) || count(array_filter($config[$field], fn ($id) => filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0)))) {
                        throw new \InvalidArgumentException($field.' cần danh sách ID số nguyên dương');
                    }
                }
                if (isset($config['filters'])) {
                    KeywordService::validateFilters($config['filters']);
                }
                foreach (array_merge($config['pool_ids'] ?? [], $config['fallback_pool_ids'] ?? []) as $poolId) {
                    $query = DB::table('keyword_bindings')->where(['pool_id' => $poolId, 'status' => 'active']);
                    if ($siteId) {
                        $query->where('site_id', $siteId);
                    }
                    if (! $query->exists()) {
                        throw new \InvalidArgumentException('Pool chưa gắn site');
                    }
                }
            } elseif (isset($config['template'])) {
                app(TemplateCompiler::class)->validate($config['template']);
                if (str_contains($config['template'], 'var.')) {
                    app(TemplateVariableService::class)->assertKnownTokens($config['template']);
                }
            }
        } elseif ($config !== null && $config !== false) {
            throw new \InvalidArgumentException('Rule phải là object, null hoặc false');
        }

        return DB::transaction(function () use ($data, $actorId, $scope, $siteId, $config, $slot) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            if ($siteId) {
                DB::table('sites')->where('id', $siteId)->lockForUpdate()->first();
            }
            $identity = ['scope_type' => $scope, 'site_id' => $siteId, 'page_id' => $scope === 'page' ? ($data['page_id'] ?? null) : null, 'category_id' => $scope === 'category' ? ($data['category_id'] ?? null) : null, 'page_type' => $scope === 'page_type' ? ($data['page_type'] ?? null) : null, 'slot' => $slot];
            $rule = DB::table('rules')->where($identity)->lockForUpdate()->first();
            CatalogAvailability::assertWritable($rule, 'RULE_DELETED');
            if ($rule?->status === 'archived') {
                throw new Conflict('RULE_ARCHIVED', 'Quy tắc đã lưu trữ. Khôi phục quy tắc trước khi chỉnh sửa.');
            }
            if (($data['expected_absent'] ?? false) && $rule) {
                abort(409, 'Quy tắc đã được tạo bởi thao tác khác. Hãy tải lại.');
            }
            if (isset($data['row_version']) && (! $rule || (int) $rule->row_version !== (int) $data['row_version'])) {
                abort(409, 'Quy tắc đã thay đổi. Hãy tải lại trước khi lưu.');
            }
            $id = $rule?->id ?? DB::table('rules')->insertGetId($identity);
            $version = (DB::table('rule_versions')->where('rule_id', $id)->max('version_no') ?? 0) + 1;
            $vid = DB::table('rule_versions')->insertGetId(['rule_id' => $id, 'version_no' => $version, 'config_json' => $config === null ? null : Json::encode($config), 'hash' => Json::hash($config), 'created_by' => $actorId]);
            DB::table('rules')->where('id', $id)->update(['current_version_id' => $vid, 'row_version' => DB::raw('row_version+1')]);
            if ($siteId) {
                DB::table('sites')->where('id', $siteId)->increment('config_epoch');
            } else {
                DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            }
            Access::audit('rule.version.created', $actorId, $siteId, ['rule_id' => $id, 'version_id' => $vid]);

            return DB::table('rules')->find($id);
        }, 3);
    }

    public function saveTemplate(array $data, int $actorId): object
    {
        if (! DB::table('users')->where('id', $actorId)->value('is_global_admin')) {
            abort(403);
        }
        $definition = $data['definition_json'] ?? $data['definition'] ?? [];
        if (is_string($definition)) {
            $definition = Json::decode($definition);
        }
        SeoTemplateBundle::validate($definition);
        $profiles = SeoTemplateBundle::isBundle($definition) ? $definition['page_templates'] : [$definition];
        foreach ($profiles as $profile) {
            foreach ($profile['slots'] ?? [] as $entry) {
                if (is_array($entry) && ($entry['enabled'] ?? true) === false) {
                    continue;
                }
                $expression = is_string($entry) ? $entry : (is_array($entry) ? ($entry['template'] ?? '') : '');
                if (str_contains($expression, 'var.')) {
                    app(TemplateVariableService::class)->assertKnownTokens($expression);
                }
            }
        }

        return DB::transaction(function () use ($data, $definition, $actorId) {
            DB::table('system_settings')->where('id', 1)->lockForUpdate()->first();
            $templateQuery = DB::table('templates');
            $templateQuery = isset($data['id']) ? $templateQuery->where('id', $data['id']) : $templateQuery->where('name', $data['name']);
            $template = $templateQuery->lockForUpdate()->first();
            CatalogAvailability::assertWritable($template, 'TEMPLATE_DELETED');
            if (($data['expected_absent'] ?? false) && $template) {
                abort(409, 'Template đã được tạo bởi thao tác khác. Hãy tải lại.');
            }
            if (isset($data['row_version']) && (! $template || (int) $template->row_version !== (int) $data['row_version'])) {
                abort(409, 'Template đã thay đổi. Hãy tải lại trước khi lưu.');
            }
            if ($template && DB::table('templates')->where('name', $data['name'])->where('id', '<>', $template->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'Tên template đã được dùng. Chọn tên khác.']);
            }
            $id = $template?->id ?? DB::table('templates')->insertGetId(['name' => $data['name']]);
            $version = (DB::table('template_versions')->where('template_id', $id)->max('version_no') ?? 0) + 1;
            $vid = DB::table('template_versions')->insertGetId(['template_id' => $id, 'version_no' => $version, 'schema_version' => SeoTemplateBundle::isBundle($definition) ? 2 : 1, 'definition_json' => Json::encode($definition), 'hash' => Json::hash($definition), 'created_by' => $actorId]);
            $profiles = SeoTemplateBundle::isBundle($definition) ? $definition['page_templates'] : ['' => $definition];
            foreach ($profiles as $view => $profile) {
                foreach ($profile['slots'] as $slot => $entry) {
                    DB::table('template_slots')->insert(['template_version_id' => $vid, 'slot_name' => $view === '' ? $slot : $view.':'.$slot, 'context' => $slot === 'intro' ? 'html' : 'text', 'token_expression' => is_string($entry) ? $entry : (is_array($entry) ? ($entry['template'] ?? null) : null), 'options_json' => Json::encode(is_array($entry) ? $entry : ['enabled' => $entry !== false])]);
                }
            }
            DB::table('templates')->where('id', $id)->update(['name' => $data['name'], 'current_version_id' => $vid, 'row_version' => DB::raw('row_version+1')]);
            DB::table('system_settings')->where('id', 1)->increment('config_epoch');
            Access::audit('template.version.created', $actorId, null, ['template_id' => $id, 'version_id' => $vid]);

            return DB::table('templates')->find($id);
        }, 3);
    }
}
