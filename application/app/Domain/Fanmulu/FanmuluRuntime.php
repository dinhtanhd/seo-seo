<?php

namespace App\Domain\Fanmulu;

use App\Domain\Support\Json;

/** Remove retired fanmulu interactions from the serving copy of immutable HTML. */
final class FanmuluRuntime
{
    private const TAG = '~<!--[\s\S]*?-->|<(?:(?:"[^"]*")|(?:\'[^\']*\')|[^\'">])*>~';

    private const ATTRIBUTE = '~[\x20\t\r\n\f]+(?<name>[^\x20\t\r\n\f"\'<>/=]+)(?:[\x20\t\r\n\f]*=[\x20\t\r\n\f]*(?<value>"[^"]*"|\'[^\']*\'|[^\x20\t\r\n\f"\'=<>`]+))?~';

    private const FZHM_HISTORY_HASH = '57ad1b77d616aeab7700ed4a759506331977fc8d8bbb8815dc6e67d1db834b8b';

    private const IMPORTED_RUNTIME_HASH = '5a3d6a53000dd26ce1e95a05af118bc0aa1d2c23797529cfe155d3e5a348e60c';

    private const PRIOR_IMPORTED_RUNTIME_HASHES = [
        '50f71e49ffa50703785674056d4431ba409414d15dd3ede2e9991049e9e7a684',
        'f435ab57233be835a09a78a56ada860e73c352fdc48ca227c8dd07e34add8724',
    ];

    public function reference(): ?array
    {
        $manifest = public_path('build/fanmulu/manifest.json');
        if (! is_file($manifest)) {
            return null;
        }
        try {
            $reference = Json::decode(file_get_contents($manifest));
        } catch (\Throwable) {
            return null;
        }

        return $this->valid($reference) ? $reference : null;
    }

    public function attach(string $html, array $snapshot): string
    {
        return FanmuluPresentation::enabled($snapshot) ? $this->stripInteractions($html) : $html;
    }

    /** Apply the same interaction policy when serving historical HTML. */
    public function friendLinksOverlay(string $html, bool $fanmulu = false): string
    {
        if ($fanmulu) {
            return $this->stripInteractions($html, swapImportedRuntime: true);
        }
        if (! str_contains($html, 'data-fanmulu-root') && ! str_contains($html, 'tram-fanmulu-runtime-')) {
            return $html;
        }
        foreach ($this->nodes($html) as $node) {
            if (! $node['protected'] && ($this->ownScript($node)
                || ($node['tag'] === 'body' && isset($node['attributes']['data-fanmulu-root'])))) {
                return $this->stripInteractions($html, swapImportedRuntime: true);
            }
        }

        return $html;
    }

    /** Patch only known interaction spans; all bytes outside those spans stay intact. */
    public function stripInteractions(string $html, bool $swapImportedRuntime = false): string
    {
        $nodes = $this->nodes($html);
        $remove = [];
        $replace = [];
        $myThemeHosts = [];
        foreach ($nodes as $id => $node) {
            if ($node['protected'] || ! $node['closed']) {
                continue;
            }
            if ($this->ownScript($node)
                || $this->reviewedScript($node, 'default3/assets/js/history.js', [self::FZHM_HISTORY_HASH])
                || $this->retiredControl($node, $nodes)
                || ($node['tag'] === 'div' && array_intersect(['ewave-history-dropdown', 'ewave-history-box'], $node['classes']) !== [])
                || (in_array($node['tag'], ['div', 'ul', 'ol'], true)
                    && (isset($node['attributes']['data-tram-history']) || in_array('ewave-history-list', $node['classes'], true)))) {
                $remove[$id] = $node;
            }
            if ($swapImportedRuntime && $this->reviewedScript($node, 'js/tram-imported-runtime.js', self::PRIOR_IMPORTED_RUNTIME_HASHES)) {
                $opening = substr($html, $node['start'], $node['opening_end'] - $node['start']);
                $replace[$node['start']] = [$node['opening_end'], $this->importedOpening($opening, $node)];
            }
            $fzhmList = $node['tag'] === 'ul' && in_array($node['attributes']['id'] ?? null, ['stui_history', 'tram_fanmulu_history'], true);
            $myThemeList = $node['tag'] === 'div' && in_array('history-list', $node['classes'], true);
            if (! $fzhmList && ! $myThemeList) {
                continue;
            }
            if ($fzhmList) {
                $remove[$id] = $node;
            }
            $parent = $node['parent'];
            while ($parent !== null) {
                $ancestor = $nodes[$parent];
                if ($fzhmList && $ancestor['tag'] === 'div' && $ancestor['closed']
                    && in_array('dropdown', $ancestor['classes'], true) && in_array('history', $ancestor['classes'], true)) {
                    $remove[$parent] = $ancestor;
                    break;
                }
                $host = $ancestor['parent'];
                if ($myThemeList && $ancestor['tag'] === 'div' && $ancestor['closed']
                    && in_array('dropdown-box', $ancestor['classes'], true) && $host !== null && $this->myThemeHistoryHost($nodes[$host])) {
                    $remove[$parent] = $ancestor;
                    $myThemeHosts[$host] = true;
                    break;
                }
                if (in_array($ancestor['tag'], ['html', 'body', 'header', 'nav', 'main', 'article', 'form', 'li'], true)) {
                    break;
                }
                $parent = $ancestor['parent'];
            }
        }

        $ranges = array_reverse($this->ranges($remove));
        foreach ($nodes as $id => $node) {
            if (! $node['closed'] || $node['protected']) {
                continue;
            }
            $parent = $node['parent'] === null ? null : $nodes[$node['parent']];
            $fzhmHost = $node['tag'] === 'li' && $parent !== null && $parent['tag'] === 'ul'
                && in_array('stui-header__user', $parent['classes'], true);
            $ewaveHost = $node['tag'] === 'div' && in_array('ewave-history', $node['classes'], true);
            if (! $fzhmHost && ! $ewaveHost && ! isset($myThemeHosts[$id])) {
                continue;
            }
            $inner = substr($html, $node['opening_end'], $node['closing_start'] - $node['opening_end']);
            $removed = false;
            foreach ($ranges as [$start, $end]) {
                if ($start >= $node['opening_end'] && $end <= $node['closing_start']) {
                    $inner = substr_replace($inner, '', $start - $node['opening_end'], $end - $start);
                    $removed = true;
                }
            }
            if (($removed || $ewaveHost) && trim($inner) === '') {
                $remove[$id] = $node;
            }
        }

        foreach ($this->ranges($remove) as [$start, $end]) {
            foreach (array_keys($replace) as $offset) {
                if ($offset >= $start && $offset < $end) {
                    unset($replace[$offset]);
                }
            }
            $replace[$start] = [$end, ''];
        }
        krsort($replace, SORT_NUMERIC);
        foreach ($replace as $start => [$end, $markup]) {
            $html = substr_replace($html, $markup, $start, $end - $start);
        }

        return $html;
    }

    private function retiredControl(array $node, array $nodes): bool
    {
        if (! in_array($node['tag'], ['a', 'button'], true)) {
            return false;
        }
        $actions = ['history-toggle', 'history-clear', 'favorite'];
        if (in_array($node['attributes']['data-fzhm-action'] ?? null, $actions, true)
            || in_array($node['attributes']['data-tram-action'] ?? null, $actions, true)
            || array_intersect(['historyclean', 'ewave-history-clear', 'ewave-history-text', 'ewave-favorite', 'myui-favorite'], $node['classes']) !== []) {
            return true;
        }
        $href = $node['attributes']['href'] ?? '';
        if (! in_array($href, ['', '#', '#mytheme-runtime', '#tram-runtime-state'], true)) {
            return false;
        }

        return in_array('collection', $node['classes'], true)
            || ($node['tag'] === 'a' && $node['parent'] !== null && $this->myThemeHistoryHost($nodes[$node['parent']]));
    }

    private function myThemeHistoryHost(array $node): bool
    {
        return $node['tag'] === 'li' && in_array('hist', $node['classes'], true)
            && in_array('dropdown-hover', $node['classes'], true);
    }

    private function ownScript(array $node): bool
    {
        return $node['tag'] === 'script'
            && preg_match('~^/build/fanmulu/tram-fanmulu-runtime-[a-f0-9]{8,64}\.js(?:[?#][^\s]*)?$~D', $node['attributes']['src'] ?? '') === 1;
    }

    private function reviewedScript(array $node, string $asset, array $hashes): bool
    {
        if ($node['tag'] !== 'script'
            || preg_match('~^/theme-bundles/[a-f0-9]{64}/'.preg_quote($asset, '~').'$~D', $node['attributes']['src'] ?? '') !== 1) {
            return false;
        }
        $integrity = $node['attributes']['integrity'] ?? '';
        foreach ($hashes as $hash) {
            if ($integrity === $this->integrity($hash)) {
                return true;
            }
        }

        return false;
    }

    private function importedOpening(string $opening, array $node): string
    {
        $values = [
            'src' => '/build/fanmulu/imported-theme/'.self::IMPORTED_RUNTIME_HASH.'/tram-imported-runtime.js',
            'integrity' => $this->integrity(self::IMPORTED_RUNTIME_HASH),
        ];
        $opening = preg_replace_callback(self::ATTRIBUTE, function (array $attribute) use (&$values): string {
            $name = strtolower($attribute['name']);
            if (! isset($values[$name])) {
                return $attribute[0];
            }
            $old = $attribute['value'];
            $quote = in_array($old[0], ['"', "'"], true) ? $old[0] : '';
            $value = $quote.$values[$name].$quote;
            unset($values[$name]);

            return substr_replace($attribute[0], $value, -strlen($old));
        }, $opening);
        if (! isset($node['attributes']['data-tram-no-watch-actions'])) {
            $opening = substr_replace($opening, ' data-tram-no-watch-actions', -1, 0);
        }

        return $opening;
    }

    private function integrity(string $hash): string
    {
        return 'sha256-'.base64_encode(hex2bin($hash));
    }

    /** @return array<int,array{start:int,opening_end:int,closing_start:int,end:int,tag:string,parent:?int,attributes:array,classes:array,protected:bool,closed:bool}> */
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
                for ($index = count($stack) - 1; $index >= 0; $index--) {
                    if ($nodes[$stack[$index]]['tag'] === $name) {
                        $id = $stack[$index];
                        $nodes[$id]['closing_start'] = $position;
                        $nodes[$id]['end'] = $offset;
                        $nodes[$id]['closed'] = true;
                        $stack = array_slice($stack, 0, $index);
                        break;
                    }
                }

                continue;
            }
            $attributes = [];
            if (preg_match_all(self::ATTRIBUTE, $source, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $attribute) {
                    $key = strtolower($attribute['name']);
                    if (! array_key_exists($key, $attributes)) {
                        $attributes[$key] = html_entity_decode(trim($attribute['value'] ?? '', '"\''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
            }
            $parent = $stack === [] ? null : end($stack);
            $protected = ($parent !== null && $nodes[$parent]['protected']) || in_array($name, ['code', 'pre', 'template'], true);
            $id = count($nodes);
            $void = in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true);
            $nodes[] = ['start' => $position, 'opening_end' => $offset, 'closing_start' => $offset, 'end' => $offset,
                'tag' => $name, 'parent' => $parent, 'attributes' => $attributes,
                'classes' => preg_split('/\s+/', trim($attributes['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
                'protected' => $protected, 'closed' => $void];
            if (in_array($name, ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes'], true)) {
                if (! preg_match('~</'.preg_quote($name, '~').'\s*>~i', $html, $close, PREG_OFFSET_CAPTURE, $offset)) {
                    break;
                }
                $nodes[$id]['closing_start'] = $close[0][1];
                $nodes[$id]['end'] = $offset = $close[0][1] + strlen($close[0][0]);
                $nodes[$id]['closed'] = true;

                continue;
            }
            if ($name === 'plaintext') {
                break;
            }
            if (! $void) {
                $stack[] = $id;
            }
        }

        return $nodes;
    }

    /** @return array<int,array{int,int}> */
    private function ranges(array $nodes): array
    {
        usort($nodes, fn ($a, $b) => $a['start'] <=> $b['start']);
        $ranges = [];
        foreach ($nodes as $node) {
            $last = count($ranges) - 1;
            if ($last >= 0 && $node['start'] <= $ranges[$last][1]) {
                $ranges[$last][1] = max($ranges[$last][1], $node['end']);
            } else {
                $ranges[] = [$node['start'], $node['end']];
            }
        }

        return $ranges;
    }

    private function valid(array $reference): bool
    {
        $script = $reference['script'] ?? null;
        $hash = $reference['hash'] ?? null;
        if (array_diff(array_keys($reference), ['script', 'hash', 'context_version'])
            || (array_key_exists('context_version', $reference) && $reference['context_version'] !== 3)
            || ! is_string($script) || ! is_string($hash)
            || preg_match('~^/build/fanmulu/tram-fanmulu-runtime-[a-f0-9]{8,64}\.js$~D', $script) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            return false;
        }
        $file = public_path(ltrim($script, '/'));

        return is_file($file) && ! is_link($file) && hash_equals($hash, hash_file('sha256', $file));
    }
}
