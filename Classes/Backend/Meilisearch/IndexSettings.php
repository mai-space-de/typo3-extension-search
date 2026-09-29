<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Meilisearch;

/**
 * Ranking / matching settings applied at provision time so Meilisearch
 * behaves closer to a title-first, stopword-aware search instead of the
 * SDK defaults (searchableAttributes = *, no stopwords).
 */
final class IndexSettings
{
    /**
     * @var list<string>
     */
    public const RANKING_RULES = [
        'words',
        'typo',
        'proximity',
        'attribute',
        'sort',
        'exactness',
    ];

    /**
     * @var list<string>
     */
    public const DOCUMENT_SEARCHABLE_ATTRIBUTES = ['title', 'content'];

    /**
     * @var list<string>
     */
    public const CHUNK_SEARCHABLE_ATTRIBUTES = ['parentTitle', 'content'];

    /**
     * @return array<string, mixed>
     */
    public static function forDocuments(?string $languageCode): array
    {
        return [
            'searchableAttributes' => self::DOCUMENT_SEARCHABLE_ATTRIBUTES,
            'rankingRules' => self::RANKING_RULES,
            'stopWords' => self::stopWordsFor($languageCode),
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => [
                    'oneTypo' => 4,
                    'twoTypos' => 8,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forChunks(?string $languageCode): array
    {
        return [
            'searchableAttributes' => self::CHUNK_SEARCHABLE_ATTRIBUTES,
            'rankingRules' => self::RANKING_RULES,
            'stopWords' => self::stopWordsFor($languageCode),
        ];
    }

    /**
     * Remove language stopwords from a keyword query, case-insensitively.
     *
     * Meilisearch 1.9 only drops exact stopword tokens ("was", not "Was"),
     * so question words still participate in matchingStrategy=last and hide
     * documents that match the remaining content terms.
     */
    public static function keywordQuery(string $query, ?string $languageCode): string
    {
        $stopWords = [];
        foreach (self::stopWordsFor($languageCode) as $stopWord) {
            $stopWords[mb_strtolower($stopWord, 'UTF-8')] = true;
        }

        $tokens = preg_split('/\s+/u', trim($query));
        if ($tokens === false) {
            return $query;
        }

        $kept = [];
        foreach ($tokens as $token) {
            $normalized = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $token), 'UTF-8');
            if ($normalized === '' || isset($stopWords[$normalized])) {
                continue;
            }
            $kept[] = $token;
        }

        if ($kept === []) {
            return $query;
        }

        return implode(' ', $kept);
    }

    /**
     * @return list<string>
     */
    public static function stopWordsFor(?string $languageCode): array
    {
        return match (self::normalizeLanguage($languageCode)) {
            'en' => [
                'the', 'a', 'an', 'and', 'or', 'in', 'on', 'at', 'to', 'of', 'for', 'is', 'are',
                'what', 'who', 'where', 'how', 'why', 'which',
            ],
            'uk' => ['і', 'та', 'в', 'у', 'на', 'з', 'до', 'для'],
            'ar' => ['في', 'من', 'على', 'إلى', 'و'],
            default => [
                'der', 'die', 'das', 'den', 'dem', 'des',
                'ein', 'eine', 'einer', 'eines', 'einem', 'einen',
                'und', 'oder', 'in', 'im', 'an', 'am', 'auf', 'aus',
                'bei', 'mit', 'von', 'zu', 'zum', 'zur', 'für',
                'ist', 'sind',
                'was', 'wie', 'wo', 'wer', 'wen', 'wem', 'wieso', 'warum',
                'welche', 'welcher', 'welches', 'weshalb',
            ],
        };
    }

    private static function normalizeLanguage(?string $languageCode): string
    {
        if ($languageCode === null || $languageCode === '') {
            return 'de';
        }

        $normalized = strtolower(str_replace('_', '-', $languageCode));

        return explode('-', $normalized)[0];
    }
}
