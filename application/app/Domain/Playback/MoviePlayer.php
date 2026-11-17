<?php

namespace App\Domain\Playback;

use App\Domain\Ingestion\CanonicalRecord;
use App\Domain\Support\Json;

/** First-party playback enhancement for dynamic play pages, never stored snapshots. */
final class MoviePlayer
{
    public function available(): bool
    {
        return $this->script() !== null;
    }

    public function attach(string $html, array $play, array $snapshot): array
    {
        $type = $this->type($play);
        $script = $this->script();
        if ($type === null || $script === null) {
            return ['html' => $html, 'enabled' => false];
        }
        $component = view('public.partials.movie-player', compact('play', 'snapshot', 'type'))->render();
        $placed = false;
        // Replace only the existing player surface; preserve every surrounding byte.
        preg_match_all('~<!--.*?-->(*SKIP)(*F)|<(div|section)\b[^>]*>~is', $html, $openings, PREG_OFFSET_CAPTURE);
        foreach ($openings[0] as [$opening, $offset]) {
            $idMatch = preg_match('~\sid\s*=\s*([\'\"])fzhm-player-surface\1(?=\s|/?>)~i', $opening);
            $classMatch = preg_match('~\sclass\s*=\s*([\'\"])(.*?)\1~is', $opening, $class)
                && in_array('play-source', preg_split('/\s+/', trim($class[2])), true);
            if (! $idMatch && ! $classMatch) {
                continue;
            }
            preg_match('~^<(div|section)\b~i', $opening, $tagMatch);
            $tag = $tagMatch[1];
            $start = $offset + strlen($opening);
            preg_match_all('~<!--.*?-->(*SKIP)(*F)|</?'.preg_quote($tag, '~').'\b[^>]*>~is', substr($html, $start), $tokens, PREG_OFFSET_CAPTURE);
            $depth = 1;
            foreach ($tokens[0] as [$token, $position]) {
                $depth += str_starts_with($token, '</') ? -1 : 1;
                if ($depth === 0) {
                    $tail = substr($html, $start + $position + strlen($token));
                    if ($classMatch && in_array('ewave-player-video', preg_split('/\s+/', trim($class[2])), true)) {
                        $tail = $this->withoutEmptyEwaveFrames($tail);
                    }
                    $html = substr($html, 0, $start).$component.$token.$tail;
                    $placed = true;
                    break 2;
                }
            }
        }
        if (! $placed) {
            $position = stripos($html, '<main');
            $position = $position === false ? false : strpos($html, '>', $position);
            if ($position !== false) {
                $html = substr_replace($html, $component, $position + 1, 0);
            } else {
                $position = stripos($html, '</body>');
                $html = $position === false ? $html.$component : substr_replace($html, $component, $position, 0);
            }
        }
        $scriptTag = '<script defer src="'.htmlspecialchars($script, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"></script>';
        $position = stripos($html, '</body>');
        $html = $position === false ? $html.$scriptTag : substr_replace($html, $scriptTag, $position, 0);

        return ['html' => $html, 'enabled' => true];
    }

    /** Remove only whitespace-only legacy frames between the enhanced EWave surface and its footer. */
    private function withoutEmptyEwaveFrames(string $tail): string
    {
        if (! preg_match('~<div\s+class\s*=\s*([\'\"])\s*ewave-player-footer\s*\1\s*>~i', $tail, $footer, PREG_OFFSET_CAPTURE)) {
            return $tail;
        }
        $boundary = $footer[0][1];
        $legacy = substr($tail, 0, $boundary);
        $legacy = preg_replace('~<!--.*?-->(*SKIP)(*F)|<div\s+class\s*=\s*([\'\"])\s*embed-responsive\s+embed-responsive-16by9\s*\1\s*>\s*</div>~is', '', $legacy);

        return $legacy.substr($tail, $boundary);
    }

    public function type(array $play): ?string
    {
        if (($play['can_play'] ?? false) !== true) {
            return null;
        }
        try {
            $url = CanonicalRecord::dataUrl($play['source_url'] ?? null);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $parts = $url ? parse_url($url) : false;
        if (! $parts || strtolower($parts['scheme']) !== 'https' || isset($parts['fragment'])
            || isset($parts['port']) && (int) $parts['port'] !== 443) {
            return null;
        }
        $extension = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));
        if ($extension === 'm3u8') {
            return 'hls';
        }

        return ($play['mode'] ?? '') === 'native' && in_array($extension, ['mp4', 'webm', 'ogg'], true) ? 'video' : null;
    }

    private function script(): ?string
    {
        $manifest = public_path('build/player/manifest.json');
        if (! is_file($manifest)) {
            return null;
        }
        try {
            $data = Json::decode(file_get_contents($manifest));
        } catch (\Throwable) {
            return null;
        }
        $script = $data['script'] ?? null;

        return is_string($script) && preg_match('~^/build/player/tram-movie-player-[a-f0-9]{8,64}\.js$~D', $script)
            && is_file(public_path(ltrim($script, '/'))) ? $script : null;
    }
}
