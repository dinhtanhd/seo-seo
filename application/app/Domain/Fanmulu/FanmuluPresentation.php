<?php

namespace App\Domain\Fanmulu;

use App\Domain\Keywords\AssignmentPurpose;
use App\Domain\Sites\SitePresentationService;
use App\Domain\Support\Conflict;
use App\Domain\Support\Json;

final class FanmuluPresentation
{
    public static function freeze(object $page, array $content, array $assignments): ?array
    {
        if (($page->page_kind ?? 'regular') !== 'fanmulu') {
            return null;
        }
        $provenance = Json::decode($page->input_snapshot_json)['fanmulu'] ?? [];
        $primary = AssignmentPurpose::display($assignments);
        $pageId = (int) ($page->source_page_id ?? $provenance['source_page_id'] ?? 0);
        $publicationId = $page->source_publication_id ?? $provenance['source_publication_id'] ?? null;
        $path = $provenance['source_path'] ?? null;
        $title = $content['title'] ?? null;
        if ($pageId < 1 || ($publicationId !== null && (int) $publicationId < 1)
            || ($publicationId === null && ((int) ($provenance['source_content_revision_id'] ?? 0) < 1
                || (int) $provenance['source_content_revision_id'] !== (int) $page->working_content_revision_id))
            || ! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('/[\x00-\x20\\\\?#]/', $path) || ! is_string($title) || $title === ''
            || ! is_string($primary['text'] ?? null) || trim($primary['text']) === '') {
            throw new Conflict('FANMULU_PRESENTATION_INVALID');
        }
        if ((isset($provenance['source_page_id']) && $pageId !== (int) $provenance['source_page_id'])
            || (isset($provenance['source_publication_id']) && $publicationId !== (int) $provenance['source_publication_id'])) {
            throw new Conflict('FANMULU_SOURCE_CONFLICT');
        }
        $selected = $provenance['selected_primary'] ?? null;
        if ($selected !== null && ((int) ($selected['keyword_id'] ?? 0) !== (int) ($primary['keyword_id'] ?? 0)
            || ($selected['text'] ?? null) !== $primary['text'])) {
            throw new Conflict('FANMULU_KEYWORD_CONFLICT');
        }

        return ['presentation_version' => AssignmentPurpose::separated($assignments) ? 3 : 2, 'source_page_id' => $pageId, 'source_publication_id' => $publicationId, 'source_path' => $path,
            'original_title' => $title, 'display_title' => $primary['text']];
    }

    public static function enabled(array $snapshot): bool
    {
        return is_array($snapshot['fanmulu'] ?? null)
            && is_string($snapshot['fanmulu']['display_title'] ?? null)
            && $snapshot['fanmulu']['display_title'] !== '';
    }

    public static function version2(array $snapshot): bool
    {
        return self::enabled($snapshot) && ($snapshot['fanmulu']['presentation_version'] ?? 1) >= 2;
    }

    public static function separateSeo(array $snapshot): bool
    {
        return self::enabled($snapshot) && ($snapshot['fanmulu']['presentation_version'] ?? 1) >= 3;
    }

    public static function compactContext(array $snapshot): bool
    {
        return self::version2($snapshot)
            && ($snapshot['fanmulu']['navigation']['context_version'] ?? $snapshot['fanmulu']['navigation']['runtime']['context_version'] ?? null) === 3;
    }

    public static function content(array $snapshot): array
    {
        $content = $snapshot['content'] ?? [];
        if (array_key_exists('managed_body', $snapshot)) {
            $content['body_html'] = $snapshot['managed_body'];
        }
        if (! self::enabled($snapshot)) {
            return $content;
        }
        $frozen = $snapshot['fanmulu'];
        $content['title'] = $frozen['display_title'];
        if (self::version2($snapshot)) {
            $content['body_html'] = self::ensurePrimaryHtml(self::markup((string) ($content['body_html'] ?? ''), $snapshot), $snapshot, 'plot');
        } elseif (! array_key_exists('managed_body', $snapshot)) {
            $content['body_html'] = self::replaceBody((string) ($content['body_html'] ?? ''), $frozen);
        }
        foreach ($content['episodes'] ?? [] as $index => $episode) {
            if (! is_array($episode)) {
                continue;
            }
            foreach (['title', 'name'] as $field) {
                if (is_string($episode[$field] ?? null)) {
                    $content['episodes'][$index][$field] = self::version2($snapshot)
                        ? self::text($episode[$field], $snapshot) : self::replaceText($episode[$field], $frozen);
                }
            }
        }

        return $content;
    }

    public static function slots(array $snapshot, array $slots): array
    {
        if (! self::enabled($snapshot)) {
            return $slots;
        }
        $title = $snapshot['fanmulu']['display_title'];
        if (self::version2($snapshot)) {
            foreach ($slots as $slot => $value) {
                if (is_string($value)) {
                    $slots[$slot] = $slot === 'intro' ? self::ensurePrimaryHtml(self::markup($value, $snapshot), $snapshot, 'intro') : self::text($value, $snapshot);
                }
            }
            if (isset($slots['meta_keywords']) && ! str_contains($slots['meta_keywords'], $title)) {
                $slots['meta_keywords'] = $title.($slots['meta_keywords'] === '' ? '' : ','.$slots['meta_keywords']);
            }
        }
        foreach (['title', 'h1'] as $slot) {
            $value = is_string($slots[$slot] ?? null) ? $slots[$slot] : '';
            if (! str_contains($value, $title)) {
                $slots[$slot] = $title.($value === '' ? '' : ' - '.$value);
            }
        }

        return $slots;
    }

    /** Add a visible label without altering the source's markup or serializing its DOM. */
    private static function ensurePrimaryHtml(string $html, array $snapshot, string $block): string
    {
        $primary = $snapshot['fanmulu']['display_title'];
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $text = '';
            foreach ((new \DOMXPath($document))->query('//text()[not(ancestor::script or ancestor::style or ancestor::template or ancestor::*[@hidden] or ancestor::*[@aria-hidden="true"])]') as $node) {
                $text .= $node->nodeValue;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (str_contains($text, $primary)) {
            return $html;
        }
        $tag = $block === 'plot' ? 'p' : 'span';
        $marker = self::compactContext($snapshot) ? '' : ' data-fanmulu-primary="'.$block.'"';
        $label = '<'.$tag.$marker.'>'.htmlspecialchars($primary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</'.$tag.'>';

        return $label.' '.$html;
    }

    public static function ensurePrimaryText(string $text, array $snapshot): string
    {
        if (! self::version2($snapshot)) {
            return $text;
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $primary = $snapshot['fanmulu']['display_title'];

        return str_contains($text, $primary) ? $text : $primary.($text === '' ? '' : ' · '.$text);
    }

    public static function text(string $text, array $snapshot): string
    {
        if (! self::version2($snapshot)) {
            return self::enabled($snapshot) ? self::replaceText($text, $snapshot['fanmulu']) : $text;
        }

        $labels = self::separateSeo($snapshot)
            ? array_map(fn (string $label): array => ['role' => 'secondary', 'text' => $label], $snapshot['_fanmulu_replacement_labels'] ?? [])
            : ($snapshot['assignments'] ?? []);

        return self::replaceLabels($text, (string) ($snapshot['fanmulu']['original_title'] ?? ''),
            $snapshot['fanmulu']['display_title'], $labels,
            self::separateSeo($snapshot) ? array_column(AssignmentPurpose::seo($snapshot['assignments'] ?? []), 'text') : []);
    }

    /** Replace only displayed labels; raw assignments, metadata and media remain frozen. */
    private static function replaceLabels(string $text, string $original, string $display, array $assignments = [], array $protected = []): string
    {
        $labels = [$original];
        foreach ($assignments as $assignment) {
            if (in_array($assignment['role'] ?? null, ['secondary', 'anchor'], true) && is_string($assignment['text'] ?? null)) {
                $labels[] = $assignment['text'];
            }
        }
        $labels = array_values(array_unique(array_filter($labels, fn ($label): bool => $label !== '' && $label !== $display)));
        if ($labels === []) {
            return $text;
        }
        usort($labels, fn ($a, $b): int => mb_strlen($b) <=> mb_strlen($a));
        $protected = array_values(array_unique(array_filter([$display, ...$protected], fn ($label): bool => is_string($label) && $label !== '')));
        usort($protected, fn ($a, $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '~'.implode('|', array_map(fn ($label) => preg_quote($label, '~'), $protected)).'|'.implode('|', array_map(fn ($label) => preg_quote($label, '~'), $labels)).'~u';

        return preg_replace_callback($pattern, fn ($match) => in_array($match[0], $protected, true) ? $match[0] : $display, $text) ?? $text;
    }

    public static function urls(array $snapshot, array $urls): array
    {
        if (! self::version2($snapshot) || ! FanmuluNavigation::enabled($snapshot)) {
            return $urls;
        }

        return array_replace($urls, ['home' => FanmuluNavigation::scopePath($snapshot, 'home'),
            'list' => FanmuluNavigation::scopePath($snapshot, 'list'), 'search' => FanmuluNavigation::scopePath($snapshot, 'search'),
            'detail' => FanmuluNavigation::rootPath($snapshot),
            'play' => empty($snapshot['content']['episodes']) ? '' : FanmuluNavigation::scopePath($snapshot, 'play', '1')]);
    }

    public static function card(array $snapshot, array $card, array $entry): array
    {
        if (! self::version2($snapshot) || ! FanmuluNavigation::enabled($snapshot)) {
            return $card;
        }
        $sourceId = (int) ($entry['fanmulu_link']['source_page_id'] ?? $entry['page_id'] ?? 0);
        $link = self::validLink($entry['fanmulu_link'] ?? null) ? $entry['fanmulu_link'] : null;
        if ($link === null && $sourceId > 0) {
            $link = FanmuluNavigation::link($snapshot, $sourceId);
        }
        $link ??= ['path' => FanmuluNavigation::rootPath($snapshot), 'text' => $snapshot['fanmulu']['display_title'],
            'keyword_id' => null, 'source_page_id' => $snapshot['fanmulu']['source_page_id'] ?? null];
        $original = (string) ($entry['content']['title'] ?? $card['title'] ?? '');
        $card['title'] = (string) $link['text'];
        $card['path'] = (string) $link['path'];
        $card['play_url'] = $card['path'] === FanmuluNavigation::rootPath($snapshot)
            ? FanmuluNavigation::scopePath($snapshot, 'play', '1') : $card['path'];
        $card['source_page_id'] = $link['source_page_id'];
        $card['keyword_id'] = $link['keyword_id'];
        if (is_string($card['body_excerpt'] ?? null)) {
            $card['body_excerpt'] = self::replaceLabels($card['body_excerpt'], $original, $card['title'], self::separateSeo($snapshot) ? [] : ($snapshot['assignments'] ?? []),
                self::separateSeo($snapshot) ? array_column(AssignmentPurpose::seo($snapshot['assignments'] ?? []), 'text') : []);
        }

        return $card;
    }

    public static function relatedLinks(array $snapshot): array
    {
        $links = $snapshot['related_links'] ?? [];
        if (! self::version2($snapshot) || ! FanmuluNavigation::enabled($snapshot)) {
            return $links;
        }
        foreach ($links as &$link) {
            $link['url'] = self::navigationUrl((string) ($link['url'] ?? ''), $snapshot);
            $link['text'] = self::destination($link['url'], $snapshot)['text'] ?? $snapshot['fanmulu']['display_title'];
        }
        unset($link);

        return $links;
    }

    /** Include actor-only destinations in server validation and compact runtime provenance. */
    private static function entries(array $snapshot): array
    {
        return [...($snapshot['aggregate']['entries'] ?? []), ...($snapshot['_live_recommendation_tabs']['actors'] ?? [])];
    }

    private static function destination(string $url, array $snapshot): ?array
    {
        $path = parse_url(self::navigationUrl($url, $snapshot), PHP_URL_PATH);
        if ($path === FanmuluNavigation::rootPath($snapshot) || str_starts_with((string) $path, FanmuluNavigation::rootPath($snapshot).'/_scope/')) {
            return ['text' => $snapshot['fanmulu']['display_title'], 'original' => $snapshot['fanmulu']['original_title'] ?? ''];
        }
        foreach (self::entries($snapshot) as $entry) {
            if (self::validLink($entry['fanmulu_link'] ?? null) && $entry['fanmulu_link']['path'] === $path) {
                return ['text' => $entry['fanmulu_link']['text'], 'original' => $entry['content']['title'] ?? ''];
            }
        }
        foreach ($snapshot['fanmulu']['navigation']['sources'] ?? [] as $sourceId => $source) {
            $link = FanmuluNavigation::link($snapshot, (int) $sourceId);
            if ($link !== null && $link['path'] === $path) {
                return ['text' => $link['text'], 'original' => $source['content']['title'] ?? ''];
            }
        }

        return null;
    }

    /** Route rendered navigation within the frozen fanmulu scope, without rewriting resource URLs. */
    public static function navigationUrl(string $url, array $snapshot, ?array $frozenPaths = null): string
    {
        if (! self::version2($snapshot) || ! FanmuluNavigation::enabled($snapshot)) {
            return $url;
        }
        $root = FanmuluNavigation::rootPath($snapshot);
        if ($url === '' || $url === '#' || str_starts_with($url, '#')) {
            return $url;
        }
        if (preg_match('/[\x00-\x20\\\\]/', $url) || ! ($parts = parse_url($url))) {
            return $root;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $base = parse_url($snapshot['site']['base_url'] ?? '');
            if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || ($parts['host'] ?? null) !== ($base['host'] ?? null)) {
                return $root;
            }
        }
        $path = $parts['path'] ?? ($snapshot['page']['path'] ?? $root);
        $tail = (isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
        if (preg_match('~(?:^|/)\.\.(?:/|$)~', rawurldecode($path))) {
            return $root;
        }
        if ($path === $root || str_starts_with($path, $root.'/_scope/')) {
            return $path.$tail;
        }
        $nav = $snapshot['fanmulu']['navigation'];
        // A historical regular canonical may share the fanmulu prefix.
        foreach ($nav['sources'] ?? [] as $sourceId => $source) {
            if (($source['path'] ?? null) === $path) {
                $link = FanmuluNavigation::link($snapshot, (int) $sourceId);

                return ($link['path'] ?? $root).$tail;
            }
        }
        // Search results can point to an existing publication with a historical prefix.
        foreach (self::entries($snapshot) as $entry) {
            if (self::validLink($entry['fanmulu_link'] ?? null) && $entry['fanmulu_link']['path'] === $path) {
                return $path.$tail;
            }
        }
        foreach ($nav['prefixes'] ?? [$nav['prefix']] as $prefix) {
            if (preg_match('~^'.preg_quote($prefix, '~').'/([1-9][0-9]*)-fm'.(int) ($snapshot['page']['id'] ?? 0)
                .'-'.preg_quote($nav['token'], '~').'-(0|[1-9][0-9]{0,2})'.preg_quote($nav['suffix'], '~').'$~D', $path, $intent)) {
                $link = FanmuluNavigation::link($snapshot, (int) $intent[1]);
                if (($link['path'] ?? null) === $path) {
                    return $path.$tail;
                }
            }
        }
        $profile = SitePresentationService::profile($snapshot['routing'] ?? []);
        foreach (['home', 'list', 'search'] as $view) {
            if ($path === SitePresentationService::endpointPath($profile, $view)) {
                return FanmuluNavigation::scopePath($snapshot, $view).$tail;
            }
        }
        $list = rtrim($profile['routes']['list'], '/').'/';
        if (str_starts_with($path, $list)) {
            $key = substr($path, strlen($list));
            $suffix = SitePresentationService::suffix($profile, 'list');
            if ($suffix !== '' && str_ends_with($key, $suffix)) {
                $key = substr($key, 0, -strlen($suffix));
            }
            if (preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $key)) {
                return FanmuluNavigation::scopePath($snapshot, 'list', $key).$tail;
            }
        }
        if (preg_match('~^'.preg_quote($profile['routes']['play'], '~').'/[^/]+/([1-9][0-9]*)'
            .preg_quote(SitePresentationService::suffix($profile, 'play'), '~').'$~D', $path, $episode)) {
            return FanmuluNavigation::scopePath($snapshot, 'play', $episode[1]).$tail;
        }
        if (FanmuluNavigation::opaque($snapshot) && isset(($frozenPaths ?? self::frozenPaths($snapshot))[$path])) {
            return $path.$tail;
        }

        return $root;
    }

    /** Compute frozen opaque destinations once per HTML pass, rather than once per anchor. */
    private static function frozenPaths(array $snapshot): array
    {
        $paths = [];
        foreach ($snapshot['fanmulu']['navigation']['sources'] ?? [] as $sourceId => $source) {
            $link = FanmuluNavigation::link($snapshot, (int) $sourceId);
            if ($link !== null) {
                $paths[$link['path']] = true;
            }
        }

        return $paths;
    }

    public static function markup(string $html, array $snapshot): string
    {
        return self::version2($snapshot) ? self::patchMarkup($html, $snapshot, true, false) : $html;
    }

    public static function navigationHtml(string $html, array $snapshot): string
    {
        return self::version2($snapshot) && FanmuluNavigation::enabled($snapshot)
            ? self::patchMarkup($html, $snapshot, false, true) : $html;
    }

    /** Patch source spans only; do not serialize or normalize the surrounding theme DOM. */
    private static function patchMarkup(string $html, array $snapshot, bool $labels, bool $navigation): string
    {
        $compact = $navigation && self::compactContext($snapshot);
        $frozenPaths = $navigation && (FanmuluNavigation::opaque($snapshot) || $compact) ? self::frozenPaths($snapshot) : null;
        $approvedPaths = $frozenPaths ?? [];
        if ($compact) {
            foreach (self::entries($snapshot) as $entry) {
                if (self::validLink($entry['fanmulu_link'] ?? null)) {
                    $approvedPaths[$entry['fanmulu_link']['path']] = true;
                }
            }
        }
        $markedTargets = [];
        $token = '~<!--[\s\S]*?-->|<![^>]*>|</?([A-Za-z][A-Za-z0-9:-]*)\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~';
        preg_match_all($token, $html, $matches, PREG_OFFSET_CAPTURE);
        $result = '';
        $offset = 0;
        $stack = [];
        $raw = null;
        $protected = false;
        foreach ($matches[0] as [$tag, $position]) {
            $text = substr($html, $offset, $position - $offset);
            $labelSnapshot = $stack !== [] ? end($stack)['labels'] : $snapshot;
            $result .= $labels && ! $protected && $raw === null ? self::textSpan($text, $labelSnapshot) : $text;
            $offset = $position + strlen($tag);
            if (! preg_match('~^<(/?)([A-Za-z][A-Za-z0-9:-]*)~', $tag, $element)) {
                $result .= $tag;

                continue;
            }
            $name = strtolower($element[2]);
            $closing = $element[1] === '/';
            if ($raw !== null && ! ($closing && $name === $raw)) {
                $result .= $tag;

                continue;
            }
            if ($compact && ! $closing) {
                // Reserved renderer marker: source markup cannot approve its own destinations.
                $tag = self::attributes($tag, ['data-tram-fm' => fn () => null]);
            }
            if ($closing) {
                while ($stack !== []) {
                    $node = array_pop($stack);
                    if ($node['name'] === $name) {
                        break;
                    }
                }
                if ($raw === $name) {
                    $raw = null;
                }
                $protected = $stack !== [] && end($stack)['protected'];
            } else {
                $nodeProtected = $protected || in_array($name, ['script', 'style', 'code', 'pre'], true)
                    || preg_match('~\sdata-seo-optout(?:\s|=|/?>)~i', $tag);
                $attributes = [];
                if ($labels && ! $nodeProtected && $name === 'a' && FanmuluNavigation::enabled($snapshot)) {
                    $href = '';
                    self::attributes($tag, ['href' => function ($value) use (&$href) {
                        $href = $value;

                        return $value;
                    }]);
                    $destination = self::destination($href, $snapshot);
                    if ($destination !== null) {
                        $labelSnapshot['fanmulu']['display_title'] = $destination['text'];
                        foreach ([$snapshot['fanmulu']['display_title'], $destination['original']] as $label) {
                            if (self::separateSeo($snapshot)) {
                                $labelSnapshot['_fanmulu_replacement_labels'][] = $label;
                            } else {
                                $labelSnapshot['assignments'][] = ['role' => 'secondary', 'text' => $label];
                            }
                        }
                    }
                }
                if ($labels && ! $nodeProtected) {
                    foreach (['title', 'alt', 'aria-label'] as $attribute) {
                        $attributes[$attribute] = fn ($value) => self::text($value, $labelSnapshot);
                    }
                }
                if ($navigation) {
                    $attributes['id'] = fn ($value) => $value === 'stui_history' ? 'tram_fanmulu_history' : $value;
                    if (in_array($name, ['a', 'area'], true)) {
                        $attributes['href'] = fn ($value) => self::navigationUrl($value, $snapshot, $frozenPaths);
                        if ($compact) {
                            $href = null;
                            self::attributes($tag, ['href' => function ($value) use (&$href) {
                                $href = $value;

                                return $value;
                            }]);
                            $target = $href === null ? null : parse_url(self::navigationUrl($href, $snapshot, $frozenPaths), PHP_URL_PATH);
                            if (is_string($target) && $target !== FanmuluNavigation::rootPath($snapshot)
                                && isset($approvedPaths[$target]) && ! isset($markedTargets[$target])) {
                                $markedTargets[$target] = true;
                                $attributes['data-tram-fm'] = fn () => '';
                            }
                        }
                    } elseif ($name === 'form') {
                        $attributes['action'] = fn ($value) => self::navigationUrl($value, $snapshot, $frozenPaths);
                    }
                    if ($name === 'body') {
                        $nav = $snapshot['fanmulu']['navigation'];
                        $targets = [];
                        foreach (self::entries($snapshot) as $entry) {
                            $link = $entry['fanmulu_link'] ?? null;
                            if (self::validLink($link) && $link['path'] !== FanmuluNavigation::rootPath($snapshot)) {
                                $targets[$link['path']] = $link['path'];
                                if (count($targets) === 120) {
                                    break;
                                }
                            }
                        }
                        $context = ['data-fanmulu-root' => FanmuluNavigation::rootPath($snapshot),
                            'data-fanmulu-prefix' => $nav['prefix'], 'data-fanmulu-keyword' => $snapshot['fanmulu']['display_title']];
                        if (! $compact) {
                            $context += ['data-fanmulu-scope' => 'true',
                                'data-fanmulu-home' => FanmuluNavigation::scopePath($snapshot, 'home'),
                                'data-fanmulu-search' => FanmuluNavigation::scopePath($snapshot, 'search'),
                                'data-fanmulu-targets' => Json::encode(array_values($targets))];
                        } else {
                            $tag = self::attributes($tag, array_fill_keys(['data-fanmulu-scope', 'data-fanmulu-home', 'data-fanmulu-search', 'data-fanmulu-targets'], fn () => null));
                        }
                        foreach ($context as $key => $value) {
                            $attributes[$key] = fn () => $value;
                        }
                    }
                }
                $tag = self::attributes($tag, $attributes);
                if (! in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true)
                    && ! str_ends_with($tag, '/>')) {
                    $stack[] = ['name' => $name, 'protected' => (bool) $nodeProtected, 'labels' => $labelSnapshot];
                    $protected = (bool) $nodeProtected;
                    if (in_array($name, ['script', 'style'], true)) {
                        $raw = $name;
                    }
                }
            }
            $result .= $tag;
        }
        $tail = substr($html, $offset);
        $labelSnapshot = $stack !== [] ? end($stack)['labels'] : $snapshot;

        return $result.($labels && ! $protected && $raw === null ? self::textSpan($tail, $labelSnapshot) : $tail);
    }

    private static function textSpan(string $text, array $snapshot): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $projected = self::text($decoded, $snapshot);

        return $projected === $decoded ? $text : htmlspecialchars($projected, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function attributes(string $tag, array $changes): string
    {
        $found = [];
        $pattern = '~(\s+)([A-Za-z_:][A-Za-z0-9:_.-]*)(?:(\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?~';
        $tag = preg_replace_callback($pattern, function ($match) use ($changes, &$found): string {
            $name = strtolower($match[2]);
            if (! isset($changes[$name])) {
                return $match[0];
            }
            $found[$name] = true;
            $value = html_entity_decode($match[4] ?? $match[5] ?? $match[6] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $projected = $changes[$name]($value);

            if ($projected === null) {
                return '';
            }

            return $projected === $value ? $match[0] : $match[1].$match[2].($match[3] ?? '=').'"'.htmlspecialchars($projected, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }, $tag, -1, $count, PREG_UNMATCHED_AS_NULL) ?? $tag;
        foreach ($changes as $name => $transform) {
            if (! isset($found[$name]) && (str_starts_with($name, 'data-fanmulu-') || $name === 'data-tram-fm')) {
                $value = $transform('');
                if ($value !== null) {
                    $tag = substr($tag, 0, -1).' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
                }
            }
        }

        return $tag;
    }

    private static function validLink(mixed $link): bool
    {
        return is_array($link) && is_string($link['path'] ?? null) && str_starts_with($link['path'], '/')
            && ! str_starts_with($link['path'], '//') && ! preg_match('/[\x00-\x20\x7f\\\\?#]/', $link['path'])
            && ! preg_match('~(?:^|/)\.\.(?:/|$)~', rawurldecode($link['path']))
            && is_string($link['text'] ?? null) && $link['text'] !== ''
            && is_int($link['keyword_id'] ?? null) && $link['keyword_id'] > 0
            && is_int($link['source_page_id'] ?? null) && $link['source_page_id'] > 0;
    }

    private static function replaceText(string $text, array $frozen): string
    {
        $original = $frozen['original_title'] ?? '';

        return is_string($original) && $original !== '' ? str_replace($original, $frozen['display_title'], $text) : $text;
    }

    private static function replaceBody(string $body, array $frozen): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="fanmulu-body">'.$body.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[@id="fanmulu-body"]//text()[not(ancestor::script or ancestor::style or ancestor::code or ancestor::pre or ancestor::*[@data-seo-optout])]') as $node) {
            $node->nodeValue = self::replaceText($node->nodeValue ?? '', $frozen);
        }
        $html = '';
        foreach ($dom->getElementById('fanmulu-body')->childNodes as $node) {
            $html .= $dom->saveHTML($node);
        }

        return $html;
    }
}
