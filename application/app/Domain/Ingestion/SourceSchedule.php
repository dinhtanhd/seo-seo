<?php

namespace App\Domain\Ingestion;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SourceSchedule
{
    private const FIELDS = ['schedule_mode', 'schedule_daily_time', 'schedule_timezone', 'schedule_interval_minutes'];

    public const TIMEZONES = ['Asia/Shanghai', 'Asia/Ho_Chi_Minh', 'UTC'];

    /** The scheduling override never becomes part of the connector content hash. */
    public static function forSource(object $source): array
    {
        $configuration = json_decode($source->config_json, true, 24, JSON_THROW_ON_ERROR);
        if (! is_array($configuration)) {
            throw new \InvalidArgumentException('Cấu hình nguồn phải là một đối tượng.');
        }
        if (($source->schedule_json ?? null) === null) {
            return $configuration;
        }
        $override = json_decode($source->schedule_json, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($override) || array_diff(array_keys($override), self::FIELDS) !== []) {
            throw new \InvalidArgumentException('Cấu hình lịch cập nhật không hợp lệ.');
        }

        return array_replace($configuration, $override);
    }

    /** @return array{mode:string,time:string,timezone:string,interval:int} */
    public static function values(array $config): array
    {
        $interval = self::integer($config['schedule_interval_minutes'] ?? 0) ?? 0;
        $mode = $config['schedule_mode'] ?? ($interval >= 5 ? 'interval' : 'off');

        return [
            'mode' => in_array($mode, ['off', 'daily', 'interval'], true) ? $mode : 'off',
            'time' => self::validTime($config['schedule_daily_time'] ?? null) ? $config['schedule_daily_time'] : '03:00',
            'timezone' => in_array($config['schedule_timezone'] ?? null, self::TIMEZONES, true) ? $config['schedule_timezone'] : 'Asia/Shanghai',
            'interval' => $interval >= 5 && $interval <= 10080 ? $interval : 60,
        ];
    }

    /**
     * Schedule controls own these keys. An older connection editor cannot clear
     * a daily schedule merely by omitting its new fields or echoing interval 0.
     */
    public static function apply(array $configuration, array $data, string $kind, array $previous = []): array
    {
        if ($previous !== []) {
            foreach (self::FIELDS as $field) {
                unset($configuration[$field]);
                if (array_key_exists($field, $previous)) {
                    $configuration[$field] = $previous[$field];
                }
            }
        }
        if (! array_key_exists('schedule_mode', $data)) {
            if (! array_key_exists('schedule_interval_minutes', $data)) {
                return $configuration;
            }
            $validated = Validator::make($data, ['schedule_interval_minutes' => 'required|integer|min:0|max:10080'])->validate();
            $interval = (int) $validated['schedule_interval_minutes'];
            if ($interval > 0 && $interval < 5) {
                throw ValidationException::withMessages(['schedule_interval_minutes' => 'Lịch crawl tối thiểu 5 phút; 0 để tắt.']);
            }
            if ($previous !== [] && $interval === (int) ($previous['schedule_interval_minutes'] ?? 0)) {
                return $configuration;
            }
            self::requireRemote($kind, $interval > 0);
            if (array_key_exists('schedule_mode', $configuration)) {
                $configuration['schedule_mode'] = $interval === 0 ? 'off' : 'interval';
                unset($configuration['schedule_daily_time'], $configuration['schedule_timezone']);
            }
            if ($interval !== 0 || array_key_exists('schedule_interval_minutes', $configuration)) {
                $configuration['schedule_interval_minutes'] = $interval;
            }

            return $configuration;
        }
        $validated = Validator::make($data, [
            'schedule_mode' => 'required|in:off,daily,interval',
            'schedule_daily_time' => ['required_if:schedule_mode,daily', 'exclude_unless:schedule_mode,daily', 'string', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D'],
            'schedule_timezone' => 'required_if:schedule_mode,daily|exclude_unless:schedule_mode,daily|string|in:'.implode(',', self::TIMEZONES),
            'schedule_interval_minutes' => 'required_if:schedule_mode,interval|exclude_unless:schedule_mode,interval|integer|min:5|max:10080',
        ], [
            'schedule_daily_time.required_if' => 'Chọn giờ cập nhật hằng ngày.',
            'schedule_daily_time.regex' => 'Giờ cập nhật phải có dạng HH:MM, từ 00:00 đến 23:59.',
            'schedule_timezone.required_if' => 'Chọn múi giờ cho lịch cập nhật.',
            'schedule_timezone.in' => 'Múi giờ lịch cập nhật không được hỗ trợ.',
        ])->validate();
        self::requireRemote($kind, $validated['schedule_mode'] !== 'off');
        $configuration['schedule_mode'] = $validated['schedule_mode'];
        $configuration['schedule_interval_minutes'] = $validated['schedule_mode'] === 'interval' ? (int) $validated['schedule_interval_minutes'] : 0;
        unset($configuration['schedule_daily_time'], $configuration['schedule_timezone']);
        if ($validated['schedule_mode'] === 'daily') {
            $configuration['schedule_daily_time'] = $validated['schedule_daily_time'];
            $configuration['schedule_timezone'] = $validated['schedule_timezone'];
        }

        return $configuration;
    }

    public static function dueKey(array $config, CarbonImmutable $now): ?string
    {
        $schedule = self::validated($config);
        if ($schedule['mode'] === 'off') {
            return null;
        }
        if ($schedule['mode'] === 'interval') {
            return 'scheduled:'.(int) floor($now->getTimestamp() / ($schedule['interval'] * 60));
        }
        $local = $now->setTimezone($schedule['timezone']);
        if ($local->format('H:i') < $schedule['time']) {
            return null;
        }

        return 'scheduled:daily:'.$local->format('Y-m-d');
    }

    public static function label(array $config): string
    {
        try {
            $schedule = self::validated($config);
        } catch (\InvalidArgumentException) {
            return 'Cấu hình lịch không hợp lệ';
        }
        if ($schedule['mode'] === 'off') {
            return 'Đang tắt';
        }
        if ($schedule['mode'] === 'interval') {
            return 'Mỗi '.$schedule['interval'].' phút';
        }
        $timezone = match ($schedule['timezone']) {
            'Asia/Shanghai' => 'giờ Trung Quốc (UTC+8)',
            'Asia/Ho_Chi_Minh' => 'giờ Việt Nam (UTC+7)',
            default => 'UTC',
        };

        return 'Mỗi ngày lúc '.$schedule['time'].' · '.$timezone;
    }

    /** @return array{mode:string,time:string,timezone:string,interval:int} */
    private static function validated(array $config): array
    {
        $rawInterval = $config['schedule_interval_minutes'] ?? 0;
        $interval = self::integer($rawInterval);
        if ($interval === null || $interval < 0 || $interval > 10080 || ($interval > 0 && $interval < 5)) {
            throw new \InvalidArgumentException('Chu kỳ cập nhật phải là 0 hoặc 5–10080 phút.');
        }
        $mode = $config['schedule_mode'] ?? ($interval >= 5 ? 'interval' : 'off');
        if (! in_array($mode, ['off', 'daily', 'interval'], true)) {
            throw new \InvalidArgumentException('Chế độ lịch cập nhật không hợp lệ.');
        }
        if ($mode === 'interval' && $interval < 5) {
            throw new \InvalidArgumentException('Lịch theo chu kỳ cần tối thiểu 5 phút.');
        }
        if ($mode === 'daily' && (! self::validTime($config['schedule_daily_time'] ?? null)
            || ! in_array($config['schedule_timezone'] ?? null, self::TIMEZONES, true))) {
            throw new \InvalidArgumentException('Lịch hằng ngày cần giờ HH:MM và múi giờ được hỗ trợ.');
        }

        return ['mode' => $mode, 'time' => $config['schedule_daily_time'] ?? '03:00',
            'timezone' => $config['schedule_timezone'] ?? 'Asia/Shanghai', 'interval' => $interval];
    }

    private static function validTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value) === 1;
    }

    private static function integer(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && preg_match('/^[0-9]{1,5}$/D', $value))) ? (int) $value : null;
    }

    private static function requireRemote(string $kind, bool $enabled): void
    {
        if ($enabled && ! in_array($kind, ['maccms', 'shandian', 'html'], true)) {
            throw ValidationException::withMessages(['schedule_mode' => 'Chỉ nguồn API hoặc HTML mới có thể tự cập nhật.']);
        }
    }
}
