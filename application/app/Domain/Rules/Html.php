<?php

namespace App\Domain\Rules;

final class Html
{
    public static function safeUrl(string $url, bool $internal = false): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return false;
        }
        if ($internal && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }
        $p = parse_url($url);

        return $p && in_array(strtolower($p['scheme'] ?? ''), ['http', 'https']) && isset($p['host']) && ! isset($p['user']) && ! isset($p['pass']);
    }

    private static function dom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="tram-root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    public static function sanitize(string $html): string
    {
        if (strlen($html) > 2000000) {
            throw new \InvalidArgumentException('HTML vượt giới hạn');
        }
        $dom = self::dom($html);
        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//script|//style|//iframe|//object|//embed|//form|//input|//button|//textarea|//svg|//math|//link|//meta|//base')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        $allowed = ['div', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'h2', 'h3', 'h4', 'a', 'img', 'span', 'pre', 'code', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'hr'];
        foreach (iterator_to_array($dom->getElementsByTagName('*')) as $node) {
            if (in_array($node->tagName, ['html', 'body', 'head'])) {
                continue;
            }
            if ($node === $dom->getElementById('tram-root')) {
                continue;
            }
            if (! in_array(strtolower($node->tagName), $allowed)) {
                while ($node->firstChild) {
                    $node->parentNode?->insertBefore($node->firstChild, $node);
                } $node->parentNode?->removeChild($node);

                continue;
            }
            foreach (iterator_to_array($node->attributes) as $attr) {
                $name = strtolower($attr->name);
                $value = $attr->value;
                if (! in_array($name, ['href', 'src', 'alt', 'title', 'data-seo-zone', 'data-seo-optout', 'hidden']) || (in_array($name, ['href', 'src']) && ! self::safeUrl($value, true))) {
                    $node->removeAttribute($attr->name);
                }
            }
            if ($node->tagName === 'a') {
                $node->setAttribute('rel', 'nofollow noopener');
            }
        }

        return self::inner($dom);
    }

    private static function inner(\DOMDocument $dom): string
    {
        $root = $dom->getElementById('tram-root');
        if (! $root) {
            return '';
        }
        $html = '';
        foreach ($root->childNodes as $node) {
            $html .= $dom->saveHTML($node);
        }

        return $html;
    }

    public static function anchors(string $sanitized, array $assignments, string $canonical, int $max = 3, int $perKeyword = 1): string
    {
        $dom = self::dom($sanitized);
        $xpath = new \DOMXPath($dom);
        $total = 0;
        $used = [];
        $nodes = iterator_to_array($xpath->query('//*[@data-seo-zone]//text()[not(ancestor::a or ancestor::script or ancestor::style or ancestor::code or ancestor::pre or ancestor::h1 or ancestor::h2 or ancestor::h3 or ancestor::h4 or ancestor::h5 or ancestor::h6 or ancestor::*[@hidden] or ancestor::*[@data-seo-optout])]'));
        usort($assignments, fn ($a, $b) => mb_strlen($b['text']) <=> mb_strlen($a['text']) ?: (($b['priority'] ?? 0) <=> ($a['priority'] ?? 0)) ?: ($a['keyword_id'] <=> $b['keyword_id']));
        foreach ($nodes as $node) {
            if ($total >= $max) {
                break;
            } $text = $node->nodeValue;
            $matches = [];
            foreach ($assignments as $a) {
                $target = $a['target_url'] ?? '';
                if (! self::safeUrl($target, true) || $target === $canonical || (str_starts_with($target, '/') && $target === (parse_url($canonical, PHP_URL_PATH) ?: '/'))) {
                    continue;
                }
                $offset = 0;
                while (($pos = mb_strpos($text, $a['text'], $offset)) !== false) {
                    $end = $pos + mb_strlen($a['text']);
                    $offset = $end;
                    $overlap = false;
                    foreach ($matches as $m) {
                        if ($pos < $m['end'] && $end > $m['start']) {
                            $overlap = true;
                        }
                    }
                    if ($overlap || ($used[$a['keyword_id']] ?? 0) >= $perKeyword || $total >= $max) {
                        continue;
                    }
                    $matches[] = ['start' => $pos, 'end' => $end, 'assignment' => $a];
                    $used[$a['keyword_id']] = ($used[$a['keyword_id']] ?? 0) + 1;
                    $total++;
                }
            }
            if (! $matches) {
                continue;
            }
            usort($matches, fn ($a, $b) => $a['start'] <=> $b['start']);
            $fragment = $dom->createDocumentFragment();
            $cursor = 0;
            foreach ($matches as $m) {
                $fragment->appendChild($dom->createTextNode(mb_substr($text, $cursor, $m['start'] - $cursor)));
                $link = $dom->createElement('a');
                $link->setAttribute('href', $m['assignment']['target_url']);
                $link->appendChild($dom->createTextNode(mb_substr($text, $m['start'], $m['end'] - $m['start'])));
                $fragment->appendChild($link);
                $cursor = $m['end'];
            }
            $fragment->appendChild($dom->createTextNode(mb_substr($text, $cursor)));
            $node->parentNode->replaceChild($fragment, $node);
        }

        return self::inner($dom);
    }
}
