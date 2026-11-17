<?php

namespace App\Domain\Fanmulu;

use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;

final class FanmuluLinks
{
    /** Discovery reserves URL identities only; keyword quota and pages wait for the first click. */
    public function links(object $site, ?object $page, string $path): array
    {
        $settings = FanmuluSettings::fromSite($site);
        if (! $settings['enabled'] || $settings['links_per_page'] === 0 || ($page->page_kind ?? 'regular') !== 'regular') {
            return [];
        }
        $originPath = $page->canonical_path ?? $path;

        return DB::transaction(function () use ($site, $page, $originPath): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['tram:fanmulu:discovery:'.$site->id.':'.$originPath]);
            $current = DB::table('sites')->where('id', $site->id)->sharedLock()->first();
            $settings = FanmuluSettings::fromSite($current);
            if (! $current || $current->status !== 'active' || ! $settings['enabled'] || $settings['links_per_page'] === 0) {
                return [];
            }
            $identities = [];
            for ($index = 0; $index < $settings['links_per_page']; $index++) {
                // Mutable settings/source/keywords never reroll an already issued choice.
                $identities[] = Json::canonicalEncode(['discovery-keyword-v1', (int) $site->id, $originPath, $index]);
            }
            $registry = app(FanmuluUrlRegistry::class);
            $stored = $registry->findIdentities((int) $site->id, $identities);
            $links = [];
            $catalog = app(FanmuluSourceCatalog::class);
            foreach ($identities as $index => $identity) {
                $record = $stored[hash('sha256', $identity)] ?? null;
                if ($record !== null && $record['selected_primary'] !== null
                    && $catalog->candidateIds((int) $site->id, 1, (int) $record['source_page_id']) !== []) {
                    $links[$index] = ['url' => $record['path'], 'label' => $record['keyword_text']];
                }
            }
            if (count($links) === count($identities)) {
                return array_values($links);
            }
            $originId = ($page->page_type ?? null) === 'movie' ? (int) $page->id : null;
            if ($originId === null) {
                $originId = DB::table('pages')->where(['site_id' => $site->id, 'page_kind' => 'regular', 'page_type' => 'movie', 'canonical_path' => $originPath])->value('id');
                $originId = $originId === null ? null : (int) $originId;
            }
            $ids = app(FanmuluSourceCatalog::class)->randomCandidateIds((int) $site->id, 20, $originId);
            $choices = app(FanmuluNavigation::class)->discoveryChoices((int) $site->id, $current->locale ?? 'zh-CN', $ids, bin2hex(random_bytes(12)), $originId);
            if ($choices === []) {
                ksort($links);

                return array_values($links);
            }
            $sourceIds = array_keys($choices);
            shuffle($sourceIds);
            $requests = [];
            $indices = [];
            $usedTexts = array_fill_keys(array_column($links, 'label'), true);
            $rule = ($settings['url_rule']['mode'] ?? 'legacy') === 'legacy' ? ['mode' => 'random'] : $settings['url_rule'];
            $prefixes = $settings['prefixes'];
            $start = random_int(0, count($prefixes) - 1);
            foreach ($identities as $index => $identity) {
                if (isset($stored[hash('sha256', $identity)])) {
                    continue;
                }
                $sourceId = $sourceIds[$index % count($sourceIds)];
                $choice = $choices[$sourceId];
                $keywords = array_values(array_filter($choice['keywords'], fn (array $keyword): bool => ! isset($usedTexts[$keyword['text']])));
                $keywords = $keywords === [] ? $choice['keywords'] : $keywords;
                $keyword = $keywords[random_int(0, count($keywords) - 1)];
                $usedTexts[$keyword['text']] = true;
                $requests[] = ['identity' => $identity, 'rule' => $rule, 'prefix' => $prefixes[($start + $index) % count($prefixes)],
                    'suffix' => $settings['suffix'] ?? '', 'provenance' => ['origin' => 'discovery', 'source_page_id' => $sourceId,
                        'keyword_id' => $keyword['keyword_id'], 'keyword_text' => $keyword['text'],
                        'metadata' => ['origin_path' => $originPath, 'origin_page_id' => $originId, 'source' => $choice['metadata']]]];
                $indices[] = $index;
                $links[$index] = ['url' => '', 'label' => $keyword['text']];
            }
            if ($requests !== []) {
                foreach ($registry->issueBatch((int) $site->id, $requests) as $offset => $issuedPath) {
                    $links[$indices[$offset]]['url'] = $issuedPath;
                }
            }
            ksort($links);

            return array_values($links);
        }, 3);
    }

    public function append(string $html, object $site, ?object $page, string $path, ?string $userAgent = null): string
    {
        if (! ChineseSearchSpider::matches($userAgent) || ($page->page_kind ?? 'regular') !== 'regular') {
            return $html;
        }
        $insertion = app(FanmuluListInsertion::class);
        $target = $insertion->locate($html);
        if ($target === null) {
            return $html;
        }
        $links = $this->links($site, $page, $path);

        return $links === [] ? $html : $insertion->insert($html, $target, $links);
    }
}
