<?php

namespace App\Domain\Publishing;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Rebuildable search projections. Publication fields always come from the frozen snapshot. */
final class CatalogMetadata
{
    public function available(): bool
    {
        if (! config('public_on_demand.search_index_enabled', true)) {
            return false;
        }
        $tables = DB::selectOne("SELECT to_regclass('catalog_metadata_state') IS NOT NULL
            AND to_regclass('catalog_revision_metadata') IS NOT NULL
            AND to_regclass('catalog_publication_metadata') IS NOT NULL AS available");

        return $tables->available && (bool) DB::table('catalog_metadata_state')->where('id', 1)->value('ready');
    }

    public function filters(Builder $query, string $content, array $filters, ?string $grams = null): void
    {
        foreach (['actors_any' => ['actors', 3, 'diễn viên'], 'genres_any' => ['genre', 6, 'thể loại']] as $filter => [$field, $limit, $label]) {
            if (! array_key_exists($filter, $filters)) {
                continue;
            }
            $terms = $this->terms($filters[$filter], $limit, $label);
            if ($terms === []) {
                $query->whereRaw('false');

                continue;
            }
            if ($grams !== null) {
                $placeholders = implode(',', array_fill(0, count($terms), '?'));
                $query->whereRaw("tram_catalog_exact_terms(($content)->'$field') && ARRAY[$placeholders]::text[]", $terms);

                continue;
            }
            $query->where(function (Builder $matches) use ($content, $field, $terms): void {
                foreach ($terms as $term) {
                    $matches->orWhereRaw("((jsonb_typeof(($content)->'$field') = 'array' AND jsonb_exists(($content)->'$field', ?)) OR (jsonb_typeof(($content)->'$field') = 'string'
                            AND ? = ANY(regexp_split_to_array(btrim(($content)->>'$field'), '[[:space:]]*[/,，、|;；][[:space:]]*'))))", [$term, $term]);
                }
            });
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $term = (string) $filters['q'];
            $exactGram = false;
            // Keep the existing LIKE wildcard/escape semantics. Only literal terms
            // can safely use required character grams before the exact recheck.
            if ($grams !== null && ! strpbrk($term, '%_\\')) {
                $query->whereRaw($grams.' @> tram_catalog_query_grams(?)', [$term]);
                // A single CJK character or bigram is itself an exact substring,
                // so there is no lossy candidate recheck (or extra selectivity guess).
                $exactGram = preg_match('/^[\x{3400}-\x{9fff}\x{f900}-\x{faff}]{1,2}$/u', $term) === 1;
            }
            if (! $exactGram) {
                $query->where(function (Builder $search) use ($content, $term): void {
                    foreach (['title', 'director', 'directors', 'actors', 'genre'] as $field) {
                        $search->orWhereRaw('('.$content.")->>'".$field."' ILIKE ?", ['%'.$term.'%']);
                    }
                });
            }
        }
        foreach (['genre', 'area', 'year', 'language'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                if ($grams !== null) {
                    $query->whereRaw('md5(('.$content.")->>'".$field."') = md5(?)", [(string) $filters[$field]]);
                }
                $query->whereRaw('('.$content.")->>'".$field."' = ?", [(string) $filters[$field]]);
            }
        }
    }

    /** Internal recommendation filters match complete terms, never title/search substrings. */
    private function terms(mixed $terms, int $limit, string $label): array
    {
        if (! is_array($terms) || ! array_is_list($terms) || count($terms) > $limit) {
            throw new \InvalidArgumentException('Gợi ý cùng '.$label.' nhận tối đa '.$limit.' giá trị.');
        }
        $names = [];
        foreach ($terms as $term) {
            if (! is_string($term) || ! mb_check_encoding($term, 'UTF-8') || mb_strlen($term) > 200 || preg_match('/[\x00-\x1f\x7f]/u', $term)) {
                throw new \InvalidArgumentException('Giá trị '.$label.' không hợp lệ.');
            }
            $term = \Normalizer::normalize(trim($term), \Normalizer::FORM_C);
            if ($term !== '') {
                $names[$term] = $term;
            }
        }

        return array_values($names);
    }
}
