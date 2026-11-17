<?php

namespace App\Domain\Rules;

final class TemplateCompiler
{
    public const TOKENS = ['content.title', 'content.genre', 'content.author', 'content.year', 'content.area', 'content.director', 'content.actors', 'content.language', 'kw.primary', 'kw.secondary', 'site.name', 'category.name', 'search.query', 'episode.title', 'episode.index'];

    public function validate(string $template): void
    {
        if (strlen($template) > 20000) {
            throw new \InvalidArgumentException('Template quá dài');
        }
        if (preg_match('/<\s*(script|style|iframe|object)|\bon\w+\s*=|<\?php|@php|@include/i', $template)) {
            throw new \InvalidArgumentException('Template chứa mã hoặc thuộc tính không được phép');
        }
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u', $template, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$token,$position]) {
            if (! in_array(trim($token), self::TOKENS) && ! preg_match('/^var\.[a-z][a-z0-9_]{0,59}$/D', trim($token))) {
                throw new \InvalidArgumentException("Token không hợp lệ tại $position: $token");
            }
        }
        $rest = preg_replace('/\{\{\s*([^{}]+?)\s*\}\}/u', '', $template);
        if (str_contains($rest, '{{') || str_contains($rest, '}}')) {
            throw new \InvalidArgumentException('Ngoặc token không đóng đúng');
        }
        if (preg_match('/<[^>]*\{\{/u', $template)) {
            throw new \InvalidArgumentException('Token chỉ được dùng trong text; không dùng trong thuộc tính HTML');
        }
    }

    public function compile(string $template, array $values, array $fallbacks = [], bool $html = false): string
    {
        $this->validate($template);

        return preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', function ($m) use ($values, $fallbacks, $html) {
            $token = trim($m[1]);
            $value = $values[$token] ?? ($fallbacks[$token] ?? null);
            if ($value === null || ($value === '' && ! in_array($token, ['kw.secondary', 'search.query'], true) && ! str_starts_with($token, 'var.') && ! array_key_exists($token, $fallbacks))) {
                throw new \InvalidArgumentException('Thiếu dữ liệu token '.$token);
            }
            if (! is_scalar($value)) {
                throw new \InvalidArgumentException('Token sai kiểu '.$token);
            }

            return $html ? htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : (string) $value;
        }, $template);
    }
}
