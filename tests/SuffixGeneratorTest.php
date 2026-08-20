<?php

/**
 * @file tests/SuffixGeneratorTest.php
 *
 * Exercises the three suffix modes, token resolution, sanitization and
 * disambiguation.
 */

class SuffixGeneratorTest extends SriTestCase
{
    public function run(): array
    {
        $gen = new \SRI\Plugin\SuffixGenerator();

        $article = new \SRI\Plugin\ArticleData();
        $article->journalInitials = 'JOR';
        $article->articleId = '42';
        $article->volume = '5';
        $article->issue = '2';
        $article->year = 2026;
        $article->firstPage = '12';

        // Default mode: %j.%a
        $suffix = $gen->generate($article, \SRI\Plugin\SuffixGenerator::MODE_DEFAULT);
        $this->same('JOR.42', $suffix, 'default pattern %j.%a (case preserved)');
        $this->isTrue($gen->isValid($suffix), 'default suffix is backend-valid');

        // Pattern mode with full token set
        $suffix = $gen->generate($article, \SRI\Plugin\SuffixGenerator::MODE_PATTERN, '%j-%v-%i-%Y-%a-%p');
        $this->same('JOR-5-2-2026-42-12', $suffix, 'custom pattern tokens resolve');
        $this->isTrue($gen->isValid($suffix), 'custom suffix is backend-valid');

        // 2-digit year + %% escape (%% -> literal %, then sanitized out of a
        // backend-valid suffix since % is not in the allowed charset)
        $suffix = $gen->generate($article, \SRI\Plugin\SuffixGenerator::MODE_PATTERN, '%y-%%-%j');
        $this->same('26--JOR', $suffix, '%y token + %% literal is sanitized out');

        // Manual mode
        $article->manualSuffix = 'my.article-1';
        $this->same('my.article-1', $gen->generate($article, \SRI\Plugin\SuffixGenerator::MODE_MANUAL), 'manual suffix passthrough');
        // Per-article manual override wins even in pattern mode
        $this->same('my.article-1', $gen->generate($article, \SRI\Plugin\SuffixGenerator::MODE_PATTERN, '%j.%a'), 'manual override wins in pattern mode');

        // Sanitization: illegal chars stripped, leading separators trimmed
        $this->same('abc123', $gen->sanitize('a b c!@#$%^&*()123', ''), 'sanitize strips illegal chars');
        $this->same('jor.5.2', $gen->sanitize('..jor..5..2..', ''), 'sanitize trims/collapses dots');
        $this->isTrue($gen->isValid($gen->sanitize('!!--__:::', '55')), 'sanitize always yields valid suffix');

        // Empty resolved value falls back to a deterministic "a{articleId}"
        $empty = new \SRI\Plugin\ArticleData();
        $empty->articleId = '7';
        $this->same('a7', $gen->generate($empty, \SRI\Plugin\SuffixGenerator::MODE_PATTERN, '%v%i%p'), 'empty pattern falls back to a{articleId}');

        // Disambiguation
        $this->same('jor.42-2', $gen->disambiguate('jor.42', 1), 'first retry appends -2');
        $this->same('jor.42-3', $gen->disambiguate('jor.42', 2), 'second retry appends -3');
        $this->isTrue($gen->isValid($gen->disambiguate('jor.42', 1)), 'disambiguated suffix is valid');

        return $this->result();
    }
}
