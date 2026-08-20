<?php

/**
 * @file plugins/pubIds/sri/classes/core/ArticleData.php
 *
 * Normalized container for the article metadata that the plugin maps from OJS
 * into the SRI registration payload.
 *
 * This DTO is deliberately free of any OJS dependency. The per-version plugin
 * adapters are responsible for populating it from OJS Submission / Publication
 * / Issue / Galley objects (see the adapter's MetadataBuilder).
 */

namespace SRI\Plugin;

final class ArticleData
{
    /** @var string Article title (required). */
    public string $title = '';

    /**
     * Ordered list of creators.
     *
     * @var array<int, array{name: string, orcid?: string, affiliation?: string, email?: string}>
     */
    public array $creators = [];

    /** Defaults to JournalArticle — the entire OJS use case. */
    public string $resourceType = 'JournalArticle';

    public string $resourceTypeGeneral = 'Text';

    /** YYYY-MM-DD. */
    public string $publicationDate = '';

    /** Article landing page URL (registered as the SRI resolution target). */
    public string $targetUrl = '';

    public string $abstract = '';

    /**
     * @var array<int, array{subject: string, scheme?: string, schemeUri?: string, valueUri?: string}>
     */
    public array $subjects = [];

    /** RFC 5646-ish language tag, e.g. "en". */
    public string $language = '';

    /** Journal / publisher display name. */
    public string $publisher = '';

    /** SPDX/CC identifier or URI (validated server-side). */
    public string $license = '';

    public string $issn = '';
    public string $volume = '';
    public string $issue = '';
    public string $pages = '';

    /**
     * @var array<int, array{funderName: string, funderIdentifier?: string,
     *    funderIdentifierType?: string, awardNumber?: string}>
     */
    public array $funders = [];

    /**
     * @var array<int, array{relationType: string, relatedIdentifier: string,
     *    identifierType?: string, resourceType?: string}>
     */
    public array $relatedIdentifiers = [];

    // Context used by the suffix generator -------------------------------------------------

    /** Journal initials/acronym (used by the %j token). */
    public string $journalInitials = '';

    /** Submission/article id (used by the %a token). */
    public string $articleId = '';

    /** Galley id (used by the %g token). */
    public string $galleyId = '';

    /** Submission file id (used by the %f token). */
    public string $fileId = '';

    /** First page (used by the %p token). May be a range e.g. "12-20". */
    public string $firstPage = '';

    /** Manual suffix override (when suffix mode = manual or per-article override). */
    public string $manualSuffix = '';

    /** Year used for the SRI (usually the article/issue year). */
    public int $year = 0;

    /** Public non-empty fields, for optional pre-registration or debugging. */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** Convenience: number of pages (used by RIS/output polish). */
    public function pageCount(): int
    {
        if (!$this->pages) {
            return 0;
        }
        if (preg_match('/^\d+$/', $this->pages)) {
            return (int)$this->pages;
        }
        if (preg_match('/^(\d+)-(\d+)$/', $this->pages, $m)) {
            return max(0, (int)$m[2] - (int)$m[1] + 1);
        }
        return 0;
    }
}
