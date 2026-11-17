<?php

namespace App\Domain\Ingestion;

final class HtmlSanitizer
{
    public function sanitize(string $html): string
    {
        if (! mb_check_encoding($html, 'UTF-8') || strlen($html) > 2 * 1024 * 1024) {
            throw new \InvalidArgumentException('HTML quá lớn hoặc không phải UTF-8.');
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><div id="source-root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $root = $dom->getElementById('source-root');
            if (! $root) {
                return '';
            }
            $this->clean($root);
            $result = '';
            foreach ($root->childNodes as $child) {
                $result .= $dom->saveHTML($child);
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function clean(\DOMNode $parent): void
    {
        $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'h2', 'h3', 'h4', 'span', 'div', 'a'];
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof \DOMComment || $node instanceof \DOMProcessingInstruction) {
                $parent->removeChild($node);
            } elseif ($node instanceof \DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'form', 'input', 'button', 'img', 'video', 'audio', 'source', 'link', 'meta'], true)) {
                    $parent->removeChild($node);

                    continue;
                }
                $this->clean($node);
                foreach (iterator_to_array($node->attributes) as $attr) {
                    if ($tag !== 'a' || ! in_array(strtolower($attr->name), ['href', 'title'], true)) {
                        $node->removeAttribute($attr->name);
                    }
                }
                if ($tag === 'a') {
                    $href = trim($node->getAttribute('href'));
                    if (! preg_match('~^(https?://|/(?!/)|#)~i', $href) || preg_match('/[\x00-\x20\x7f]/', $href)) {
                        $node->removeAttribute('href');
                    }
                    $node->setAttribute('rel', 'nofollow noopener noreferrer');
                }
                if (! in_array($tag, $allowed, true)) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
            }
        }
    }
}
