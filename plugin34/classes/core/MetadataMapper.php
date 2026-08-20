<?php

/**
 * @file plugins/pubIds/sri/classes/core/MetadataMapper.php
 *
 * Maps a normalized ArticleData DTO into the SRI registration payload
 * (POST /api/v1/register) and the metadata-update payload (PATCH).
 *
 * Field names and shapes are taken directly from the SRI-Backend OpenAPI
 * RegistrationRequest schema (src/modules/metadata/schemas/metadata-schema.ts),
 * so developer-facing documentation must use these real names — not the
 * invented names from any old scope-of-work draft.
 */

namespace SRI\Plugin;

final class MetadataMapper
{
    /** ResourceType used for every OJS registration (journal article). */
    public const RESOURCE_TYPE_JOURNAL_ARTICLE = 'JournalArticle';

    /**
     * Build the POST /api/v1/register body for a single article.
     *
     * @param ArticleData $article
     * @param int|string  $prefix   Numeric SRI prefix.
     * @param string      $suffix   Computed suffix for this article.
     * @param string|null $year     Optional explicit year (defaults to article year).
     * @param bool        $includeSource Include source=OJS_PLUGIN channel tag.
     */
    public function toRegistrationPayload(
        ArticleData $article,
        int|string $prefix,
        string $suffix,
        ?string $year = null,
        bool $includeSource = true
    ): array {
        $payload = [
            'title' => $article->title,
            'creators' => $this->mapCreators($article->creators),
            'resourceType' => $article->resourceType !== '' ? $article->resourceType : self::RESOURCE_TYPE_JOURNAL_ARTICLE,
            'resourceTypeGeneral' => $article->resourceTypeGeneral,
            'publicationDate' => $this->normalizeDate($article->publicationDate),
            'targetUrl' => $article->targetUrl,
            'suffix' => $suffix,
            'prefix' => (int)$prefix,
        ];

        $this->setIf($payload, 'abstract', $article->abstract);
        $this->setIf($payload, 'language', $article->language);
        $this->setIf($payload, 'publisher', $article->publisher);
        $this->setIf($payload, 'license', $this->normalizeLicense($article->license));
        $this->setIf($payload, 'issn', $article->issn);
        $this->setIf($payload, 'volume', $article->volume);
        $this->setIf($payload, 'issue', $article->issue);
        $this->setIf($payload, 'pages', $article->pages);

        if (!empty($article->subjects)) {
            $payload['subjects'] = $this->mapSubjects($article->subjects);
        }
        if (!empty($article->funders)) {
            $payload['funders'] = $this->mapFunders($article->funders);
        }
        if (!empty($article->relatedIdentifiers)) {
            $payload['relatedIdentifiers'] = $this->mapRelatedIdentifiers($article->relatedIdentifiers);
        }

        $yearValue = $year ?? ($article->year > 0 ? (string)$article->year : null);
        if ($yearValue !== null) {
            $payload['year'] = $yearValue;
        }
        if ($includeSource) {
            $payload['source'] = 'OJS_PLUGIN';
        }

        return $payload;
    }

    /**
     * Extract only the mutable metadata fields for a PATCH
     * (POST /api/v1/metadata/{fullSri} partial update).
     */
    public function toUpdatePayload(ArticleData $article): array
    {
        $payload = [
            'title' => $article->title,
            'creators' => $this->mapCreators($article->creators),
            'publicationDate' => $this->normalizeDate($article->publicationDate),
            'targetUrl' => $article->targetUrl,
            'resourceType' => $article->resourceType !== '' ? $article->resourceType : self::RESOURCE_TYPE_JOURNAL_ARTICLE,
        ];

        $this->setIf($payload, 'abstract', $article->abstract);
        $this->setIf($payload, 'language', $article->language);
        $this->setIf($payload, 'publisher', $article->publisher);
        $this->setIf($payload, 'license', $this->normalizeLicense($article->license));
        $this->setIf($payload, 'issn', $article->issn);
        $this->setIf($payload, 'volume', $article->volume);
        $this->setIf($payload, 'issue', $article->issue);
        $this->setIf($payload, 'pages', $article->pages);
        $this->setIf($payload, 'resourceTypeGeneral', $article->resourceTypeGeneral);

        if (!empty($article->subjects)) {
            $payload['subjects'] = $this->mapSubjects($article->subjects);
        }
        if (!empty($article->funders)) {
            $payload['funders'] = $this->mapFunders($article->funders);
        }
        if (!empty($article->relatedIdentifiers)) {
            $payload['relatedIdentifiers'] = $this->mapRelatedIdentifiers($article->relatedIdentifiers);
        }

        return $payload;
    }

    /**
     * Build a CSV row (header + one row) for bulk registration.
     * Uses only the columns the backend bulk parser understands.
     *
     * @return array<int, string> CSV header columns.
     */
    public function bulkCsvColumns(): array
    {
        return [
            'title', 'creators', 'resourceType', 'publicationDate', 'targetUrl',
            'suffix', 'year', 'prefix', 'language', 'license', 'abstract',
            'publisher', 'version', 'resourceTypeGeneral', 'issn', 'volume',
            'issue', 'pages', 'subjects',
        ];
    }

    /**
     * Convert a single article into a bulk CSV row (associative: column => value).
     */
    public function toBulkRow(ArticleData $article, int|string $prefix, string $suffix): array
    {
        $year = $article->year > 0 ? (string)$article->year : '';
        $creators = implode('; ', array_map(static fn (array $c) => $c['name'], $this->mapCreators($article->creators)));
        $subjects = implode('; ', array_map(static fn (array $s) => $s['subject'], $this->mapSubjects($article->subjects)));

        return [
            'title' => $article->title,
            'creators' => $creators,
            'resourceType' => $article->resourceType !== '' ? $article->resourceType : self::RESOURCE_TYPE_JOURNAL_ARTICLE,
            'publicationDate' => $this->normalizeDate($article->publicationDate),
            'targetUrl' => $article->targetUrl,
            'suffix' => $suffix,
            'year' => $year,
            'prefix' => (string)(int)$prefix,
            'language' => $article->language,
            'license' => $this->normalizeLicense($article->license),
            'abstract' => $article->abstract,
            'publisher' => $article->publisher,
            'version' => '',
            'resourceTypeGeneral' => $article->resourceTypeGeneral,
            'issn' => $article->issn,
            'volume' => $article->volume,
            'issue' => $article->issue,
            'pages' => $article->pages,
            'subjects' => $subjects,
        ];
    }

    /**
     * Render bulk rows to CSV.
     *
     * @param array<int, array<string, string>> $rows
     */
    public function toCsv(array $rows): string
    {
        $columns = $this->bulkCsvColumns();
        $write = static function (array $row, array $columns, bool $withHeader): string {
            $handle = fopen('php://temp', 'r+');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open in-memory CSV stream.');
            }
            if ($withHeader) {
                fputcsv($handle, $columns, ',', '"', '');
            }
            foreach ($row as $r) {
                $line = [];
                foreach ($columns as $c) {
                    $line[] = $r[$c] ?? '';
                }
                fputcsv($handle, $line, ',', '"', '');
            }
            rewind($handle);
            $out = stream_get_contents($handle);
            fclose($handle);
            return $out === false ? '' : $out;
        };

        $streams = [];
        if (count($rows) > 0) {
            $streams[] = $write([$rows[0]], $columns, true);
        }
        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $streams[] = $write([$rows[$i]], $columns, false);
        }
        return implode('', $streams);
    }

    /**
     * @return array<int, array{name: string, orcid?: string, affiliation?: string, email?: string}>
     */
    private function mapCreators(array $creators): array
    {
        $out = [];
        foreach (array_values($creators) as $c) {
            $name = trim((string)($c['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $item = ['name' => mb_substr($name, 0, 500)];
            foreach (['orcid', 'affiliation', 'email'] as $field) {
                $value = trim((string)($c[$field] ?? ''));
                if ($value !== '') {
                    $item[$field] = $value;
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @return array<int, array{subject: string, scheme?: string, schemeUri?: string, valueUri?: string}>
     */
    private function mapSubjects(array $subjects): array
    {
        $out = [];
        foreach (array_values($subjects) as $s) {
            $subject = trim((string)($s['subject'] ?? ''));
            if ($subject === '') {
                continue;
            }
            $item = ['subject' => mb_substr($subject, 0, 500)];
            foreach (['scheme', 'schemeUri', 'valueUri'] as $field) {
                $value = trim((string)($s[$field] ?? ''));
                if ($value !== '') {
                    $item[$field] = $value;
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @return array<int, array{funderName: string, funderIdentifier?: string,
     *    funderIdentifierType?: string, awardNumber?: string}>
     */
    private function mapFunders(array $funders): array
    {
        $out = [];
        foreach ($funders as $f) {
            $name = trim((string)($f['funderName'] ?? ''));
            if ($name === '') {
                continue;
            }
            $item = ['funderName' => mb_substr($name, 0, 500)];
            foreach (['funderIdentifier', 'funderIdentifierType', 'awardNumber'] as $field) {
                $value = trim((string)($f[$field] ?? ''));
                if ($value !== '') {
                    $item[$field] = $value;
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @return array<int, array{relationType: string, relatedIdentifier: string,
     *    identifierType?: string, resourceType?: string}>
     */
    private function mapRelatedIdentifiers(array $related): array
    {
        $allowedRelation = [
            'Cites', 'IsCitedBy', 'IsPartOf', 'HasPart', 'IsVersionOf',
            'IsVersionedBy', 'IsIdenticalTo', 'IsDerivedFrom', 'IsSourceOf',
            'IsSupplementTo', 'IsSupplementedBy', 'IsReferencedBy', 'References',
            'IsNewVersionOf', 'IsPreviousVersionOf',
        ];
        $out = [];
        foreach ($related as $r) {
            $id = trim((string)($r['relatedIdentifier'] ?? ''));
            if ($id === '') {
                continue;
            }
            $relationType = (string)($r['relationType'] ?? '');
            if ($relationType === '' || !in_array($relationType, $allowedRelation, true)) {
                $relationType = 'References';
            }
            $item = [
                'relationType' => $relationType,
                'relatedIdentifier' => mb_substr($id, 0, 500),
            ];
            foreach (['identifierType', 'resourceType'] as $field) {
                $value = trim((string)($r[$field] ?? ''));
                if ($value !== '') {
                    $item[$field] = $value;
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Normalize a license to SPDX/CC identifier or URI, or empty.
     * Creative Commons license URLs are folded into the canonical
     * CC-{type}-{version} identifier (e.g. CC-BY-4.0). Everything else that is
     * not clearly a SPDX/CC/OSI identifier is left untouched so the backend
     * license validator (which accepts SPDX/CC/ODC ids and http(s) URIs) can
     * make the final call.
     */
    private function normalizeLicense(string $license): string
    {
        $license = trim($license);
        if ($license === '') {
            return '';
        }
        $cc = preg_match(
            '#^https?://creativecommons\.org/licenses/([a-z\-\d]+)/([\d.]+)/?$#i',
            $license,
            $m
        );
        if ($cc) {
            return 'CC-' . strtoupper((string)$m[1]) . '-' . $m[2];
        }
        $osd = preg_match('#^https?://opensource\.org/licenses/([A-Za-z0-9\-.]+?)/?(\.html)?$#i', $license, $m);
        if ($osd) {
            return strtoupper((string)$m[1]);
        }
        $gnu = preg_match('#^https?://www\.gnu\.org/licenses/([A-Za-z0-9\-.]+?)\.html$#i', $license, $m);
        if ($gnu) {
            return 'GNU-' . strtoupper((string)$m[1]);
        }
        return mb_substr($license, 0, 500);
    }

    /**
     * Normalize a date to YYYY-MM-DD, falling back to today.
     */
    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        $ts = strtotime($date);
        if ($date === '' || $ts === false) {
            return date('Y-m-d');
        }
        return date('Y-m-d', $ts);
    }

    private function setIf(array &$payload, string $key, string $value): void
    {
        if ($value !== '') {
            $payload[$key] = $value;
        }
    }
}
