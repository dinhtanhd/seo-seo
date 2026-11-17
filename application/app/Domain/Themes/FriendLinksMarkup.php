<?php

namespace App\Domain\Themes;

use App\Domain\Rules\Html;

/** Patch only friend-link lists and their own container, leaving the rest of the source intact. */
final class FriendLinksMarkup
{
    public const CSS = '.tram-friend-links{display:block!important;clear:both;box-sizing:border-box;text-align:left}.tram-friend-links h2,.tram-friend-links h3,.tram-friend-links h4,.tram-friend-links h5{color:inherit!important}.tram-friend-links h2,.tram-friend-links h3{font-size:16px;line-height:1.5}.tram-friend-links ul,.tram-friend-links ol{display:flex;flex-wrap:wrap;gap:8px 20px;list-style:none;margin:0;padding:0}.tram-friend-links li{float:none!important;width:auto!important;max-width:100%;margin:0;padding:0!important;list-style:none}.tram-friend-links a{display:inline-block;white-space:normal;overflow-wrap:anywhere;word-break:break-word;line-height:1.7}.tram-friend-links-fallback{max-width:1400px;margin:24px auto;padding:16px 20px;border-top:1px solid currentColor}.tram-friend-links-fallback h2{margin:0 0 12px}.tram-friend-links-fallback a{font-size:14px}@media(max-width:640px){.tram-friend-links-fallback{margin:18px 12px;padding:14px 4px}.tram-friend-links ul,.tram-friend-links ol{gap:6px 16px}}';

    private const TAG = '~<!--[\s\S]*?-->|<(?:(?:"[^"]*")|(?:\'[^\']*\')|[^\'">])*>~';

    private const ATTRIBUTE = '~(?<prefix>[\x20\t\r\n\f]+)(?<name>[^\x20\t\r\n\f"\'<>/=]+)(?:(?<equals>[\x20\t\r\n\f]*=[\x20\t\r\n\f]*)(?<value>"[^"]*"|\'[^\']*\'|[^\x20\t\r\n\f"\'=<>`]+))?~';

    /** @param array<int,array{text:string,url:string}> $links */
    public function decorate(string $html, array $links): string
    {
        $html = $this->removeOwnStyles($html);
        $items = $this->items($links);
        $nodes = $this->nodes($html);
        $regions = $this->regions($html, $nodes);
        $patches = [];
        foreach ($regions as [$region, $list]) {
            if ($items === '') {
                $patches[] = [$region['start'], $region['end'] - $region['start'], ''];

                continue;
            }
            $opening = substr($html, $region['start'], $region['opening_end'] - $region['start']);
            $patches[] = [$region['start'], strlen($opening), $this->visibleOpening($opening)];
            $patches[] = [$list['opening_end'], $list['closing_start'] - $list['opening_end'], $items];
        }
        if ($regions === [] && $items !== '') {
            $block = '<section class="tram-friend-links tram-friend-links-fallback" aria-label="友情链接" data-tram-friend-links="true"><h2>友情链接</h2><ul>'.$items.'</ul></section>';
            $footer = null;
            foreach ($nodes as $node) {
                if ($node['tag'] === 'footer') {
                    $footer = $node['start'];
                    break;
                }
            }
            if ($footer === null) {
                foreach ($nodes as $node) {
                    if (array_intersect($node['classes'], ['stui-foot', 'myui-foot', 'site-footer', 'bottom']) !== []) {
                        $footer = $node['start'];
                        break;
                    }
                }
            }
            $offset = $footer ?? strripos($html, '</body>');
            $patches[] = [$offset === false ? strlen($html) : $offset, 0, $block];
        }
        usort($patches, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($patches as [$offset, $length, $replacement]) {
            $html = substr_replace($html, $replacement, $offset, $length);
        }
        if ($items !== '') {
            $style = '<style data-tram-friend-links-style="true">'.self::CSS.'</style>';
            $head = null;
            foreach ($this->nodes($html) as $node) {
                if ($node['tag'] === 'head' && $node['end'] > $node['opening_end']) {
                    $head = $node['closing_start'];
                    break;
                }
            }
            $html = $head === null ? $html.$style : substr_replace($html, $style, $head, 0);
        }

        return $html;
    }

    private function removeOwnStyles(string $html): string
    {
        $offset = 0;
        $patches = [];
        while (preg_match(self::TAG, $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
            [$source, $position] = $match[0];
            $offset = $position + strlen($source);
            if (str_starts_with($source, '<!--') || ! preg_match('~^<\s*(script|style|textarea|title)\b~i', $source, $tag)) {
                continue;
            }
            $name = strtolower($tag[1]);
            if (! preg_match('~</'.preg_quote($name, '~').'\s*>~i', $html, $close, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }
            $offset = $close[0][1] + strlen($close[0][0]);
            if ($name === 'style' && preg_match_all(self::ATTRIBUTE, $source, $attributes, PREG_SET_ORDER)) {
                foreach ($attributes as $attribute) {
                    if (strtolower($attribute['name']) === 'data-tram-friend-links-style') {
                        $patches[] = [$position, $offset - $position];
                        break;
                    }
                }
            }
        }
        foreach (array_reverse($patches) as [$position, $length]) {
            $html = substr_replace($html, '', $position, $length);
        }

        return $html;
    }

    private function items(array $links): string
    {
        $items = '';
        $seen = [];
        foreach ($links as $link) {
            if (! is_array($link) || ! is_string($link['url'] ?? null) || ! is_string($link['text'] ?? null)
                || ! Html::safeUrl($link['url']) || trim($link['text']) === '' || isset($seen[$link['url']])) {
                continue;
            }
            $seen[$link['url']] = true;
            $url = htmlspecialchars($link['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text = htmlspecialchars($link['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $items .= '<li><a href="'.$url.'" title="'.$text.'" target="_blank" rel="noopener noreferrer">'.$text.'</a></li>';
            if (count($seen) === 10) {
                break;
            }
        }

        return $items;
    }

    private function visibleOpening(string $opening): string
    {
        $hasClass = false;
        $opening = preg_replace_callback(self::ATTRIBUTE, function ($attribute) use (&$hasClass): string {
            $name = strtolower($attribute['name']);
            $value = self::value($attribute['value'] ?? '');
            if ($name === 'class') {
                $hasClass = true;
                $classes = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
                $classes = array_diff($classes, ['hidden', 'hide', 'd-none', 'hidden-xs', 'hidden-sm', 'hidden-md', 'hidden-lg', 'hidden-xl', 'hidden-xxl', 'hidden-pc', 'hide-pc']);
                $classes[] = 'tram-friend-links';

                return $attribute['prefix'].'class="'.htmlspecialchars(implode(' ', array_unique($classes)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            }
            if ($name === 'style') {
                $declarations = array_filter(explode(';', $value), fn ($declaration) => trim($declaration) !== '' && ! preg_match('~^\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:!important)?\s*$~i', $declaration));

                return $declarations === [] ? '' : $attribute['prefix'].'style="'.htmlspecialchars(implode(';', $declarations), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            }
            if ($name === 'hidden' || $name === 'data-tram-friend-links') {
                return '';
            }

            return $attribute[0];
        }, $opening);

        return substr_replace($opening, ($hasClass ? '' : ' class="tram-friend-links"').' data-tram-friend-links="true"', strrpos($opening, '>'), 0);
    }

    /** @return array<int,array{start:int,opening_end:int,closing_start:int,end:int,tag:string,parent:?int,classes:array}> */
    private function nodes(string $html): array
    {
        $nodes = [];
        $stack = [];
        $offset = 0;
        while (preg_match(self::TAG, $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
            [$source, $position] = $match[0];
            $offset = $position + strlen($source);
            if (str_starts_with($source, '<!--') || ! preg_match('~^<\s*(/?)\s*([a-z][a-z0-9:-]*)\b~i', $source, $tag)) {
                continue;
            }
            $name = strtolower($tag[2]);
            if ($tag[1] === '/') {
                if ($stack !== [] && $nodes[end($stack)]['tag'] === $name) {
                    $id = array_pop($stack);
                    $nodes[$id]['closing_start'] = $position;
                    $nodes[$id]['end'] = $offset;
                }

                continue;
            }
            if (in_array($name, ['script', 'style', 'textarea', 'title'], true)) {
                if (preg_match('~</'.preg_quote($name, '~').'\s*>~i', $html, $close, PREG_OFFSET_CAPTURE, $offset)) {
                    $offset = $close[0][1] + strlen($close[0][0]);
                } else {
                    break;
                }

                continue;
            }
            if (str_ends_with(rtrim($source), '/>') || in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true)) {
                continue;
            }
            $classes = [];
            if (preg_match_all(self::ATTRIBUTE, $source, $attributes, PREG_SET_ORDER)) {
                foreach ($attributes as $attribute) {
                    if (strtolower($attribute['name']) === 'class') {
                        $classes = preg_split('/\s+/', trim(self::value($attribute['value'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
                    }
                }
            }
            $id = count($nodes);
            $nodes[] = ['start' => $position, 'opening_end' => $offset, 'closing_start' => $offset, 'end' => $offset,
                'tag' => $name, 'parent' => $stack === [] ? null : end($stack), 'classes' => $classes];
            $stack[] = $id;
        }

        return $nodes;
    }

    private function regions(string $html, array $nodes): array
    {
        $regions = [];
        foreach ($nodes as $node) {
            if (! in_array($node['tag'], ['h2', 'h3', 'h4', 'h5'], true) && ! in_array('widget-list-title', $node['classes'], true)) {
                continue;
            }
            $text = html_entity_decode(strip_tags(substr($html, $node['opening_end'], $node['closing_start'] - $node['opening_end'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_replace('/\s+/u', '', $text) !== '友情链接') {
                continue;
            }
            $parent = $node['parent'];
            $selected = null;
            while ($parent !== null) {
                $region = $nodes[$parent];
                if (in_array($region['tag'], ['html', 'body', 'main', 'article'], true)) {
                    break;
                }
                foreach ($nodes as $list) {
                    if (! in_array($region['tag'], ['nav', 'header', 'footer', 'form'], true) && in_array($list['tag'], ['ul', 'ol'], true)
                        && $list['start'] >= $node['end'] && $list['end'] <= $region['end']
                        && $this->sameWidget($region, $node, $list, $nodes, $html)) {
                        if ($selected === null || array_intersect($region['classes'], ['myui-panel', 'stui-pannel', 'links-box', 'contpost', 'tram-friend-links']) !== []) {
                            $selected = [$region, $list];
                        }
                        break;
                    }
                }
                $parent = $region['parent'];
            }
            if ($selected !== null) {
                $regions[$selected[0]['start']] = $selected;
            }
        }

        return array_values($regions);
    }

    /** A heading and a list must own the widget; neighboring film content is never part of it. */
    private function sameWidget(array $region, array $heading, array $list, array $nodes, string $html): bool
    {
        if ($list['closing_start'] < $list['opening_end'] || $list['end'] <= $list['opening_end']) {
            return false;
        }
        $patches = [[$heading['start'], $heading['end'] - $heading['start']], [$list['start'], $list['end'] - $list['start']]];
        foreach ($nodes as $node) {
            if ($node['start'] <= $region['start'] || $node['end'] > $region['end']) {
                continue;
            }
            $containsHeading = $node['start'] < $heading['start'] && $node['end'] >= $heading['end'];
            $containsList = $node['start'] < $list['start'] && $node['end'] >= $list['end'];
            if (! $containsHeading && ! $containsList) {
                continue;
            }
            if ($containsList && ! $containsHeading && in_array($node['tag'], ['section', 'article', 'aside', 'main', 'nav', 'header', 'footer', 'form'], true)) {
                return false;
            }
            $patches[] = [$node['start'], $node['opening_end'] - $node['start']];
            $patches[] = [$node['closing_start'], $node['end'] - $node['closing_start']];
        }
        usort($patches, fn ($a, $b) => $b[0] <=> $a[0]);
        $remainder = substr($html, $region['opening_end'], $region['closing_start'] - $region['opening_end']);
        foreach ($patches as [$offset, $length]) {
            $remainder = substr_replace($remainder, '', $offset - $region['opening_end'], $length);
        }
        $remainder = preg_replace('~<!--[\s\S]*?-->~', '', $remainder);

        return preg_replace('/[\s\p{Z}]+/u', '', html_entity_decode($remainder, ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '';
    }

    private static function value(string $value): string
    {
        return html_entity_decode(in_array($value[0] ?? '', ['"', "'"], true) ? substr($value, 1, -1) : $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
