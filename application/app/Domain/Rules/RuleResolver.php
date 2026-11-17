<?php

namespace App\Domain\Rules;

use App\Domain\Fanmulu\FanmuluSettings;
use App\Domain\Support\CatalogAvailability;
use App\Domain\Support\Json;
use App\Domain\Themes\MovieThemeService;
use Illuminate\Support\Facades\DB;

final class RuleResolver
{
    public function resolve(object $page, ?object $site = null): array
    {
        $site ??= DB::table('sites')->find($page->site_id);
        $templateId = $page->template_id ?? $site->template_id;
        $template = $templateId ? DB::table('templates as t')->leftJoin('template_versions as v', 'v.id', '=', 't.current_version_id')
            ->where('t.id', $templateId)->first(['t.*', 'v.definition_json']) : null;
        $definition = $template ? Json::decode($template->definition_json) : [];
        $detail = ! in_array($page->page_type, ['home', 'category']);
        $defaults = ['title' => $detail ? '{{content.title}} - {{kw.primary}} - {{site.name}}' : '{{site.name}}', 'description' => $detail ? '{{content.title}}资料、简介与相关内容，收录于{{kw.primary}}栏目。' : '资料、简介与分类索引。', 'h1' => $detail ? '{{content.title}}' : '{{site.name}}', 'meta_keywords' => false];
        $profile = SeoTemplateBundle::page($definition, $page->page_type);
        $slots = SeoTemplateBundle::isBundle($definition)
            ? array_replace($defaults, array_filter($profile['slots'] ?? [], fn ($entry) => $entry !== null))
            : ($definition['slots'] ?? $defaults);
        $pools = DB::table('keyword_bindings')->where(['site_id' => $page->site_id, 'status' => 'active'])->orderByDesc('priority')->orderBy('id')->pluck('pool_id')->unique()->values()->all();
        foreach (['primary', 'secondary', 'anchor'] as $role) {
            $slots['allocation.'.$role] = ['pool_ids' => $pools, 'mode' => 'weighted', 'count' => $detail ? ($role === 'primary' ? 1 : ($role === 'secondary' ? 1 : 3)) : 0, 'required' => $detail && $role === 'primary'];
        }
        $slots['managed_body'] ??= ['enabled' => true, 'max_anchors' => 3, 'per_keyword' => 1];
        $categories = [];
        if ($page->category_id) {
            $ancestors = DB::select(<<<'SQL'
WITH RECURSIVE ancestors AS (
    SELECT ?::bigint AS id, ARRAY[?::bigint] AS visited, false AS cycle, 0 AS depth
    UNION ALL
    SELECT c.parent_id, a.visited || c.parent_id, c.parent_id = ANY(a.visited), a.depth + 1
    FROM ancestors a JOIN categories c ON c.id = a.id AND c.site_id = ?
    WHERE NOT a.cycle AND c.parent_id IS NOT NULL AND c.parent_id <> 0
)
SELECT id, cycle FROM ancestors ORDER BY depth
SQL, [$page->category_id, $page->category_id, $page->site_id]);
            foreach ($ancestors as $ancestor) {
                if ($ancestor->cycle) {
                    throw new \InvalidArgumentException('Category cycle');
                }
                $categories[] = $ancestor->id;
            }
        }
        $rules = CatalogAvailability::filter(DB::table('rules as r'), 'rules', 'r')
            ->join('rule_versions as v', 'v.id', '=', 'r.current_version_id')->where('r.status', 'active')
            ->where(fn ($query) => $query->where('r.scope_type', 'global')->orWhere(function ($siteRules) use ($page, $categories): void {
                $siteRules->where('r.site_id', $page->site_id)->where(fn ($scopes) => $scopes->where('r.scope_type', 'site')
                    ->orWhere(fn ($scope) => $scope->where('r.scope_type', 'page_type')->where('r.page_type', $page->page_type))
                    ->orWhere(fn ($scope) => $scope->where('r.scope_type', 'category')->whereIn('r.category_id', $categories))
                    ->orWhere(fn ($scope) => $scope->where('r.scope_type', 'page')->where('r.page_id', $page->id)));
            }))->select('r.*', 'v.config_json')->get();
        $matches = [];
        foreach ($rules as $rule) {
            $rank = match ($rule->scope_type) {
                'global' => 0,'site' => 1,'page_type' => $rule->page_type === $page->page_type ? 2 : -1,'category' => in_array($rule->category_id, $categories) ? 3 + count($categories) - array_search($rule->category_id, $categories) : -1,'page' => $rule->page_id === $page->id ? 1000 : -1
            };
            if ($rank >= 0) {
                $matches[] = ['rank' => $rank, 'rule' => $rule];
            }
        }
        usort($matches, fn ($a, $b) => $a['rank'] <=> $b['rank']);
        $sources = [];
        foreach ($slots as $slot => $value) {
            $sources[$slot] = ['scope' => 'template/default', 'template_version_id' => $template?->current_version_id];
        }
        foreach ($matches as $match) {
            $r = $match['rule'];
            $value = $r->config_json === null ? null : json_decode($r->config_json, true, 32, JSON_THROW_ON_ERROR);
            if ($value === null) {
                continue;
            }
            $slots[$r->slot] = $value;
            $sources[$r->slot] = ['scope' => $r->scope_type, 'rule_id' => $r->id, 'rule_version_id' => $r->current_version_id];
        }

        if (($page->page_kind ?? 'regular') === 'fanmulu') {
            $fanmulu = Json::decode($page->input_snapshot_json)['fanmulu'] ?? [];
            $selected = $fanmulu['selected_primary']['keyword_id'] ?? null;
            $namePoolIds = FanmuluSettings::fromSite($site)['display_name_pool_ids'] ?? [];
            $hasSeparatedPin = $page->current_publication_id && DB::table('page_keyword_assignments')
                ->where('publication_id', $page->current_publication_id)->where('purpose', 'fanmulu_title')->exists();
            $slot = $namePoolIds === [] && ! $hasSeparatedPin ? 'allocation.primary' : 'allocation.fanmulu_title';
            $slots[$slot] = ['pool_ids' => $namePoolIds === [] ? $pools : $namePoolIds, 'mode' => $selected ? 'manual' : 'random-once',
                'count' => 1, 'required' => true, 'keyword_ids' => $selected ? [(int) $selected] : []];
            $sources[$slot] = ['scope' => 'page', 'source' => 'fanmulu', 'selected_keyword_id' => $selected];

            if (($fanmulu['first_seo_allocation'] ?? null) === 'random-once' && $page->current_publication_id === null) {
                foreach (['allocation.primary', 'allocation.secondary', 'allocation.anchor'] as $seoSlot) {
                    if (($slots[$seoSlot]['mode'] ?? null) === 'sequential' && $sources[$seoSlot]['scope'] !== 'page') {
                        $slots[$seoSlot]['mode'] = 'random-once';
                    }
                }
            }
        }

        $resolved = ['slots' => $slots, 'sources' => $sources, 'template_version_id' => $template?->current_version_id, 'managed_body_zone' => $profile['managed_body_zone'] ?? 'main-content', 'theme' => app(MovieThemeService::class)->reference($page, $site)];
        if ($template && SeoTemplateBundle::isBundle($definition)) {
            $resolved['seo_template_bundle'] = SeoTemplateBundle::reference($template, $definition);
        }
        $variables = method_exists(TemplateVariableService::class, 'configurationFingerprint') ? app(TemplateVariableService::class)->configurationFingerprint($resolved) : [];
        if ($variables !== []) {
            $resolved['custom_variable_configuration'] = $variables;
        }

        return $resolved;
    }
}
