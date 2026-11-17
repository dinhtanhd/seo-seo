<?php

namespace App\Domain\Support;

use App\Domain\Rules\TemplateCompiler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RuleConfigurationForm
{
    /** @return array<string, mixed> */
    public static function values(string $slot, mixed $current, array $defaultPoolIds = []): array
    {
        $config = is_array($current) ? $current : [];
        $fallbacks = [];
        foreach (TemplateCompiler::TOKENS as $token) {
            $value = $config['fallbacks'][$token] ?? '';
            $fallbacks[$token] = is_scalar($value) ? (string) $value : '';
        }

        return [
            'mode' => $current === null ? 'inherit' : ($current === false || ($config['enabled'] ?? true) === false ? 'off' : 'on'),
            'rule_form' => [
                'template' => is_string($current) ? $current : ($config['template'] ?? ($current === null ? self::defaultExpression($slot) : '')),
                'fallbacks' => $fallbacks,
                'max_anchors' => $config['max_anchors'] ?? 3,
                'per_keyword' => $config['per_keyword'] ?? 1,
            ],
            'allocation_mode' => $config['mode'] ?? 'weighted',
            'allocation_count' => $config['count'] ?? 1,
            'allocation_required' => (bool) ($config['required'] ?? false),
            'pool_ids' => $config['pool_ids'] ?? $defaultPoolIds,
            'fallback_pool_ids' => $config['fallback_pool_ids'] ?? [],
            'keyword_ids' => $config['keyword_ids'] ?? $config['manual_ids'] ?? [],
            'allocation_filter_form' => MetadataFilterForm::values(is_array($config['filters'] ?? null) ? $config['filters'] : []),
        ];
    }

    public static function fromRequest(Request $request, string $slot, mixed $current = null): mixed
    {
        $data = $request->validate(['mode' => 'required|in:on,inherit,off']);
        if ($data['mode'] !== 'on') {
            return self::state($data['mode'], $current);
        }
        if (! str_starts_with($slot, 'allocation.')) {
            $fields = $request->validate(['rule_form' => ($slot === 'related_links' ? 'nullable' : 'required').'|array:_present,template,fallbacks,max_anchors,per_keyword']);

            return self::fromFields($slot, ($fields['rule_form'] ?? []) + ['mode' => 'on'], $current);
        }

        $data += $request->validate([
            'allocation_mode' => 'required|in:weighted,random-once,sequential,manual',
            'allocation_count' => 'required|integer|min:0|max:'.($slot === 'allocation.primary' ? 1 : 20),
            'allocation_required' => 'required|boolean',
            'pool_ids' => 'nullable|array', 'pool_ids.*' => 'integer|min:1|distinct',
            'fallback_pool_ids' => 'nullable|array', 'fallback_pool_ids.*' => 'integer|min:1|distinct',
            'keyword_ids' => 'nullable|array', 'keyword_ids.*' => 'integer|min:1|distinct',
        ]);
        $before = self::values($slot, $current);
        $config = is_array($current) ? $current : ['enabled' => true];
        $changes = [
            'mode' => $data['allocation_mode'], 'count' => (int) $data['allocation_count'],
            'required' => $request->boolean('allocation_required'),
            'pool_ids' => array_map('intval', $data['pool_ids'] ?? []),
            'fallback_pool_ids' => array_map('intval', $data['fallback_pool_ids'] ?? []),
            'keyword_ids' => array_map('intval', $data['keyword_ids'] ?? []),
        ];
        foreach ($changes as $key => $value) {
            $formKey = ['mode' => 'allocation_mode', 'count' => 'allocation_count', 'required' => 'allocation_required'][$key] ?? $key;
            $prior = $before[$formKey];
            if (is_int($value)) {
                $prior = (int) $prior;
            } elseif (is_array($value)) {
                $prior = array_map('intval', $prior);
            }
            if (! is_array($current) || $value !== $prior) {
                $target = $key === 'keyword_ids' && array_key_exists('manual_ids', $config) && ! array_key_exists('keyword_ids', $config) ? 'manual_ids' : $key;
                $config[$target] = $value;
            }
        }
        $priorFilters = is_array($config['filters'] ?? null) ? $config['filters'] : [];
        $filters = MetadataFilterForm::fromRequest($request, 'allocation_filter_form', $priorFilters);
        if ($filters !== $priorFilters) {
            $config['filters'] = $filters;
        }
        if (($config['enabled'] ?? true) === false) {
            $config['enabled'] = true;
        }

        return $config;
    }

    /** @param array<string, mixed> $fields */
    public static function fromFields(string $slot, array $fields, mixed $current = null, bool $templateSlot = false): mixed
    {
        $unknownFields = array_diff(array_keys($fields), ['mode', '_present', 'template', 'fallbacks', 'max_anchors', 'per_keyword']);
        if ($unknownFields !== []) {
            throw ValidationException::withMessages(['configuration_form' => 'Cấu hình có trường không được hỗ trợ.']);
        }
        $data = Validator::make($fields, [
            'mode' => 'required|in:on,inherit,off',
            'template' => 'nullable|string|max:20000',
            'fallbacks' => 'nullable|array',
            'max_anchors' => 'nullable|integer|min:0|max:100',
            'per_keyword' => 'nullable|integer|min:0|max:100',
        ])->validate();
        if (isset($fields['fallbacks']) && is_array($fields['fallbacks'])) {
            foreach ($fields['fallbacks'] as $token => $value) {
                if (! in_array($token, TemplateCompiler::TOKENS, true)) {
                    throw ValidationException::withMessages(['fallbacks' => 'Biến dự phòng không được hỗ trợ.']);
                }
                Validator::make(['fallback_value' => $value], ['fallback_value' => 'nullable|string|max:20000'])->validate();
            }
            $data['fallbacks'] = $fields['fallbacks'];
        }
        if ($data['mode'] !== 'on') {
            return self::state($data['mode'], $current);
        }
        $before = self::values($slot, $current)['rule_form'];
        $config = is_array($current) ? $current : ['enabled' => true];
        if ($slot === 'managed_body') {
            foreach (['max_anchors', 'per_keyword'] as $key) {
                if (array_key_exists($key, $data) && ((! is_array($current) && $current !== true) || (int) $data[$key] !== (int) $before[$key])) {
                    $config[$key] = (int) $data[$key];
                }
            }
        } elseif ($slot !== 'related_links') {
            $expression = (string) ($data['template'] ?? $before['template']);
            if ((! is_array($current) && $current !== true) || $expression !== (string) $before['template']) {
                $config['template'] = $expression;
            }
            $fallbacks = is_array($config['fallbacks'] ?? null) ? $config['fallbacks'] : [];
            foreach (TemplateCompiler::TOKENS as $token) {
                if (! array_key_exists($token, $data['fallbacks'] ?? [])) {
                    continue;
                }
                $value = (string) ($data['fallbacks'][$token] ?? '');
                if ($value !== $before['fallbacks'][$token]) {
                    if ($value === '') {
                        unset($fallbacks[$token]);
                    } else {
                        $fallbacks[$token] = $value;
                    }
                }
            }
            if ($fallbacks !== ($config['fallbacks'] ?? [])) {
                $config['fallbacks'] = $fallbacks;
            }
            if ($templateSlot && (is_string($current) || $current === null) && $fallbacks === []) {
                return $expression;
            }
        }
        if (($config['enabled'] ?? true) === false) {
            $config['enabled'] = true;
        }
        if ($templateSlot && $current === true && $config === ['enabled' => true]) {
            return true;
        }

        return $config;
    }

    private static function state(string $mode, mixed $current): mixed
    {
        if ($mode === self::values('', $current)['mode']) {
            return $current;
        }

        return $mode === 'inherit' ? null : false;
    }

    private static function defaultExpression(string $slot): string
    {
        return match ($slot) {
            'title' => '{{content.title}} - {{kw.primary}} - {{site.name}}',
            'h1' => '{{content.title}}',
            'description' => '{{content.title}}资料、简介与相关内容。',
            'intro' => '收录{{kw.primary}}相关信息。',
            default => '',
        };
    }
}
