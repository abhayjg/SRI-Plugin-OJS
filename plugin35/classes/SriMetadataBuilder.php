<?php

/**
 * @file plugins/pubIds/sri/classes/SriMetadataBuilder.php
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * Maps OJS Submission / Publication / Issue objects into the shared,
 * OJS-free ArticleData DTO used by the SRI\Plugin core.
 *
 * This is the OJS 3.5 variant (namespaced objects, Repo facade).
 */

namespace APP\plugins\pubIds\sri\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\pubIds\sri\SriPubIdPlugin;
use APP\submission\Submission;
use SRI\Plugin\ArticleData;
use SRI\Plugin\SuffixGenerator;

final class SriMetadataBuilder
{
    private SriPubIdPlugin $plugin;

    public function __construct(SriPubIdPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Build ArticleData for a submission's current publication.
     */
    public function fromSubmission(Submission $submission): ArticleData
    {
        $article = new ArticleData();
        $contextId = (int)$submission->getData('contextId');
        $context = $this->plugin->getContext($contextId);
        $publication = $submission->getCurrentPublication();

        $article->title = $this->firstNonEmpty(
            $publication ? $this->localized($publication, 'title') : '',
            $this->localized($submission, 'title'),
            'Untitled'
        );

        $article->creators = $this->mapAuthors($publication ? $publication->getData('authors') : []);

        $issue = null;
        if ($publication && $publication->getData('issueId')) {
            $issue = Repo::issue()->get($publication->getData('issueId'));
        }

        $article->publicationDate = $this->resolvePublicationDate($publication, $issue);

        $request = Application::get()->getRequest();
        if ($request) {
            $dispatcher = $request->getDispatcher();
            $contextPath = $context ? $context->getPath() : null;
            $article->targetUrl = $dispatcher->url(
                $request,
                \PKP\core\PKPApplication::ROUTE_PAGE,
                $contextPath,
                'article',
                'view',
                [$submission->getId()]
            );
        }

        $article->abstract = $publication ? trim((string)$this->localized($publication, 'abstract')) : '';
        $article->subjects = $this->mapSubjects($submission->getLocalizedData('keywords'));
        $article->language = $this->resolveLanguage($submission, $publication, $context);
        $article->publisher = $this->resolvePublisher($context);
        $article->license = $this->resolveLicense($context, $publication);
        $article->issn = $this->firstNonEmpty(
            $context ? (string)$context->getData('printIssn') : '',
            $context ? (string)$context->getData('onlineIssn') : ''
        );
        $article->volume = $issue ? (string)$issue->getData('volume') : '';
        $article->issue = $issue ? (string)$issue->getData('number') : '';
        $article->pages = $publication ? (string)$publication->getData('pages') : '';

        if ($publication) {
            $article->firstPage = $this->firstPage($article->pages);
            if ($this->plugin->setting($contextId, 'sriIncludeDoi', true)) {
                $doi = $this->resolveCompanionDoi($publication);
                if ($doi !== '') {
                    $article->relatedIdentifiers[] = [
                        'relationType' => 'IsIdenticalTo',
                        'relatedIdentifier' => $doi,
                        'identifierType' => 'DOI',
                        'resourceType' => 'JournalArticle',
                    ];
                }
            }
        }

        // Suffix context
        $article->journalInitials = $this->journalInitials($context);
        $article->articleId = (string)$submission->getId();
        $article->year = $this->resolveYear($issue, $publication);

        $suffixMode = $this->plugin->setting($contextId, 'sriSuffix', SuffixGenerator::MODE_DEFAULT);
        if ($suffixMode === SuffixGenerator::MODE_MANUAL) {
            $publication = $submission->getCurrentPublication();
            $article->manualSuffix = $publication ? (string)$publication->getData('pub-id::sri') : '';
        }

        return $article;
    }

    /**
     * Fill manual suffix for a specifically named suffix field.
     */
    public function withManualSuffix(ArticleData $article, string $suffix): ArticleData
    {
        $article->manualSuffix = $suffix;
        return $article;
    }

    //
    // Private mapping helpers ----------------------------------------------------
    //

    private function mapAuthors(?iterable $authors): array
    {
        $out = [];
        if (!$authors) {
            return $out;
        }
        foreach ($authors as $author) {
            if (!method_exists($author, 'getFullName')) {
                continue;
            }
            $name = trim((string)$author->getFullName());
            if ($name === '') {
                continue;
            }
            $entry = ['name' => $name];
            $orcid = $this->authorOrcid($author);
            if ($orcid !== '') {
                $entry['orcid'] = $orcid;
            }
            $affiliation = $this->authorAffiliation($author);
            if ($affiliation !== '') {
                $entry['affiliation'] = $affiliation;
            }
            $email = method_exists($author, 'getEmail') ? trim((string)$author->getEmail()) : '';
            if ($email !== '') {
                $entry['email'] = $email;
            }
            $out[] = $entry;
        }
        return $out;
    }

    private function authorOrcid($author): string
    {
        if (method_exists($author, 'getOrcid')) {
            try {
                $orcid = $author->getOrcid();
                if (is_object($orcid) && method_exists($orcid, 'getPath')) {
                    return trim((string)$orcid->getPath(), '/');
                }
                if (is_string($orcid) && $orcid !== '') {
                    return trim($orcid, '/');
                }
            } catch (\Throwable $e) {
                // ignore and fall through
            }
        }
        $raw = method_exists($author, 'getData') ? (string)$author->getData('orcid') : '';
        return trim($raw, '/');
    }

    private function authorAffiliation($author): string
    {
        if (method_exists($author, 'getLocalizedAffiliation')) {
            $aff = (array)$author->getLocalizedAffiliation();
            foreach ($aff as $row) {
                if (is_string($row) && trim($row) !== '') {
                    return trim($row);
                }
            }
        }
        if (method_exists($author, 'getData')) {
            $raw = $author->getData('affiliation');
            if (is_array($raw)) {
                foreach ($raw as $v) {
                    if (is_string($v) && trim($v) !== '') {
                        return trim($v);
                    }
                }
            } elseif (is_string($raw) && trim($raw) !== '') {
                return trim($raw);
            }
        }
        return '';
    }

    private function mapSubjects($keywords): array
    {
        $out = [];
        if (empty($keywords)) {
            return $out;
        }
        foreach ((array)$keywords as $k => $v) {
            $subject = is_string($v) ? $v : (is_scalar($k) ? (string)$k : '');
            $subject = trim($subject);
            if ($subject !== '') {
                $out[] = ['subject' => mb_substr($subject, 0, 500)];
            }
        }
        return $out;
    }

    private function localized($object, string $field): string
    {
        if (!method_exists($object, 'getLocalizedData')) {
            if (method_exists($object, 'getData')) {
                $raw = $object->getData($field);
                if (is_array($raw)) {
                    $raw = reset($raw);
                }
                return is_scalar($raw) ? (string)$raw : '';
            }
            return '';
        }
        $value = $object->getLocalizedData($field);
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_scalar($value) ? (string)$value : '';
    }

    private function resolvePublicationDate($publication, $issue): string
    {
        $date = '';
        if ($publication && method_exists($publication, 'getData')) {
            $date = (string)$publication->getData('datePublished');
        }
        $ts = $date !== '' ? strtotime($date) : false;
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        $year = $this->resolveYear($issue, $publication);
        return $year > 0 ? sprintf('%04d-01-01', $year) : date('Y-m-d');
    }

    private function resolveYear($issue, $publication): int
    {
        $year = 0;
        if ($publication && method_exists($publication, 'getData')) {
            $raw = $publication->getData('datePublished');
            if ($raw) {
                $year = (int)date('Y', is_numeric($raw) ? (int)$raw : (strtotime($raw) ?: time()));
            }
        }
        if (!$year && $issue && method_exists($issue, 'getData') && $issue->getData('year')) {
            $year = (int)$issue->getData('year');
        }
        if (!$year) {
            $year = (int)date('Y');
        }
        return $year >= 1900 ? $year : (int)date('Y');
    }

    private function resolveLanguage($submission, $publication, $context): string
    {
        $lang = '';
        if ($publication && method_exists($publication, 'getData')) {
            $lang = trim((string)$publication->getData('language'));
        }
        if ($lang === '' && method_exists($submission, 'getLocale')) {
            $lang = trim((string)$submission->getLocale());
        }
        if ($lang === '') {
            $lang = 'en';
        }
        if (strlen($lang) > 10) {
            $lang = substr($lang, 0, 10);
        }
        return $lang;
    }

    private function resolvePublisher($context): string
    {
        $publisher = $this->plugin->setting($context ? $context->getId() : 0, 'sriFallbackPublisher', '');
        if ($publisher !== '') {
            return $publisher;
        }
        if ($context && method_exists($context, 'getLocalizedName')) {
            return trim((string)$context->getLocalizedName());
        }
        return '';
    }

    private function resolveLicense($context, $publication): string
    {
        $licenseUrl = '';
        if ($publication && method_exists($publication, 'getData')) {
            $licenseUrl = (string)$publication->getData('licenseUrl');
        }
        if ($licenseUrl === '' && $context && method_exists($context, 'getData')) {
            $licenseUrl = (string)$context->getData('licenseUrl');
        }
        return trim($licenseUrl);
    }

    private function resolveCompanionDoi($publication): string
    {
        if (!method_exists($publication, 'getStoredPubId')) {
            return '';
        }
        try {
            $doi = $publication->getStoredPubId('doi');
        } catch (\Throwable $e) {
            $doi = '';
        }
        if ($doi !== '') {
            return trim((string)$doi);
        }
        if (method_exists($publication, 'getDoi')) {
            try {
                $doiObj = $publication->getDoi();
                if (is_object($doiObj) && method_exists($doiObj, 'getData')) {
                    return trim((string)$doiObj->getData('doi'));
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return '';
    }

    private function journalInitials($context): string
    {
        if (!$context) {
            return '';
        }
        if (method_exists($context, 'getLocalizedData')) {
            $acronym = trim((string)$context->getLocalizedData('acronym'));
            if ($acronym !== '') {
                return $this->slugify($acronym);
            }
        }
        $name = method_exists($context, 'getLocalizedName') ? (string)$context->getLocalizedName() : '';
        $letters = '';
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $word) {
            if ($word !== '') {
                $letters .= mb_substr($word, 0, 1);
            }
        }
        return $this->slugify($letters !== '' ? $letters : 'jor');
    }

    private function slugify(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
        return $value !== '' ? $value : 'jor';
    }

    private function firstPage(string $pages): string
    {
        if (preg_match('/^(\d+)/', trim($pages), $m)) {
            return $m[1];
        }
        return '';
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $v) {
            if (trim($v) !== '') {
                return $v;
            }
        }
        return '';
    }
}
