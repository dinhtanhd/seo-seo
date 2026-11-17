<?php

namespace App\Domain\Sites;

use App\Domain\Fanmulu\FanmuluSettings;
use Illuminate\Validation\ValidationException;

final class FanmuluPresetSettings
{
    /** @return array{enabled: bool, prefix: string, suffix: string, links_per_page: int, max_pages: int} */
    public static function defaults(bool $enabled = false): array
    {
        return ['enabled' => $enabled, 'prefix' => '/f', 'suffix' => '', 'links_per_page' => 3, 'max_pages' => 10000];
    }

    /** Validate portable configuration without storing an actor from another website. */
    public static function normalize(array $settings, array $presentation, array $previous = []): array
    {
        if (array_diff(array_keys($settings), [...array_keys(self::defaults()), 'prefixes', 'url_rule', 'display_name_pool_ids'])) {
            throw ValidationException::withMessages(['fanmulu' => 'Cấu hình fanmulu chỉ nhận trạng thái, đường dẫn, quy tắc URL và các giới hạn; tài khoản áp dụng do hệ thống xác định.']);
        }
        if (! array_key_exists('prefixes', $settings) && array_key_exists('prefixes', $previous)) {
            $settings['prefixes'] = $previous['prefixes'];
        }
        if (! array_key_exists('url_rule', $settings) && array_key_exists('url_rule', $previous)) {
            $settings['url_rule'] = $previous['url_rule'];
        }
        if (! array_key_exists('display_name_pool_ids', $settings) && array_key_exists('display_name_pool_ids', $previous)) {
            $settings['display_name_pool_ids'] = $previous['display_name_pool_ids'];
        }
        try {
            $normalized = FanmuluSettings::normalize(array_replace(self::defaults(), $settings, ['actor_id' => 1]), $presentation);
        } catch (\InvalidArgumentException|\TypeError $error) {
            throw ValidationException::withMessages(['fanmulu' => $error->getMessage()]);
        }
        app(UrlSuffixCatalog::class)->assertSelectable($normalized['suffix'], $previous['suffix'] ?? '', 'fanmulu.suffix');
        unset($normalized['actor_id']);

        return array_replace(self::defaults(), $normalized);
    }
}
