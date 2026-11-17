<?php

namespace App\Domain\Support;

use App\Domain\Rules\SeoTemplateBundle;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class TemplateConfigurationForm
{
    public const SLOTS = ['title', 'description', 'h1', 'intro', 'meta_keywords', 'related_links', 'managed_body'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return ['slots' => [
            'title' => '{{content.title}} - {{kw.primary}} - {{site.name}}',
            'description' => '{{content.title}}资料、简介与相关内容。',
            'h1' => '{{content.title}}', 'intro' => '收录{{kw.primary}}相关信息。',
            'meta_keywords' => SeoTemplateBundle::defaultMetaKeywords('detail'),
        ], 'managed_body_zone' => 'main-content'];
    }

    /** @return array<string, mixed> */
    public static function values(array $definition): array
    {
        $slots = [];
        foreach (array_unique(array_merge(self::SLOTS, array_keys($definition['slots'] ?? []))) as $slot) {
            $values = RuleConfigurationForm::values($slot, $definition['slots'][$slot] ?? null);
            $slots[$slot] = ['mode' => $values['mode']] + $values['rule_form'];
        }

        return ['managed_body_zone' => $definition['managed_body_zone'] ?? 'main-content', 'slots' => $slots];
    }

    /** @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request, array $current = []): array
    {
        if ($current === []) {
            $current = self::defaults();
        }
        $data = $request->validate([
            'template_form' => 'required|array:managed_body_zone,slots',
            'template_form.managed_body_zone' => 'required|string|max:200',
            'template_form.slots' => 'required|array',
            'new_h2_name' => 'nullable|string|regex:/^[a-z0-9_-]{1,70}$/',
            'new_h2_template' => 'nullable|string|max:20000',
        ]);
        $definition = $current;
        $definition['slots'] ??= [];
        $before = self::values($current);
        if ($data['template_form']['managed_body_zone'] !== $before['managed_body_zone']) {
            $definition['managed_body_zone'] = $data['template_form']['managed_body_zone'];
        }
        foreach ($data['template_form']['slots'] as $slot => $fields) {
            if (! is_array($fields)) {
                throw ValidationException::withMessages(['template_form.slots' => 'Mỗi vị trí phải có các trường cấu hình hợp lệ.']);
            }
            if (! in_array($slot, self::SLOTS, true) && ! preg_match('/^h2\.[a-z0-9_-]+$/', $slot)) {
                throw ValidationException::withMessages(['template_form.slots' => 'Vị trí slot không hợp lệ.']);
            }
            $previous = $current['slots'][$slot] ?? null;
            $value = RuleConfigurationForm::fromFields($slot, $fields, $previous, true);
            if (($fields['mode'] ?? '') === 'inherit' && ! (array_key_exists($slot, $current['slots'] ?? []) && $previous === null)) {
                unset($definition['slots'][$slot]);
            } else {
                $definition['slots'][$slot] = $value;
            }
        }
        if (! empty($data['new_h2_name'])) {
            $slot = 'h2.'.$data['new_h2_name'];
            if (array_key_exists($slot, $definition['slots'])) {
                throw ValidationException::withMessages(['new_h2_name' => 'Slot H2 đã có. Hãy sửa slot trong danh sách.']);
            }
            $definition['slots'][$slot] = (string) ($data['new_h2_template'] ?? '');
        }

        return $definition;
    }
}
