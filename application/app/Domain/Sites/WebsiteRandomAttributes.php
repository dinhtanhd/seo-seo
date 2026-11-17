<?php

namespace App\Domain\Sites;

use App\Domain\Fanmulu\FanmuluSettings;
use Illuminate\Validation\ValidationException;

final class WebsiteRandomAttributes
{
    public const FIELDS = ['name', 'list_prefix', 'detail_prefix', 'play_prefix', 'search_prefix', 'movie_key', 'category_key', 'suffix', 'fanmulu_prefix', 'fanmulu_suffix', 'fanmulu_url_template'];

    private const ROUTES = ['list_prefix' => 'list', 'detail_prefix' => 'detail', 'play_prefix' => 'play', 'search_prefix' => 'search'];

    /** Suggestions are candidates, never changes to an existing website. */
    public static function defaults(): array
    {
        return [
            'name' => ['星河影院', '云帆影视', '青柠影院', '流光影视', '银河剧场', '追光影院', '山海影视', '映月影院', '星辰剧场', '风铃影院', '云海影视', '拾光影院'],
            'list_prefix' => ['/type', '/category', '/list', '/sort', '/class'],
            'detail_prefix' => ['/movie', '/video', '/vod', '/film', '/detail'],
            'play_prefix' => ['/play', '/watch', '/view', '/player'],
            'search_prefix' => ['/search', '/find', '/so'],
            'movie_key' => ['source_id', 'pinyin_compact_id'],
            'category_key' => ['id', 'pinyin_compact_id'],
            'suffix' => ['', '.html', '.asp'],
            'fanmulu_prefix' => ['/f', '/videos', '/v', '/kan'],
            'fanmulu_suffix' => ['', '.html', '.asp'],
            'fanmulu_url_template' => ['{8chucai}{4chuso}', '{12chuso}', '{ngaythangnam}/{6chucai}{4chuso}'],
        ];
    }

    /** Convert enabled form controls to a portable candidate map. */
    public static function fromForm(mixed $fields, mixed $values): array
    {
        $fields ??= [];
        $values ??= [];
        if (! is_array($fields) || ! array_is_list($fields) || count($fields) > count(self::FIELDS)
            || ! is_array($values) || array_diff(array_keys($values), self::FIELDS)) {
            self::fail('random_fields', 'Chọn các thuộc tính ngẫu nhiên bằng các ô trên biểu mẫu.');
        }
        $choices = [];
        foreach ($fields as $field) {
            if (! is_string($field) || ! in_array($field, self::FIELDS, true) || array_key_exists($field, $choices)) {
                self::fail('random_fields', 'Thuộc tính ngẫu nhiên không hợp lệ hoặc bị chọn lặp.');
            }
            $value = $values[$field] ?? null;
            if (in_array($field, ['movie_key', 'category_key', 'suffix', 'fanmulu_suffix'], true)) {
                if (! is_array($value) || ! array_is_list($value)) {
                    self::fail('random_values.'.$field, 'Chọn ít nhất một giá trị cho thuộc tính này.');
                }
                $choices[$field] = array_map(fn ($item) => in_array($field, ['suffix', 'fanmulu_suffix'], true) && $item === '__none__' ? '' : $item, $value);
            } else {
                if ($field === 'name') {
                    if (! is_string($value)) {
                        self::fail('random_values.name', 'Điền danh sách UTF-8, mỗi dòng một tên website.');
                    }
                    $choices[$field] = WebsiteDisplayNames::normalize($value, 'random_values.name');

                    continue;
                }
                if (! is_string($value) || strlen($value) > 65536 || ! mb_check_encoding($value, 'UTF-8')) {
                    self::fail('random_values.'.$field, 'Điền danh sách UTF-8, mỗi dòng một giá trị.');
                }
                $choices[$field] = array_values(array_filter(array_map('trim', preg_split('/\R/u', $value) ?: []), fn ($item): bool => $item !== ''));
            }
        }

        return self::normalize($choices);
    }

    /** Pure syntax validation lets an existing request replay even after candidates are archived. */
    public static function normalize(mixed $choices): array
    {
        if (! is_array($choices) || ($choices !== [] && array_is_list($choices)) || array_diff(array_keys($choices), self::FIELDS)) {
            self::fail('random_fields', 'Danh sách thuộc tính ngẫu nhiên không hợp lệ.');
        }
        $normalized = [];
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $choices)) {
                continue;
            }
            $values = $choices[$field];
            if ($field === 'name') {
                if (! is_array($values) || ! array_is_list($values)) {
                    self::fail('random_values.name', 'Cần danh sách tên website.');
                }
                $normalized[$field] = WebsiteDisplayNames::normalize($values, 'random_values.name');

                continue;
            }
            if (! is_array($values) || ! array_is_list($values) || $values === [] || count($values) > 50) {
                self::fail('random_values.'.$field, 'Mỗi thuộc tính cần từ 1 đến 50 giá trị.');
            }
            $items = [];
            foreach ($values as $value) {
                if (! is_string($value) || strlen($value) > 1024 || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
                    self::fail('random_values.'.$field, 'Giá trị phải là văn bản hợp lệ, không có ký tự điều khiển.');
                }
                $value = trim($value);
                try {
                    if (isset(self::ROUTES[$field]) || $field === 'fanmulu_prefix') {
                        $value = FanmuluSettings::normalize(['enabled' => false, 'prefix' => $value])['prefix'];
                    } elseif ($field === 'movie_key') {
                        if (! in_array($value, ['source_id', 'pinyin_id', 'pinyin_compact_id'], true)) {
                            throw new \InvalidArgumentException('Kiểu URL phim cần là ID nguồn hoặc pinyin kèm ID.');
                        }
                    } elseif ($field === 'category_key') {
                        if (! in_array($value, ['id', 'pinyin_id', 'pinyin_compact_id'], true)) {
                            throw new \InvalidArgumentException('Kiểu URL danh mục cần là ID hoặc pinyin kèm ID.');
                        }
                    } elseif (in_array($field, ['suffix', 'fanmulu_suffix'], true)) {
                        $value = UrlSuffixCatalog::normalizeSuffix($value);
                    } else {
                        $value = FanmuluSettings::normalizeUrlRule(['mode' => 'tokens', 'template' => $value])['template'];
                    }
                } catch (\InvalidArgumentException $exception) {
                    self::fail('random_values.'.$field, $exception->getMessage());
                }
                if (! in_array($value, $items, true)) {
                    $items[] = $value;
                }
            }
            $normalized[$field] = $items;
        }

        return $normalized;
    }

    /** Check every possible route combination before any domain enters the queue. */
    public static function assertCompatible(array $choices, array $definition): void
    {
        $choices = self::normalize($choices);
        foreach (['suffix', 'fanmulu_suffix'] as $field) {
            foreach ($choices[$field] ?? [] as $suffix) {
                app(UrlSuffixCatalog::class)->assertSelectable($suffix, '', 'random_values.'.$field);
            }
        }
        if (array_intersect(array_keys($choices), [...array_keys(self::ROUTES), 'fanmulu_prefix']) === []) {
            return;
        }
        $profile = SitePresentationService::normalize(array_replace($definition['presentation'], ['fanmulu' => FanmuluSettings::defaults()]));
        $groups = ['home' => [$profile['routes']['home']]];
        foreach (self::ROUTES as $field => $type) {
            $groups[$type] = $choices[$field] ?? [$profile['routes'][$type]];
        }
        $settings = $definition['fanmulu'] ?? FanmuluPresetSettings::defaults();
        $groups['fanmulu'] = $choices['fanmulu_prefix'] ?? array_values(array_unique([$settings['prefix'] ?? '/f', ...($settings['prefixes'] ?? [])]));
        foreach ($groups as $type => $paths) {
            foreach ($groups as $other => $targets) {
                if ($type === $other) {
                    continue;
                }
                foreach ($paths as $path) {
                    foreach ($targets as $target) {
                        if ($path !== '/' && $target !== '/' && self::overlaps($path, $target)) {
                            self::fail('random_fields', 'Có tổ hợp đường dẫn '.$type.' ('.$path.') và '.$other.' ('.$target.') trùng hoặc lồng nhau. Sửa danh sách để mọi lựa chọn đều dùng được.');
                        }
                    }
                }
            }
        }
    }

    /** @return array{entry:array,definition:array} */
    public static function apply(array $entry, array $definition, array $choices): array
    {
        $choices = self::normalize($choices);
        if ($choices === []) {
            return compact('entry', 'definition');
        }
        $selected = [];
        foreach ($choices as $field => $values) {
            $selected[$field] = $values[random_int(0, count($values) - 1)];
        }
        if (isset($selected['name']) && ($entry['name'] ?? $entry['domain']) === $entry['domain']) {
            $entry['name'] = $selected['name'];
        }
        if (array_diff(array_keys($choices), ['name']) === []) {
            return compact('entry', 'definition');
        }
        $profile = $definition['presentation'];
        foreach (self::ROUTES as $field => $type) {
            if (isset($selected[$field])) {
                $profile['routes'][$type] = $selected[$field];
            }
        }
        foreach (['movie_key', 'category_key'] as $field) {
            if (isset($selected[$field])) {
                $profile[$field] = $selected[$field];
            }
        }
        if (array_key_exists('suffix', $selected)) {
            $profile['suffixes'] = array_replace(array_fill_keys(SitePresentationService::TYPES, ''), $profile['suffixes'] ?? []);
            foreach (['list', 'detail', 'play', 'search'] as $type) {
                $profile['suffixes'][$type] = $selected['suffix'];
            }
        }
        try {
            // Provisioning applies the portable fanmulu definition separately;
            // an old embedded actor/root must not override those frozen choices.
            $profile = SitePresentationService::normalize(array_replace($profile, ['fanmulu' => FanmuluSettings::defaults()]));
            if (array_key_exists('fanmulu', $definition) || array_intersect(array_keys($selected), ['fanmulu_prefix', 'fanmulu_suffix', 'fanmulu_url_template']) !== []) {
                $settings = $definition['fanmulu'] ?? FanmuluPresetSettings::defaults();
                if (isset($selected['fanmulu_prefix'])) {
                    $settings['prefix'] = $selected['fanmulu_prefix'];
                    $settings['prefixes'] = [$selected['fanmulu_prefix']];
                }
                if (array_key_exists('fanmulu_suffix', $selected)) {
                    $settings['suffix'] = $selected['fanmulu_suffix'];
                }
                if (isset($selected['fanmulu_url_template'])) {
                    $settings['url_rule'] = ['mode' => 'tokens', 'template' => $selected['fanmulu_url_template']];
                }
                $portable = FanmuluSettings::normalize(array_replace(FanmuluPresetSettings::defaults(), $settings, ['actor_id' => 1]), $profile);
                unset($portable['actor_id']);
                $definition['fanmulu'] = array_replace(FanmuluPresetSettings::defaults(), $portable);
            }
            $definition['presentation'] = $profile;
        } catch (\InvalidArgumentException $exception) {
            self::fail('random_fields', $exception->getMessage());
        }

        return compact('entry', 'definition');
    }

    private static function overlaps(string $left, string $right): bool
    {
        return $left === $right || str_starts_with($left, $right.'/') || str_starts_with($right, $left.'/');
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
