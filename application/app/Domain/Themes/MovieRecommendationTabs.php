<?php

namespace App\Domain\Themes;

/** Adapt known legacy MYUI/EWave regions on a response copy, never stored theme bytes. */
final class MovieRecommendationTabs
{
    private const TAG = '~<!--[\s\S]*?-->|<(?:(?:"[^"]*")|(?:\'[^\']*\')|[^\'">])*>~';

    private const ATTRIBUTE = '~\s+(?<name>[^\s"\'<>/=]+)(?:\s*=\s*(?<value>"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?~';

    public function adapt(string $fragment): string
    {
        $nodes = $this->nodes($fragment);
        $actorLinks = $this->tabLinks($fragment, $nodes, '#actor', '同主演');
        $typeLinks = $this->tabLinks($fragment, $nodes, '#type', '同类型');
        $actor = array_values(array_filter($nodes, fn ($node) => $this->pane($node, 'actor')));
        $type = array_values(array_filter($nodes, fn ($node) => $this->pane($node, 'type')));
        if (count($actorLinks) !== 1 || count($typeLinks) !== 1 || count($actor) !== 1 || count($type) !== 1
            || $actor[0]['parent'] !== $type[0]['parent']) {
            return $fragment;
        }
        $node = $actor[0];
        $inner = $this->inner($fragment, $node);
        $changed = preg_replace('~\A(\s*)\{\{\s*\#each\s+recommended_movies\s*\}\}~', '$1{{#each actor_recommended_movies}}', $inner, 1, $count);
        if ($count !== 1) {
            return $fragment;
        }
        $changed = preg_replace('~(\{\{\s*else\s*\}\}\s*<li\b[^>]*>)\s*暂无影片。\s*(</li>\s*\{\{\s*/each\s*\}\})~u', '$1暂无同主演影片。$2', $changed, 1);

        return substr_replace($fragment, $changed, $node['opening_end'], $node['closing_start'] - $node['opening_end']);
    }

    /** Insert only into a recognized existing empty comment region, retaining disabled forms. */
    public function comments(string $fragment): string
    {
        $nodes = $this->nodes($fragment);
        $patches = [];
        foreach ($nodes as $index => $box) {
            if ($box['tag'] !== 'div' || ! in_array('comment-box', $box['classes'], true)) {
                continue;
            }
            $headings = array_values(array_filter($nodes, fn ($node) => in_array($node['tag'], ['h2', 'h3'], true)
                && $this->inside($node, $box) && in_array($this->text($this->inner($fragment, $node)), ['用户评论', '影片评论'], true)));
            if (count($headings) !== 1) {
                continue;
            }
            $regions = array_values(array_filter($nodes, fn ($node) => $node['tag'] === 'div' && $this->inside($node, $box)
                && array_intersect($node['classes'], ['myui_comment', 'ewave-comment']) !== []));
            if (count($regions) !== 1) {
                continue;
            }
            $region = $regions[0];
            $inner = $this->inner($fragment, $region);
            if (in_array('myui_comment', $region['classes'], true)) {
                $forms = array_values(array_filter($nodes, fn ($node) => $node['tag'] === 'form'
                    && in_array('comment_form', $node['classes'], true) && $this->inside($node, $region)));
                if (count($forms) !== 1) {
                    continue;
                }
                $form = $forms[0];
                $outside = substr($fragment, $region['opening_end'], $form['start'] - $region['opening_end'])
                    .substr($fragment, $form['end'], $region['closing_start'] - $form['end']);
                if ($this->withoutComments($outside) !== '') {
                    continue;
                }
                $list = '<ul class="myui-comment__list clearfix" data-tram-sample-comments="true">{{#each comments}}<li class="col-pd bottom-line"><p class="text-muted">{{comment.author}}</p><p>{{comment.text}}</p></li>{{else}}<li class="col-pd text-muted">暂无示例评论。</li>{{/each}}</ul>';
                $patches[] = [$form['end'], 0, $list];
            } else {
                foreach ($nodes as $child) {
                    if ($child['tag'] === 'div' && in_array('ewave-empty', $child['classes'], true)
                        && $this->inside($child, $region) && $this->withoutComments($this->inner($fragment, $child)) === '') {
                        $inner = str_replace(substr($fragment, $child['start'], $child['end'] - $child['start']), '', $inner);
                    }
                }
                if ($this->withoutComments($inner) !== '') {
                    continue;
                }
                $list = '<ul class="comment-list" data-tram-sample-comments="true">{{#each comments}}<li class="gap"><p class="text-muted">{{comment.author}}</p><p>{{comment.text}}</p></li>{{else}}<li>暂无示例评论。</li>{{/each}}</ul>';
                $patches[] = [$region['opening_end'], $region['closing_start'] - $region['opening_end'], $list];
            }
            $heading = $headings[0];
            $patches[] = [$heading['opening_end'], $heading['closing_start'] - $heading['opening_end'], '示例评论'];
        }
        usort($patches, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($patches as [$offset, $length, $replacement]) {
            $fragment = substr_replace($fragment, $replacement, $offset, $length);
        }

        return $fragment;
    }

    private function pane(array $node, string $id): bool
    {
        return $node['tag'] === 'ul' && ($node['attributes']['id'] ?? '') === $id
            && in_array('tab-pane', $node['classes'], true) && in_array('myui-vodlist__bd', $node['classes'], true);
    }

    private function tabLinks(string $fragment, array $nodes, string $href, string $text): array
    {
        return array_values(array_filter($nodes, fn ($node) => $node['tag'] === 'a'
            && ($node['attributes']['href'] ?? '') === $href && ($node['attributes']['data-toggle'] ?? '') === 'tab'
            && $this->text($this->inner($fragment, $node)) === $text));
    }

    private function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($this->withoutComments($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function withoutComments(string $html): string
    {
        return trim(preg_replace('~<!--[\s\S]*?-->~', '', $html));
    }

    private function inner(string $fragment, array $node): string
    {
        return substr($fragment, $node['opening_end'], $node['closing_start'] - $node['opening_end']);
    }

    private function inside(array $node, array $parent): bool
    {
        return $node['start'] >= $parent['opening_end'] && $node['end'] <= $parent['closing_start'];
    }

    /** Record source spans without serializing or altering unrelated markup. */
    private function nodes(string $fragment): array
    {
        $nodes = [];
        $stack = [];
        $offset = 0;
        while (preg_match(self::TAG, $fragment, $match, PREG_OFFSET_CAPTURE, $offset)) {
            [$source, $position] = $match[0];
            $offset = $position + strlen($source);
            if (str_starts_with($source, '<!--') || ! preg_match('~^<\s*(/?)\s*([a-z][a-z0-9:-]*)\b~i', $source, $tag)) {
                continue;
            }
            $name = strtolower($tag[2]);
            if ($tag[1] !== '') {
                for ($index = count($stack) - 1; $index >= 0; $index--) {
                    $nodeId = $stack[$index];
                    if ($nodes[$nodeId]['tag'] === $name) {
                        $nodes[$nodeId]['closing_start'] = $position;
                        $nodes[$nodeId]['end'] = $offset;
                        $stack = array_slice($stack, 0, $index);
                        break;
                    }
                }

                continue;
            }
            if (in_array($name, ['script', 'style', 'textarea', 'title'], true)) {
                if (preg_match('~</'.preg_quote($name, '~').'\s*>~i', $fragment, $close, PREG_OFFSET_CAPTURE, $offset)) {
                    $offset = $close[0][1] + strlen($close[0][0]);
                }

                continue;
            }
            $attributes = [];
            preg_match_all(self::ATTRIBUTE, $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $attribute) {
                $key = strtolower($attribute['name']);
                $attributes[$key] = html_entity_decode(trim($attribute['value'] ?? '', "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $id = count($nodes);
            $nodes[] = ['tag' => $name, 'start' => $position, 'opening_end' => $offset, 'closing_start' => $offset,
                'end' => null, 'parent' => $stack === [] ? null : $stack[array_key_last($stack)], 'attributes' => $attributes,
                'classes' => preg_split('/\s+/', trim($attributes['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)];
            if (in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true) || str_ends_with($source, '/>')) {
                $nodes[$id]['end'] = $offset;
            } else {
                $stack[] = $id;
            }
        }

        return array_values(array_filter($nodes, fn ($node) => $node['end'] !== null));
    }
}
