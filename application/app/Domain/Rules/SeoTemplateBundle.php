<?php

namespace App\Domain\Rules;

use App\Domain\Fanmulu\FanmuluPresentation;
use App\Domain\Keywords\AssignmentPurpose;
use App\Domain\Support\Json;

final class SeoTemplateBundle
{
    public static function labels(): array
    {
        return ['home' => 'Trang chủ', 'list' => 'Danh sách / danh mục', 'detail' => 'Chi tiết phim / tác phẩm', 'play' => 'Phát phim', 'search' => 'Tìm kiếm'];
    }

    public static function isBundle(array $definition): bool
    {
        return array_key_exists('page_templates', $definition);
    }

    public static function defaults(): array
    {
        $form = static fn (array $slots, string $view): array => ['slots' => $slots + ['meta_keywords' => self::defaultMetaKeywords($view)], 'managed_body_zone' => 'main-content'];

        return ['page_templates' => [
            'home' => $form(['title' => '{{site.name}} - 电影与剧集资料库', 'description' => '{{site.name}}收录电影、剧集、演员及创作者资料，提供分类索引与内容介绍。', 'h1' => '{{site.name}}', 'intro' => '发现影片，了解故事与创作者。'], 'home'),
            'list' => $form(['title' => '{{category.name}} - {{site.name}}', 'description' => '{{category.name}}分类索引与影片资料。', 'h1' => '{{category.name}}', 'intro' => '浏览已发布的影片介绍与相关资料。'], 'list'),
            'detail' => $form(['title' => '{{content.title}} - {{kw.primary}} - {{site.name}}', 'description' => '{{content.title}}资料、简介与相关内容。', 'h1' => '{{content.title}}', 'intro' => '收录{{kw.primary}}相关信息。'], 'detail'),
            'play' => $form(['title' => '{{content.title}} - {{episode.title}} - {{site.name}}', 'description' => '{{content.title}} · {{episode.title}}。', 'h1' => '{{content.title}} · {{episode.title}}'], 'play'),
            'search' => $form(['title' => '{{search.query}} - 搜索影片 - {{site.name}}', 'description' => '在{{site.name}}查找已发布的电影与剧集资料。', 'h1' => '搜索：{{search.query}}', 'intro' => '搜索结果来自已发布的影片资料。'], 'search'),
        ]];
    }

    public static function defaultMetaKeywords(string $view): array
    {
        $expression = match ($view) {
            'home' => '{{site.name}},电影,电视剧,动漫,综艺',
            'list' => '{{category.name}},{{site.name}}',
            'detail' => '{{content.title}},{{content.genre}},{{kw.primary}},{{kw.secondary}}',
            'play' => '{{content.title}},{{episode.title}},{{site.name}}',
            'search' => '{{search.query}},{{site.name}}',
            default => throw new \InvalidArgumentException('Kiểu trang Meta keywords không hợp lệ.'),
        };

        return ['enabled' => true, 'template' => $expression,
            'fallbacks' => $view === 'detail' ? ['content.genre' => '', 'kw.primary' => '', 'kw.secondary' => ''] : ($view === 'play' ? ['episode.title' => ''] : [])];
    }

    public static function view(string $pageType): string
    {
        return match ($pageType) {
            'home' => 'home', 'category', 'list' => 'list', 'play' => 'play', 'search' => 'search',
            default => 'detail',
        };
    }

    public static function page(array $definition, string $view): array
    {
        return self::isBundle($definition) ? ($definition['page_templates'][self::view($view)] ?? []) : $definition;
    }

    public static function validate(array $definition): void
    {
        if (! self::isBundle($definition)) {
            self::validatePage($definition);

            return;
        }
        $pages = $definition['page_templates'];
        if (! is_array($pages) || array_diff(array_keys(self::labels()), array_keys($pages)) || array_diff(array_keys($pages), array_keys(self::labels()))) {
            throw new \InvalidArgumentException('Bộ Template SEO cần đủ Trang chủ, Danh sách, Chi tiết, Phát phim và Tìm kiếm.');
        }
        foreach ($pages as $view => $page) {
            if (! is_array($page)) {
                throw new \InvalidArgumentException('Cấu hình trang trong bộ template không hợp lệ.');
            }
            self::validatePage($page, $view);
        }
    }

    public static function validatePage(array $definition, ?string $view = null): void
    {
        if (! isset($definition['slots']) || ! is_array($definition['slots']) || ($view === null && $definition['slots'] === [])) {
            throw new \InvalidArgumentException('Template cần cấu hình các vị trí SEO.');
        }
        if (isset($definition['managed_body_zone']) && (! is_string($definition['managed_body_zone']) || $definition['managed_body_zone'] === '' || mb_strlen($definition['managed_body_zone']) > 200)) {
            throw new \InvalidArgumentException('Vùng nội dung được quản lý không hợp lệ.');
        }
        foreach ($definition['slots'] as $slot => $entry) {
            if (! in_array($slot, ['title', 'description', 'h1', 'intro', 'meta_keywords', 'related_links', 'managed_body'], true) && ! preg_match('/^h2\.[a-z0-9_-]+$/', $slot)) {
                throw new \InvalidArgumentException('Vị trí SEO không hợp lệ: '.$slot);
            }
            if ($entry !== null && ! is_bool($entry) && ! is_string($entry) && ! is_array($entry)) {
                throw new \InvalidArgumentException('Nội dung vị trí SEO không hợp lệ.');
            }
            $expression = is_string($entry) ? $entry : (is_array($entry) ? ($entry['template'] ?? null) : null);
            if ($expression !== null) {
                if (! is_string($expression)) {
                    throw new \InvalidArgumentException('Công thức SEO phải là văn bản.');
                }
                app(TemplateCompiler::class)->validate($expression);
                preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u', $expression, $matches);
                foreach ($matches[1] as $token) {
                    $token = trim($token);
                    $fallbacks = is_array($entry) && is_array($entry['fallbacks'] ?? null) ? $entry['fallbacks'] : [];
                    $unavailable = ($view !== null && $view !== 'search' && $token === 'search.query')
                        || ($view !== null && $view !== 'play' && str_starts_with($token, 'episode.'))
                        || (in_array($view, ['home', 'list', 'search'], true) && (str_starts_with($token, 'content.') || $token === 'kw.primary'));
                    if ($unavailable && ! array_key_exists($token, $fallbacks)) {
                        throw new \InvalidArgumentException('Biến '.$token.' không có trên trang '.$view.'. Hãy dùng biến của trang này hoặc điền giá trị dự phòng.');
                    }
                }
            }
        }
    }

    public static function dynamic(array $snapshot, string $view, array $context = []): array
    {
        $definition = $snapshot['seo_template_bundle']['definition'] ?? null;
        $outputs = $snapshot['slots'] ?? [];
        if (! is_array($definition) || ! self::isBundle($definition)) {
            return $outputs;
        }
        $profile = self::page($definition, $view);
        $content = FanmuluPresentation::content($snapshot);
        $assignments = AssignmentPurpose::seo($snapshot['assignments'] ?? []);
        $primary = collect($assignments)->firstWhere('role', 'primary');
        $values = [
            'site.name' => $snapshot['site']['name'] ?? '',
            'category.name' => $context['category']['name'] ?? $snapshot['category']['name'] ?? '影片目录',
            'content.title' => $content['title'] ?? null, 'content.genre' => $content['genre'] ?? null,
            'content.author' => $content['author'] ?? null,
            'kw.primary' => $primary['text'] ?? null,
            'kw.secondary' => collect($assignments)->where('role', 'secondary')->pluck('text')->implode('、'),
            'search.query' => $view === 'search' ? ($context['q'] ?? '') : null,
            'episode.title' => $view === 'play' ? ($context['episode']['title'] ?? '') : null,
            'episode.index' => $view === 'play' ? ($context['episode']['index'] ?? $context['index'] ?? '') : null,
        ] + self::movieMetadataValues($content) + (empty($snapshot['custom_variables']) ? [] : app(TemplateVariableService::class)->values($snapshot['custom_variables']));
        $name = $values['site.name'];
        $outputs = match ($view) {
            'play' => ['title' => ($values['content.title'] ?? '').' - '.$values['episode.title'], 'description' => ($values['content.title'] ?? '').' · '.$values['episode.title'], 'h1' => ($values['content.title'] ?? '').' · '.$values['episode.title']],
            'search' => ['title' => ($values['search.query'] !== '' ? $values['search.query'].' - ' : '').$name, 'description' => '电影、剧集与创作者资料索引。', 'h1' => $values['search.query'] !== '' ? '搜索：'.$values['search.query'] : $name],
            'list' => ['title' => $values['category.name'].' - '.$name, 'description' => '电影、剧集与创作者资料索引。', 'h1' => $values['category.name']],
            default => ['title' => $name, 'description' => '电影、剧集与创作者资料索引。', 'h1' => $name],
        };
        foreach ($profile['slots'] ?? [] as $slot => $config) {
            if ($config === null || in_array($slot, ['managed_body', 'related_links'], true)) {
                continue;
            }
            if ($config === false || (is_array($config) && ($config['enabled'] ?? true) === false)) {
                unset($outputs[$slot]);

                continue;
            }
            $expression = is_string($config) ? $config : (is_array($config) ? ($config['template'] ?? '') : '');
            $fallbacks = is_array($config) ? ($config['fallbacks'] ?? []) : [];
            if (! empty($snapshot['_live_content_changed'])) {
                foreach (['content.genre', 'content.author', 'content.year', 'content.area', 'content.director', 'content.actors', 'content.language'] as $token) {
                    if (! array_key_exists($token, $fallbacks)) {
                        $fallbacks[$token] = '';
                    }
                }
            }
            $output = app(TemplateCompiler::class)->compile($expression, $values, $fallbacks, $slot === 'intro');
            $outputs[$slot] = $slot === 'intro' ? Html::sanitize($output) : $output;
        }

        return FanmuluPresentation::slots($snapshot, $outputs);
    }

    /** @return array<string, ?string> */
    public static function movieMetadataValues(array $content): array
    {
        return [
            'content.year' => self::metadataText($content['year'] ?? null),
            'content.area' => self::metadataText($content['area'] ?? null),
            'content.director' => self::metadataText($content['directors'] ?? null) ?? self::metadataText($content['director'] ?? null),
            'content.actors' => self::metadataText($content['actors'] ?? null),
            'content.language' => self::metadataText($content['language'] ?? null),
        ];
    }

    private static function metadataText(mixed $value): ?string
    {
        $values = is_array($value) ? $value : [$value];
        $parts = [];
        foreach ($values as $part) {
            if (! is_string($part) && ! is_int($part) && ! is_float($part)) {
                continue;
            }
            $text = trim((string) $part);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : implode(' / ', $parts);
    }

    public static function reference(object $template, array $definition): array
    {
        return ['template_id' => $template->id, 'template_version_id' => $template->current_version_id, 'schema_version' => 2, 'hash' => Json::hash($definition), 'definition' => $definition];
    }
}
