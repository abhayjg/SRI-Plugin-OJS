<?php

/**
 * @file plugins/pubIds/sri/classes/core/SuffixGenerator.php
 *
 * SRI suffix generation — three configurable modes, mirroring OJS's own DOI
 * suffix system (the same token vocabulary journals already know from
 * configuring DOI):
 *
 *   default : %j.%a   (journal initials + article id)  — deliberately the
 *                     simpler pattern, not the legacy volume/issue-dependent one
 *   pattern : a custom token pattern (%j %v %i %Y %y %a %g %f %p %x)
 *   manual  : a per-article suffix entered by an editor
 *
 * Whatever the plugin computes is sent as the existing `suffix` field of
 * POST /api/v1/register, exactly as a human would supply it today. Uniqueness
 * of (prefix, suffix, year) is enforced by the backend; a 409 duplicate is
 * retried here with a disambiguator (see RegistrationService::registerWithRetry).
 */

namespace SRI\Plugin;

final class SuffixGenerator
{
    public const MODE_DEFAULT = 'default';
    public const MODE_PATTERN = 'pattern';
    public const MODE_MANUAL = 'manual';

    /** Matches the backend's allowed suffix charset plus its start rule. */
    private const SUFFIX_REGEX = '/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/';

    public const DEFAULT_PATTERN = '%j.%a';

    /**
     * Generate (or return) a suffix for the given article and mode.
     *
     * @param ArticleData $article  Article context (tokens resolve against it).
     * @param string      $mode     self::MODE_DEFAULT | self::MODE_PATTERN | self::MODE_MANUAL
     * @param string      $pattern  The custom pattern (used when mode = pattern).
     *
     * @return string Sanitized, backend-valid suffix (already charset-safe).
     */
    public function generate(ArticleData $article, string $mode, string $pattern = ''): string
    {
        if ($article->manualSuffix !== '') {
            // A per-article manual override always wins, even in pattern modes.
            return $this->sanitize($article->manualSuffix, $article->articleId);
        }

        $raw = match ($mode) {
            self::MODE_PATTERN => $this->resolve($pattern, $article),
            self::MODE_DEFAULT => $this->resolve(self::DEFAULT_PATTERN, $article),
            self::MODE_MANUAL => $article->manualSuffix,
            default => $this->resolve(self::DEFAULT_PATTERN, $article),
        };

        return $this->sanitize($raw, $article->articleId);
    }

    /**
     * Resolve a token pattern against article context.
     *
     * Supported tokens (same vocabulary as OJS DOI suffix patterns):
     *   %j journal initials/acronym
     *   %v volume     %i issue
     *   %Y 4-digit year   %y 2-digit year
     *   %a article id      %g galley id   %f file id
     *   %p first page      %x article id (alias of %a)
     *   %% literal percent
     */
    public function resolve(string $pattern, ArticleData $article): string
    {
        $year = $article->year > 0 ? $article->year : (int)date('Y');
        $map = [
            '%%' => '%',
            '%j' => $article->journalInitials,
            '%v' => $article->volume,
            '%i' => $article->issue,
            '%Y' => (string)$year,
            '%y' => str_pad((string)($year % 100), 2, '0', STR_PAD_LEFT),
            '%a' => $article->articleId,
            '%x' => $article->articleId,
            '%g' => $article->galleyId,
            '%f' => $article->fileId,
            '%p' => $article->firstPage,
        ];

        // Single left-to-right pass: %% is matched first so it is never confused
        // with a lone % prefix, and every %X token resolves in one sweep.
        return (string)preg_replace_callback(
            '/%%|%[jviYyagfpx]/',
            static function (array $m) use ($map): string {
                return $map[$m[0]] ?? $m[0];
            },
            $pattern
        );
    }

    /**
     * Make a suffix safe for the backend: allowed charset [a-zA-Z0-9._:-],
     * must start alphanumeric, no trailing dot, max 100 chars.
     *
     * Falls back to "a{articleId}" when the resolved/typed value would be empty,
     * guaranteeing a deterministic non-empty suffix.
     */
    public function sanitize(string $value, string $articleId = ''): string
    {
        // Only keep valid chars, collapse internal dot runs.
        $clean = preg_replace('/[^a-zA-Z0-9._:-]/', '', $value ?? '') ?? '';
        $clean = preg_replace('/\.{2,}/', '.', $clean) ?? '';
        $clean = trim($clean, '.');
        $clean = ltrim($clean, '_-:');

        if ($clean === '' || !preg_match('/^[a-zA-Z0-9]/', $clean)) {
            $base = $articleId !== '' ? $articleId : (string)time();
            $clean = 'a' . $base;
        }

        if (strlen($clean) > 100) {
            $clean = substr($clean, 0, 100);
        }

        // Guarantee the final character isn't a dot/separator.
        $clean = rtrim($clean, '._:-');
        if ($clean === '') {
            $clean = 'a' . ($articleId !== '' ? $articleId : (string)time());
        }

        return $clean;
    }

    /**
     * Fast validity check against the backend's suffix rules.
     */
    public function isValid(string $suffix): bool
    {
        return strlen($suffix) > 0
            && strlen($suffix) <= 100
            && preg_match(self::SUFFIX_REGEX, $suffix) === 1
            && !str_contains($suffix, '..')
            && !str_ends_with($suffix, '.');
    }

    /**
     * Build a disambiguated candidate for a 409 duplicate-suffix retry
     * (attempt 1 -> "suffix-2", attempt 2 -> "suffix-3", ...). Kept inside the
     * allowed charset by construction.
     */
    public function disambiguate(string $suffix, int $attempt): string
    {
        $n = $attempt + 1; // first retry appends "-2"
        $candidate = $suffix . '-' . $n;
        if (strlen($candidate) > 100) {
            $candidate = substr($suffix, 0, 100 - strlen((string)$n) - 1) . '-' . $n;
        }
        return $candidate;
    }
}
