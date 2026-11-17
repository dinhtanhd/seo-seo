<?php

namespace App\Domain\Publishing;

use Illuminate\Database\Query\Builder;

/** Current film fields are a projection; publication identity and SEO stay frozen. */
final class LiveMovieCatalogQuery
{
    /**
     * @return array{content:string,fields:string,grams:?string,language:string,revision_id:string,revision_alias:string}
     */
    public function attach(Builder $query, string $pageAlias, string $publicationAlias, string $prefix = 'lmc', ?bool $indexed = null): array
    {
        foreach ([$pageAlias, $publicationAlias, $prefix] as $identifier) {
            if (preg_match('/^[a-z][a-z0-9_]{0,23}$/D', $identifier) !== 1) {
                throw new \InvalidArgumentException('Alias truy vấn nội dung phim không hợp lệ.');
            }
        }
        $revision = $prefix.'_revision';
        $revisionMetadata = $prefix.'_revision_meta';
        $publicationMetadata = $prefix.'_publication_meta';
        $query->leftJoin('content_revisions as '.$revision, function ($join) use ($pageAlias, $revision): void {
            $join->on($revision.'.id', '=', $pageAlias.'.serving_content_revision_id')
                ->on($revision.'.content_item_id', '=', $pageAlias.'.content_item_id');
        });
        $frozen = $publicationAlias.".snapshot_json->'content'";
        $content = 'COALESCE('.$revision.'.payload_json, '.$frozen.')';
        $indexed ??= app(CatalogMetadata::class)->available();
        $fields = $content;
        $grams = null;
        if ($indexed) {
            $query->leftJoin('catalog_revision_metadata as '.$revisionMetadata, function ($join) use ($revision, $revisionMetadata): void {
                $join->on($revisionMetadata.'.id', '=', $revision.'.id')->on($revisionMetadata.'.content_item_id', '=', $revision.'.content_item_id');
            })->leftJoin('catalog_publication_metadata as '.$publicationMetadata, function ($join) use ($pageAlias, $publicationAlias, $publicationMetadata): void {
                $join->on($publicationMetadata.'.id', '=', $publicationAlias.'.id')->on($publicationMetadata.'.site_id', '=', $pageAlias.'.site_id')
                    ->on($publicationMetadata.'.page_id', '=', $pageAlias.'.id');
            });
            $fields = 'CASE WHEN '.$revision.'.id IS NOT NULL THEN COALESCE('.$revisionMetadata.'.fields_json, '.$revision.'.payload_json) ELSE COALESCE('.$publicationMetadata.'.fields_json, '.$frozen.') END';
            $grams = 'CASE WHEN '.$revision.'.id IS NOT NULL THEN COALESCE('.$revisionMetadata.'.search_grams, tram_catalog_grams('.$revision.'.payload_json)) ELSE COALESCE('.$publicationMetadata.'.search_grams, tram_catalog_grams('.$frozen.')) END';
        }

        return ['content' => $content, 'fields' => $fields, 'grams' => $grams, 'language' => '('.$content.")->>'language'",
            'revision_id' => 'COALESCE('.$revision.'.id, '.$publicationAlias.'.content_revision_id)', 'revision_alias' => $revision];
    }
}
