<?php

namespace App\Domain\Themes;

use App\Domain\Rules\Html;

/** Small data-only HTML template language: no PHP, Blade, JavaScript or evaluation. */
final class MovieThemeCompiler
{
    public const TOKENS = [
        'site.name', 'site.locale', 'site.base_url', 'page.path', 'page.type', 'page.canonical_url',
        'seo.title', 'seo.description', 'seo.h1', 'seo.intro',
        'content.title', 'content.genre', 'content.year', 'content.area', 'content.language', 'content.remarks',
        'content.director', 'content.actors', 'content.author', 'content.poster_url', 'content.body_html', 'content.score', 'content.body_excerpt', 'content.updated_at',
        'content.genre_url', 'content.area_url', 'content.year_url',
        'kw.primary', 'kw.secondary', 'search.query', 'search.total', 'search.next_url', 'search.previous_url',
        'movie.title', 'movie.path', 'movie.poster_url', 'movie.year', 'movie.genre', 'movie.actors', 'movie.remarks', 'movie.director', 'movie.area', 'movie.play_url',
        'movie.rank', 'movie.rank_class', 'movie.score', 'movie.body_excerpt', 'movie.language',
        'episode.title', 'episode.sequence_no', 'episode.play_url', 'link.text', 'link.url', 'friend_link.text', 'friend_link.url', 'comment.text', 'comment.author',
        'url.home', 'url.list', 'url.detail', 'url.play', 'url.search',
        'category.id', 'category.name', 'category.path',
        'player.title', 'player.mode', 'player.message', 'player.can_play', 'player.source_url',
        'player.play_url', 'player.previous_url', 'player.next_url', 'player.detail_url',
        'genre.name', 'genre.url',
        'nav_item.name', 'nav_item.path', 'nav_item.current', 'section.name', 'section.path', 'section.icon_url', 'section.is_first', 'section.rank_label',
        'sub_category.name', 'sub_category.path',
        'page_link.url', 'page_link.label', 'page_link.current',
        'filter_group.name', 'filter_group.is_sort', 'filter_option.name', 'filter_option.url', 'filter_option.current',
    ];

    public const COLLECTIONS = ['movies' => 'movie', 'episodes' => 'episode', 'related_links' => 'link', 'friend_links' => 'friend_link', 'genres' => 'genre',
        'navigation' => 'nav_item', 'sections' => 'section', 'category_sections' => 'section', 'section.movies' => 'movie', 'section.children' => 'sub_category', 'featured_movies' => 'movie',
        'section.extra_movies' => 'movie', 'section.weekly_movies' => 'movie', 'section.sidebar_movies' => 'movie', 'section.sidebar_extra_movies' => 'movie', 'rank_sections' => 'section',
        'recommended_movies' => 'movie', 'actor_recommended_movies' => 'movie', 'comments' => 'comment', 'extra_movies' => 'movie', 'weekly_movies' => 'movie', 'monthly_movies' => 'movie', 'pagination' => 'page_link', 'filter_groups' => 'filter_group', 'filter_group.options' => 'filter_option',
        'section.featured_movies' => 'movie', 'section.rank_movies' => 'movie',
        'compact_featured_movies' => 'movie', 'compact_weekly_movies' => 'movie', 'compact_extra_movies' => 'movie'];

    private const RAW = ['content.body_html', 'seo.intro'];

    private const URLS = ['site.base_url', 'page.path', 'page.canonical_url', 'content.poster_url', 'content.genre_url', 'content.area_url', 'content.year_url', 'movie.path', 'movie.poster_url', 'movie.play_url', 'link.url', 'friend_link.url', 'search.next_url', 'search.previous_url', 'url.home', 'url.list', 'url.detail', 'url.play', 'url.search', 'category.path', 'episode.play_url', 'player.source_url', 'player.play_url', 'player.previous_url', 'player.next_url', 'player.detail_url', 'genre.url', 'nav_item.path', 'section.path', 'section.icon_url', 'sub_category.path', 'page_link.url', 'filter_option.url'];

    private const TAGS = ['header', 'footer', 'main', 'nav', 'section', 'aside', 'article', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'img', 'ul', 'ol', 'li', 'form', 'input', 'button', 'label', 'select', 'option', 'figure', 'figcaption', 'dl', 'dt', 'dd', 'hr', 'br', 'small', 'strong', 'b', 'em', 'i', 'u', 'blockquote', 'time', 'details', 'summary', 'code', 'pre'];

    public function validate(array $views, string $css, array $assets = [], array $runtime = []): void
    {
        $runtime = ReviewedThemeRuntime::validate($runtime, $assets);
        MovieThemePackage::schema($views);
        foreach ($views as $view => $html) {
            if (! is_string($html) || ! mb_check_encoding($html, 'UTF-8') || strlen($html) > 200000 || $html === '') {
                throw new \InvalidArgumentException('HTML '.$view.' trống, sai UTF-8 hoặc vượt 200 KB.');
            }
            $this->program($html, $assets);
            $this->validateHtml($html, $assets, $runtime !== []);
        }
        $this->renderCss($css, $assets);
    }

    public function renderCss(string $css, array $assets = []): string
    {
        if (! mb_check_encoding($css, 'UTF-8') || strlen($css) > 300000 || preg_match('/[\\\\<\x00]|@import|expression\s*\(|javascript\s*:|(?<![-\w])behavior\s*:|-moz-binding/i', $css)) {
            throw new \InvalidArgumentException('CSS sai định dạng hoặc chứa import/mã/escape không hỗ trợ.');
        }
        $css = preg_replace_callback('/\{\{\s*asset\.([a-zA-Z0-9_.\/-]+)\s*\}\}/', function ($m) use ($assets) {
            return $assets[$m[1]]['path'] ?? throw new \InvalidArgumentException('Asset không tồn tại: '.$m[1]);
        }, $css);
        if (str_contains($css, '{{')) {
            throw new \InvalidArgumentException('CSS chỉ hỗ trợ biến asset.');
        }
        $plain = preg_replace('~/\*.*?\*/~s', '', $css);
        // Comments cannot be used to obfuscate a prohibited directive or URL.
        if (preg_match('/@import|expression\s*\(|javascript\s*:|(?<![-\w])behavior\s*:|-moz-binding/i', $plain)) {
            throw new \InvalidArgumentException('CSS chứa mã không được phép.');
        }
        preg_match_all('/url\s*\((.*?)\)/is', $plain, $matches);
        foreach ($matches[1] as $url) {
            $url = trim(trim($url), '\'"');
            if (! preg_match('~^/(?:theme-assets/[a-f0-9]{64}|theme-bundles/[a-f0-9]{64}/[a-zA-Z0-9_./-]+)$~D', $url) || ! in_array($url, array_column($assets, 'path'), true)) {
                throw new \InvalidArgumentException('CSS url() phải tham chiếu asset trong bộ giao diện.');
            }
        }

        return $css;
    }

    public function render(string $fragment, array $context, array $assets = [], array $runtime = []): string
    {
        $runtime = ReviewedThemeRuntime::validate($runtime, $assets);
        $nodes = $this->program($fragment, $assets);
        $this->validateHtml($fragment, $assets, $runtime !== []);
        $steps = 0;
        $html = $this->evaluate($nodes, $context, $assets, $steps);
        if (strlen($html) > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('HTML giao diện sau render vượt 8 MB.');
        }

        return $html;
    }

    private function token(string $token, array $aliases, array $assets, bool $condition = false): void
    {
        if ($condition && isset(self::COLLECTIONS[$token])) {
            if (str_contains($token, '.') && ! in_array(explode('.', $token)[0], $aliases, true)) {
                throw new \InvalidArgumentException('Danh sách '.$token.' chỉ dùng bên trong vòng lặp cha.');
            }

            return;
        }
        if (str_starts_with($token, 'asset.') && isset($assets[substr($token, 6)])) {
            return;
        }
        if (! in_array($token, self::TOKENS, true)) {
            throw new \InvalidArgumentException('Biến giao diện không hỗ trợ: '.$token);
        }
        foreach (self::COLLECTIONS as $alias) {
            if (str_starts_with($token, $alias.'.') && ! in_array($alias, $aliases, true)) {
                throw new \InvalidArgumentException('Biến '.$token.' chỉ dùng bên trong vòng lặp '.$alias.'.');
            }
        }
    }

    private function program(string $html, array $assets): array
    {
        if (preg_match('/<\?|@(?:php|include|extends|foreach|if|eval)\b/i', $html)) {
            throw new \InvalidArgumentException('Giao diện không thực thi PHP hoặc Blade.');
        }
        $parts = preg_split('/(\{\{.*?\}\})/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $i = 0;
        [$nodes, $end] = $this->parse($parts, $i, [], $assets, 0);
        if ($end !== null) {
            throw new \InvalidArgumentException('Block giao diện đóng sai vị trí.');
        }

        return $nodes;
    }

    private function parse(array $parts, int &$i, array $aliases, array $assets, int $depth): array
    {
        if ($depth > 8) {
            throw new \InvalidArgumentException('Vòng lặp/điều kiện lồng quá 8 cấp.');
        }
        $nodes = [];
        while ($i < count($parts)) {
            $part = $parts[$i++];
            if (! str_starts_with($part, '{{')) {
                if (str_contains($part, '{{') || str_contains($part, '}}')) {
                    throw new \InvalidArgumentException('Ngoặc biến giao diện chưa đóng.');
                }
                $nodes[] = ['text', $part];

                continue;
            }
            $expr = trim(substr($part, 2, -2));
            if (in_array($expr, ['else', '/if', '/each'], true)) {
                return [$nodes, $expr];
            }
            if (preg_match('/^#(if|each)\s+([a-zA-Z0-9_.\/-]+)$/', $expr, $m)) {
                $kind = $m[1];
                $name = $m[2];
                if ($kind === 'each' && (! isset(self::COLLECTIONS[$name]) || in_array(self::COLLECTIONS[$name], $aliases, true))) {
                    throw new \InvalidArgumentException('Vòng lặp không hỗ trợ: '.$name);
                }
                $this->token($name, $aliases, $assets, true);
                $innerAliases = $kind === 'each' ? [...$aliases, self::COLLECTIONS[$name]] : $aliases;
                [$yes, $end] = $this->parse($parts, $i, $innerAliases, $assets, $depth + 1);
                $no = [];
                if ($end === 'else') {
                    [$no, $end] = $this->parse($parts, $i, $aliases, $assets, $depth + 1);
                }
                if ($end !== '/'.$kind) {
                    throw new \InvalidArgumentException('Thiếu {{/'.$kind.'}} hoặc block đóng sai.');
                }
                $nodes[] = [$kind, $name, $yes, $no];
            } else {
                $this->token($expr, $aliases, $assets);
                $nodes[] = ['value', $expr];
            }
        }

        return [$nodes, null];
    }

    private function value(string $token, array $context, array $assets): mixed
    {
        if (str_starts_with($token, 'asset.')) {
            return $assets[substr($token, 6)]['path'] ?? '';
        }
        $parts = explode('.', $token);
        $value = $context;
        foreach ($parts as $part) {
            $value = is_array($value) ? ($value[$part] ?? null) : null;
        }

        return $value;
    }

    private function evaluate(array $nodes, array $context, array $assets, int &$steps): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (++$steps > 500000) {
                throw new \InvalidArgumentException('Giao diện vượt ngân sách render.');
            }
            [$kind, $name] = $node;
            if ($kind === 'text') {
                $out .= $name;
            } elseif ($kind === 'value') {
                $value = $this->value($name, $context, $assets);
                if ($value !== null && ! is_scalar($value)) {
                    throw new \InvalidArgumentException('Biến sai kiểu: '.$name);
                }
                $value = (string) ($value ?? '');
                if ((in_array($name, self::URLS, true) || str_starts_with($name, 'asset.')) && $value !== '' && ! Html::safeUrl($value, true)) {
                    $value = '';
                }
                $out .= in_array($name, self::RAW, true) ? Html::sanitize($value) : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($kind === 'if') {
                $out .= $this->evaluate($this->value($name, $context, $assets) ? $node[2] : $node[3], $context, $assets, $steps);
            } else {
                $rows = $this->value($name, $context, $assets) ?? [];
                if (! is_array($rows) || count($rows) > 10000) {
                    throw new \InvalidArgumentException('Danh sách giao diện sai kiểu hoặc vượt 10.000.');
                }
                if (! $rows) {
                    $out .= $this->evaluate($node[3], $context, $assets, $steps);
                }
                foreach ($rows as $row) {
                    $out .= $this->evaluate($node[2], $context + [self::COLLECTIONS[$name] => $row], $assets, $steps);
                }
            }
            if (strlen($out) > 8 * 1024 * 1024) {
                throw new \InvalidArgumentException('HTML giao diện vượt 8 MB.');
            }
        }

        return $out;
    }

    private function validateHtml(string $html, array $assets, bool $reviewed = false): void
    {
        if (preg_match('/<\s*(html|head|body|script|style|iframe|object|embed|svg|math|link|meta|base)\b|<!DOCTYPE/i', $html)) {
            throw new \InvalidArgumentException('HTML chỉ chứa phần body; head SEO/CSS được hệ thống quản lý.');
        }
        $html = preg_replace('/\{\{\s*(?:#(?:if|each)\s+[^}]+|else|\/(?:if|each))\s*\}\}/', '', $html);
        $tokens = [];
        $html = preg_replace_callback('/\{\{\s*([^{}]+)\s*\}\}/', function ($m) use (&$tokens) {
            $marker = 'TRAMTOKEN'.count($tokens).'END';
            $tokens[$marker] = trim($m[1]);

            return $marker;
        }, $html);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="theme-validation-root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        if ($dom->getElementsByTagName('h1')->length !== 1) {
            throw new \InvalidArgumentException('Mỗi trang giao diện cần đúng một H1.');
        }
        foreach ($dom->getElementsByTagName('*') as $node) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['html', 'head', 'body'], true)) {
                continue;
            }
            $reviewedTags = ['sup', 'acronym', 'font', 'dfn', 'big', 'abbr', 'map', 'var', 'sub', 'del', 'area', 'ins', 'bdo', 'tt', 'center', 'video', 'source'];
            if (! in_array($tag, self::TAGS, true) && ! ($reviewed && in_array($tag, $reviewedTags, true))) {
                throw new \InvalidArgumentException('Thẻ HTML không hỗ trợ: '.$tag);
            }
            foreach ($node->attributes as $attr) {
                $name = strtolower($attr->name);
                $value = $attr->value;
                $allowed = in_array($name, ['class', 'id', 'title', 'role', 'lang', 'dir', 'tabindex', 'hidden', 'href', 'src', 'alt', 'width', 'height', 'loading', 'action', 'method', 'type', 'name', 'value', 'placeholder', 'required', 'autocomplete', 'for', 'rel', 'target', 'datetime', 'selected', 'disabled', 'aria-label', 'maxlength', 'minlength'], true) || preg_match('/^(aria|data)-[a-z0-9-]+$/', $name);
                $allowed = $allowed || ($reviewed && in_array($name, ['style', 'controls', 'playsinline', 'preload', 'poster', 'muted', 'loop'], true));
                if (! $allowed || str_starts_with($name, 'on')) {
                    throw new \InvalidArgumentException('Thuộc tính HTML không hỗ trợ: '.$name);
                }
                if ($name === 'style' && (preg_match('/[\\\\<>\x00]|url\s*\(|expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding|TRAMTOKEN/i', $value))) {
                    throw new \InvalidArgumentException('Style trong HTML chỉ nhận khai báo bố cục tĩnh, không URL hoặc biến động.');
                }
                foreach ($tokens as $marker => $token) {
                    if (str_contains($value, $marker) && in_array($token, self::RAW, true)) {
                        throw new \InvalidArgumentException('Biến HTML chỉ dùng trong nội dung: '.$token);
                    }
                }
                if (in_array($name, ['href', 'src', 'action', 'poster', 'data-original', 'data-player-url'], true)) {
                    if (isset($tokens[$value])) {
                        $token = $tokens[$value];
                        if (! in_array($token, self::URLS, true) && ! str_starts_with($token, 'asset.')) {
                            throw new \InvalidArgumentException('Thuộc tính URL cần biến URL: '.$token);
                        }
                    } elseif (preg_match('/TRAMTOKEN\d+END/', $value) || (! Html::safeUrl($value, true) && ! preg_match('/^#[a-zA-Z0-9_-]+$/', $value))) {
                        throw new \InvalidArgumentException('URL giao diện không hợp lệ.');
                    }
                }
            }
            if ($tag === 'form' && (($node->getAttribute('action') !== '/search' && ($tokens[$node->getAttribute('action')] ?? null) !== 'url.search') || ! in_array(strtolower($node->getAttribute('method')), ['', 'get'], true))) {
                throw new \InvalidArgumentException('Form giao diện chỉ hỗ trợ GET tới {{url.search}} hoặc /search legacy.');
            }
            if ($tag === 'img' && ! $node->hasAttribute('alt')) {
                throw new \InvalidArgumentException('Ảnh cần thuộc tính alt.');
            }
        }
    }
}
