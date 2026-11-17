<?php

namespace App\Domain\Fanmulu;

/** Locate an existing movie list and patch only new cards, leaving document bytes intact. */
final class FanmuluListInsertion
{
    private const TAG = '~<!--[\s\S]*?-->|<(?:(?:"[^"]*")|(?:\'[^\']*\')|[^\'">])*>~';

    private const ATTRIBUTE = '~(?<prefix>[\x20\t\r\n\f]+)(?<name>[^\x20\t\r\n\f"\'<>/=]+)(?<equals>[\x20\t\r\n\f]*=[\x20\t\r\n\f]*)(?<value>"[^"]*"|\'[^\']*\'|[^\x20\t\r\n\f"\'=<>`]+)~';

    /** @return array{offset: int, template: string}|null */
    public function locate(string $html): ?array
    {
        if (str_contains($html, 'data-fanmulu-discovery=')) {
            return null;
        }
        $stack = [];
        $targets = [];
        $offset = 0;
        while (preg_match(self::TAG, $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
            [$tag, $position] = $match[0];
            $offset = $position + strlen($tag);
            if (str_starts_with($tag, '<!--') || ! preg_match('~^<\s*(/?)\s*([a-z][a-z0-9:-]*)\b~i', $tag, $name)) {
                continue;
            }
            $name[2] = strtolower($name[2]);
            if ($name[1] === '/') {
                if ($stack === [] || end($stack)['tag'] !== $name[2]) {
                    continue;
                }
                $node = array_pop($stack);
                $node['end'] = $offset;
                $classes = $node['classes'];
                $parent = $stack === [] ? null : end($stack);
                $list = in_array($node['tag'], ['ul', 'ol'], true)
                    && (array_intersect($classes, ['stui-vodlist', 'stui-vodlist__bd', 'stui-vodlist__text', 'movie-list', 'related-list']) !== []
                        || ($parent !== null && in_array('related-panel', $parent['classes'], true)));
                $grid = in_array($node['tag'], ['div', 'section'], true)
                    && array_intersect($classes, ['films', 'movie-grid', 'film-grid', 'movie-list', 'related-list']) !== [];
                if ($list || $grid) {
                    foreach ($node['children'] as $child) {
                        if (($list && $child['tag'] === 'li') || ($grid && in_array($child['tag'], ['article', 'div', 'li', 'a'], true))) {
                            $template = substr($html, $child['start'], $child['end'] - $child['start']);
                            if (preg_match('~<a\b[^>]*\bhref\s*=~i', $template)) {
                                $targets[] = ['offset' => $position, 'template' => $template,
                                    'priority' => in_array('stui-vodlist__text', $classes, true) ? 1 : 0];
                                break;
                            }
                        }
                    }
                }
                if ($stack !== []) {
                    $stack[array_key_last($stack)]['children'][] = ['tag' => $node['tag'], 'start' => $node['start'], 'end' => $node['end']];
                }

                continue;
            }
            if (in_array($name[2], ['script', 'style', 'textarea', 'title'], true)) {
                if (preg_match('~</'.preg_quote($name[2], '~').'\s*>~i', $html, $close, PREG_OFFSET_CAPTURE, $offset)) {
                    $offset = $close[0][1] + strlen($close[0][0]);
                } else {
                    break;
                }

                continue;
            }
            if (str_ends_with(rtrim($tag), '/>') || in_array($name[2], ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true)) {
                continue;
            }
            $classes = [];
            if (preg_match_all(self::ATTRIBUTE, $tag, $attributes, PREG_SET_ORDER)) {
                foreach ($attributes as $attribute) {
                    if (strtolower($attribute['name']) === 'class') {
                        $classes = preg_split('/\s+/', trim(self::value($attribute['value'])));
                    }
                }
            }
            $stack[] = ['tag' => $name[2], 'start' => $position, 'classes' => $classes, 'children' => []];
        }
        if ($targets === []) {
            return null;
        }
        usort($targets, fn (array $a, array $b): int => $a['priority'] <=> $b['priority'] ?: $a['offset'] <=> $b['offset']);

        return array_intersect_key($targets[0], ['offset' => true, 'template' => true]);
    }

    public function insert(string $html, array $target, array $links): string
    {
        $cards = '';
        foreach ($links as $link) {
            $cards .= $this->card($target['template'], $link);
        }

        return substr($html, 0, $target['offset']).$cards.substr($html, $target['offset']);
    }

    private function card(string $template, array $link): string
    {
        $label = htmlspecialchars($link['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = htmlspecialchars($link['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $template = preg_replace_callback(self::TAG, static function (array $token) use ($label, $url): string {
            $tag = $token[0];
            if (! preg_match('~^<\s*([a-z][a-z0-9:-]*)\b~i', $tag, $name)) {
                return $tag;
            }

            return preg_replace_callback(self::ATTRIBUTE, static function (array $attribute) use ($name, $label, $url): string {
                $key = strtolower($attribute['name']);
                if ($key === 'id') {
                    return '';
                }
                if (($key === 'href' && strtolower($name[1]) === 'a') || in_array($key, ['title', 'alt', 'aria-label'], true)) {
                    return $attribute['prefix'].$attribute['name'].$attribute['equals'].'"'.($key === 'href' ? $url : $label).'"';
                }

                return $attribute[0];
            }, $tag);
        }, $template);
        // Preserve nested spans/icons while replacing visible link labels.
        $template = preg_replace_callback('~(<a\b[^>]*>)([\s\S]*?)(</a\s*>)~i', static function (array $anchor) use ($label): string {
            $replaced = false;
            $body = preg_replace_callback('~(^|>)([^<]+)(?=<|$)~', static function (array $text) use ($label, &$replaced): string {
                if (trim(html_entity_decode($text[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') {
                    return $text[0];
                }
                if ($replaced) {
                    return $text[1];
                }
                $replaced = true;

                return $text[1].$label;
            }, $anchor[2]);

            return $anchor[1].$body.$anchor[3];
        }, $template);

        return preg_replace('~^(<\s*[a-z][a-z0-9:-]*)\b~i', '$1 data-fanmulu-links="true" data-fanmulu-discovery="true"', $template, 1);
    }

    private static function value(string $value): string
    {
        return html_entity_decode(in_array($value[0] ?? '', ['"', "'"], true) ? substr($value, 1, -1) : $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
