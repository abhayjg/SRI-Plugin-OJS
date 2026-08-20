<?php

/**
 * @file tests/MetadataMapperTest.php
 *
 * Verifies the registration/update/bulk payload shapes against the
 * SRI-Backend RegistrationRequest contract.
 */

class MetadataMapperTest extends SriTestCase
{
    public function run(): array
    {
        $mapper = new \SRI\Plugin\MetadataMapper();

        $article = new \SRI\Plugin\ArticleData();
        $article->title = 'A Study on SRI';
        $article->creators = [
            ['name' => 'Jane Doe', 'orcid' => '0000-0001-2345-6789', 'affiliation' => 'Sci U', 'email' => 'jane@example.com'],
            ['name' => 'John Smith'],
            ['name' => '', 'orcid' => '0000-0001-1111-1111'],
        ];
        $article->publicationDate = '2026-03-15';
        $article->targetUrl = 'https://journal.example.org/article/view/42';
        $article->abstract = 'Abstract text here';
        $article->subjects = [['subject' => 'Science'], ['subject' => 'Peer Review', 'scheme' => 'keyword']];
        $article->language = 'en';
        $article->publisher = 'Sci Journal';
        $article->license = 'https://creativecommons.org/licenses/by/4.0/';
        $article->issn = '1234-5678';
        $article->volume = '5';
        $article->issue = '2';
        $article->pages = '12-18';
        $article->relatedIdentifiers = [
            ['relationType' => 'IsIdenticalTo', 'relatedIdentifier' => '10.1000/xyz', 'identifierType' => 'DOI'],
        ];
        $article->funders = [['funderName' => 'NSF', 'awardNumber' => '123']];
        $article->year = 2026;

        $payload = $mapper->toRegistrationPayload($article, 1001, 'jor.42', null, true);

        $this->same('A Study on SRI', $payload['title'], 'title');
        $this->same('2026-03-15', $payload['publicationDate'], 'publicationDate');
        $this->same('jor.42', $payload['suffix'], 'suffix');
        $this->same(1001, $payload['prefix'], 'prefix numeric');
        $this->same('2026', $payload['year'], 'year from article');
        $this->same('JournalArticle', $payload['resourceType'], 'resourceType default');
        $this->same('OJS_PLUGIN', $payload['source'], 'source tag');
        $this->same(2, count($payload['creators']), 'blank creator filtered (name required)');
        $this->same('Jane Doe', $payload['creators'][0]['name'], 'creator name');
        $this->same('0000-0001-2345-6789', $payload['creators'][0]['orcid'], 'creator orcid');
        $this->same('1234-5678', $payload['issn'], 'issn');
        $this->same('12-18', $payload['pages'], 'pages');
        $this->same('IsIdenticalTo', $payload['relatedIdentifiers'][0]['relationType'], 'relationType');
        $this->same('DOI', $payload['relatedIdentifiers'][0]['identifierType'], 'identifierType');
        $this->same('NSF', $payload['funders'][0]['funderName'], 'funder');

        // CC URI license normalized to an SPDX-ish identifier
        $this->same('CC-BY-4.0', $payload['license'], 'license normalized from CC URI');

        // Update payload shape
        $patch = $mapper->toUpdatePayload($article);
        $this->same('A Study on SRI', $patch['title'], 'patch title');
        $this->arrayHas($patch, 'creators', 'patch has creators');
        $this->arrayHas($patch, 'issn', 'patch has issn');
        $this->same(false, array_key_exists('suffix', $patch), 'patch excludes suffix');
        $this->same(false, array_key_exists('prefix', $patch), 'patch excludes prefix');
        $this->same(false, array_key_exists('source', $patch), 'patch excludes source');

        // Bulk row + CSV
        $row = $mapper->toBulkRow($article, 1001, 'jor.42');
        $this->same('Jane Doe; John Smith', $row['creators'], 'bulk creators column');
        $this->same('Science; Peer Review', $row['subjects'], 'bulk subjects column');
        $this->same('2026', $row['year'], 'bulk year');

        $csv = $mapper->toCsv([$row]);
        $this->ok(str_contains($csv, 'title,creators'), 'csv header includes title');
        $this->ok(str_contains($csv, 'jor.42'), 'csv row includes suffix');
        $this->same(2, substr_count($csv, "\n"), 'csv yields header + one row');

        // Bulk batch empty -> empty CSV (no throw)
        $this->same('', $mapper->toCsv([]), 'empty bulk rows -> empty csv');

        return $this->result();
    }

    private function arrayHas(array $arr, string $key, string $label): void
    {
        $this->ok(array_key_exists($key, $arr), "{$label} (key {$key})");
    }
}
