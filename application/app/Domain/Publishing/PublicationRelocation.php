<?php

namespace App\Domain\Publishing;

final class PublicationRelocation
{
    /**
     * Materialize a relocated historical publication without changing stored
     * bytes. Unmarked publications remain exactly unchanged.
     *
     * @return array{snapshot: array, html: string}
     */
    public static function materialize(array $snapshot, string $html): array
    {
        if (! array_key_exists('_relocation', $snapshot)) {
            return ['snapshot' => $snapshot, 'html' => $html];
        }
        $metadata = $snapshot['_relocation'];
        if (! is_array($metadata) || ($metadata['schema_version'] ?? null) !== 1
            || array_diff(array_keys($metadata), ['schema_version', 'from_base', 'to_base', 'category_path_map'])
            || ! is_string($metadata['from_base'] ?? null) || ! is_string($metadata['to_base'] ?? null)
            || ! is_array($metadata['category_path_map'] ?? null)) {
            throw new \InvalidArgumentException('PUBLICATION_RELOCATION_INVALID');
        }
        $from = self::origin($metadata['from_base']);
        $to = self::origin($metadata['to_base']);
        $paths = $metadata['category_path_map'];
        foreach ($paths as $old => $new) {
            if (! is_string($old) || ! is_string($new)
                || preg_match('~^/(?:[A-Za-z0-9_-]+/)+[1-9][0-9]*$~D', $old) !== 1
                || preg_match('~^/(?:[A-Za-z0-9_-]+/)+[1-9][0-9]*$~D', $new) !== 1) {
                throw new \InvalidArgumentException('PUBLICATION_RELOCATION_PATH_INVALID');
            }
        }
        if (count($paths) !== count(array_unique($paths))) {
            throw new \InvalidArgumentException('PUBLICATION_RELOCATION_PATH_COLLISION');
        }
        if (($snapshot['site']['base_url'] ?? null) !== $from
            || ! is_string($snapshot['canonical_url'] ?? null)
            || ! self::hasOrigin($snapshot['canonical_url'], $from)) {
            throw new \InvalidArgumentException('PUBLICATION_RELOCATION_SOURCE_MISMATCH');
        }
        $snapshot['site']['base_url'] = $to;
        $snapshot['canonical_url'] = self::base($snapshot['canonical_url'], $from, $to);
        if (in_array($snapshot['page']['type'] ?? null, ['category', 'list'], true)) {
            $snapshot['canonical_url'] = self::categoryPath($snapshot['canonical_url'], $paths, $to);
            if (is_string($snapshot['page']['path'] ?? null)) {
                $snapshot['page']['path'] = self::categoryPath($snapshot['page']['path'], $paths, $to);
            }
        }
        if (is_array($snapshot['category'] ?? null)) {
            $snapshot['category'] = self::category($snapshot['category'], $paths, $from, $to);
        }
        foreach ($snapshot['theme_catalog']['categories'] ?? [] as $index => $category) {
            if (is_array($category)) {
                $snapshot['theme_catalog']['categories'][$index] = self::category($category, $paths, $from, $to);
            }
        }
        foreach ($snapshot['aggregate']['entries'] ?? [] as $index => $entry) {
            if (is_array($entry['category'] ?? null)) {
                $snapshot['aggregate']['entries'][$index]['category'] = self::category($entry['category'], $paths, $from, $to);
            }
        }
        unset($snapshot['_relocation']);

        return ['snapshot' => $snapshot, 'html' => self::html($html, $paths, $from, $to)];
    }

    private static function origin(string $origin): string
    {
        $parts = parse_url($origin);
        if ($parts === false || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host']) || array_diff(array_keys($parts), ['scheme', 'host', 'port'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $origin)
            || filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('PUBLICATION_RELOCATION_ORIGIN_INVALID');
        }

        return $origin;
    }

    private static function hasOrigin(string $url, string $origin): bool
    {
        return $url === $origin || str_starts_with($url, $origin.'/')
            || str_starts_with($url, $origin.'?') || str_starts_with($url, $origin.'#');
    }

    private static function base(string $url, string $from, string $to): string
    {
        return self::hasOrigin($url, $from) ? $to.substr($url, strlen($from)) : $url;
    }

    private static function categoryPath(string $url, array $paths, string $origin): string
    {
        $prefix = str_starts_with($url, $origin.'/') ? $origin : '';
        $path = $prefix === '' ? $url : substr($url, strlen($origin));
        $end = strcspn($path, '?#');
        $plain = substr($path, 0, $end);

        return isset($paths[$plain]) ? $prefix.$paths[$plain].substr($path, $end) : $url;
    }

    private static function category(array $category, array $paths, string $from, string $to): array
    {
        if (is_string($category['path'] ?? null)) {
            $category['path'] = self::categoryPath(self::base($category['path'], $from, $to), $paths, $to);
        }

        return $category;
    }

    /** Patch URL attribute values only; preserve raw text, comments and formatting. */
    private static function html(string $html, array $paths, string $from, string $to): string
    {
        $tagPattern = '~<!--[\s\S]*?-->|<(?:(?:"[^"]*")|(?:\'[^\']*\')|[^\'">])*>~';
        $attributePattern = '~(?<prefix>[\x20\t\r\n\f]+)(?<name>[^\x20\t\r\n\f"\'<>/=]+)(?<equals>[\x20\t\r\n\f]*=[\x20\t\r\n\f]*)(?<value>"[^"]*"|\'[^\']*\'|[^\x20\t\r\n\f"\'=<>`]+)~i';
        $offset = 0;
        $output = '';
        while (preg_match($tagPattern, $html, $token, PREG_OFFSET_CAPTURE, $offset)) {
            [$tag, $position] = $token[0];
            $output .= substr($html, $offset, $position - $offset);
            $opening = preg_match('~^<\s*([a-z][a-z0-9:-]*)\b~i', $tag, $name) === 1;
            $output .= $opening ? preg_replace_callback($attributePattern, static function (array $attribute) use ($paths, $from, $to): string {
                if (! in_array(strtolower($attribute['name']), ['href', 'src', 'action', 'formaction', 'poster', 'data-original', 'data-page-url', 'data-play-url', 'data-source-url', 'data-poster-url'], true)) {
                    return $attribute[0];
                }
                $raw = $attribute['value'];
                $quote = in_array($raw[0], ['"', "'"], true) ? $raw[0] : '';
                $url = $quote === '' ? $raw : substr($raw, 1, -1);
                $projected = self::base($url, $from, $to);
                if (strtolower($attribute['name']) === 'href') {
                    $projected = self::categoryPath($projected, $paths, $to);
                }

                return $attribute['prefix'].$attribute['name'].$attribute['equals'].$quote.$projected.$quote;
            }, $tag) : $tag;
            $offset = $position + strlen($tag);
            if ($opening && in_array(strtolower($name[1]), ['script', 'style', 'textarea', 'title'], true)) {
                if (! preg_match('~</'.preg_quote($name[1], '~').'\s*>~i', $html, $close, PREG_OFFSET_CAPTURE, $offset)) {
                    return $output.substr($html, $offset);
                }
                $end = $close[0][1] + strlen($close[0][0]);
                $output .= substr($html, $offset, $end - $offset);
                $offset = $end;
            }
        }

        return $output.substr($html, $offset);
    }
}
