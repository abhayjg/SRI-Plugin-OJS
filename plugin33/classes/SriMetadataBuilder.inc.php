<?php

/**
 * @file plugins/pubIds/sri/classes/SriMetadataBuilder.inc.php
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * Maps OJS 3.3 Submission / Publication / Issue objects into the shared,
 * OJS-free ArticleData DTO used by the SRI\Plugin core.
 */

use SRI\Plugin\ArticleData;
use SRI\Plugin\SuffixGenerator;

class SriMetadataBuilder
{
    /** @var SriPubIdPlugin */
    private $_plugin;

    public function __construct(SriPubIdPlugin $plugin)
    {
        $this->_plugin = $plugin;
    }

    public function fromSubmission($submission)
    {
        $article = new ArticleData();
        $contextId = (int)$submission->getContextId();
        $context = $this->_plugin->getContext($contextId);
        $publication = $submission->getCurrentPublication();

        $article->title = $this->firstNonEmpty(
            $publication ? $this->localized($publication, 'title') : '',
            $this->localized($submission, 'title'),
            'Untitled'
        );

        $article->creators = $this->mapAuthors($publication ? $publication->getAuthors() : []);

        $issue = null;
        if ($publication && $publication->getData('issueId')) {
            $issueDao = DAORegistry::getDAO('IssueDAO');
            $issue = $issueDao->getById($publication->getData('issueId'));
        }

        $article->publicationDate = $this->resolvePublicationDate($publication, $issue);

        $request = Application::getRequest();
        if ($request) {
            $article->targetUrl = $request->url(null, 'article', 'view', $submission->getId());
        }

        $article->abstract = $publication ? trim((string)$this->localized($publication, 'abstract')) : '';
        $article->subjects = $this->mapSubjects($this->keywords($submission));
        $article->language = $this->resolveLanguage($submission, $publication);
        $article->publisher = $this->resolvePublisher($context);
        $article->license = $this->resolveLicense($context, $publication);
        $article->issn = $this->firstNonEmpty(
            $context ? (string)$context->getData('printIssn') : '',
            $context ? (string)$context->getData('onlineIssn') : ''
        );
        $article->volume = $issue ? (string)$issue->getVolume() : '';
        $article->issue = $issue ? (string)$issue->getNumber() : '';
        $article->pages = $publication ? (string)$publication->getData('pages') : '';

        if ($publication) {
            $article->firstPage = $this->firstPage($article->pages);
            if ($this->_plugin->setting($contextId, 'sriIncludeDoi', true)) {
                $doi = $this->companionDoi($publication);
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

        $article->journalInitials = $this->journalInitials($context);
        $article->articleId = (string)$submission->getId();
        $article->year = $this->resolveYear($issue, $publication);

        $suffixMode = $this->_plugin->setting($contextId, 'sriSuffix', SuffixGenerator::MODE_DEFAULT);
        if ($suffixMode === SuffixGenerator::MODE_MANUAL) {
            $publication = $submission->getCurrentPublication();
            $article->manualSuffix = $publication ? (string)$publication->getData('pub-id::sri') : '';
        }

        return $article;
    }

    private function mapAuthors($authors)
    {
        $out = [];
        if (empty($authors)) {
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
            $affiliation = method_exists($author, 'getLocalizedAffiliation') ? (array)$author->getLocalizedAffiliation() : [];
            foreach ($affiliation as $row) {
                if (is_string($row) && trim($row) !== '') {
                    $entry['affiliation'] = trim($row);
                    break;
                }
            }
            if (method_exists($author, 'getEmail')) {
                $email = trim((string)$author->getEmail());
                if ($email !== '') {
                    $entry['email'] = $email;
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    private function authorOrcid($author)
    {
        if (!method_exists($author, 'getOrcid')) {
            return '';
        }
        $orcid = $author->getOrcid();
        if (is_object($orcid) && method_exists($orcid, 'getPath')) {
            return trim((string)$orcid->getPath(), '/');
        }
        if (is_string($orcid)) {
            return trim($orcid, '/');
        }
        return '';
    }

    private function keywords($submission)
    {
        if (method_exists($submission, 'getLocalizedData')) {
            $kw = $submission->getLocalizedData('keywords');
            if (!empty($kw)) {
                return $kw;
            }
        }
        if (method_exists($submission, 'getLocalizedKeywords')) {
            return $submission->getLocalizedKeywords();
        }
        return [];
    }

    private function mapSubjects($keywords)
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

    private function localized($object, $field)
    {
        if (!method_exists($object, 'getLocalizedData')) {
            return '';
        }
        $value = $object->getLocalizedData($field);
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_scalar($value) ? (string)$value : '';
    }

    private function resolvePublicationDate($publication, $issue)
    {
        $date = $publication ? (string)$publication->getData('datePublished') : '';
        $ts = $date !== '' ? strtotime($date) : false;
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        $year = $this->resolveYear($issue, $publication);
        return $year > 0 ? sprintf('%04d-01-01', $year) : date('Y-m-d');
    }

    private function resolveYear($issue, $publication)
    {
        $year = 0;
        if ($publication) {
            $raw = $publication->getData('datePublished');
            if ($raw) {
                $year = (int)date('Y', is_numeric($raw) ? (int)$raw : (strtotime($raw) ?: time()));
            }
        }
        if (!$year && $issue && $issue->getYear()) {
            $year = (int)$issue->getYear();
        }
        if (!$year) {
            $year = (int)date('Y');
        }
        return $year >= 1900 ? $year : (int)date('Y');
    }

    private function resolveLanguage($submission, $publication)
    {
        $lang = $publication ? trim((string)$publication->getData('language')) : '';
        if ($lang === '' && method_exists($submission, 'getLocale')) {
            $lang = trim((string)$submission->getLocale());
        }
        if ($lang === '') {
            $lang = 'en';
        }
        return strlen($lang) > 10 ? substr($lang, 0, 10) : $lang;
    }

    private function resolvePublisher($context)
    {
        $publisher = $this->_plugin->setting($context ? $context->getId() : 0, 'sriFallbackPublisher', '');
        if ($publisher !== '') {
            return $publisher;
        }
        if ($context && method_exists($context, 'getLocalizedName')) {
            return trim((string)$context->getLocalizedName());
        }
        return '';
    }

    private function resolveLicense($context, $publication)
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

    private function companionDoi($publication)
    {
        if (!method_exists($publication, 'getStoredPubId')) {
            return '';
        }
        try {
            $doi = $publication->getStoredPubId('doi');
        } catch (\Throwable $e) {
            $doi = '';
        }
        return $doi !== '' ? trim((string)$doi) : '';
    }

    private function journalInitials($context)
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

    private function slugify($value)
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
        return $value !== '' ? $value : 'jor';
    }

    private function firstPage($pages)
    {
        if (preg_match('/^(\d+)/', trim($pages), $m)) {
            return $m[1];
        }
        return '';
    }

    private function firstNonEmpty(...$values)
    {
        foreach ($values as $v) {
            if (trim((string)$v) !== '') {
                return (string)$v;
            }
        }
        return '';
    }
}
