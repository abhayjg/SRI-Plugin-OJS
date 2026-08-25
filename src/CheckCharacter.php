<?php

/**
 * @file plugins/pubIds/sri/classes/core/CheckCharacter.php
 *
 * SRI identifier check-character computation.
 *
 * Mirrors the SRI-Backend implementation exactly
 * (src/modules/identifiers/utils/checksum.ts): a Luhn-variant mod-36 checksum
 * over the alphabet "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ".
 *
 * SRI identifier shape:
 *
 *   sri:{year}.{prefix}.{suffix}+{CHECKCHAR}
 *
 * The plugin computes the full SRI locally (so editors can preview it before
 * registration) and cross-checks it against the SRI returned by the API.
 */

namespace SRI\Plugin;

final class CheckCharacter
{
    /** Base-36 alphabet used by the checksum (uppercase). */
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Compute the single check character for an SRI body
     * (everything before the trailing "+CHAR", e.g. "sri:2026.1001.art1").
     */
    public static function compute(string $input): string
    {
        $alphabet = self::ALPHABET;
        $sum = 0;
        $double = false;

        // Strip every non-alphanumeric character and uppercase, same as backend.
        $cleaned = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $input) ?? '');

        for ($i = strlen($cleaned) - 1; $i >= 0; $i--) {
            $codePoint = strpos($alphabet, $cleaned[$i]);
            if ($codePoint === false) {
                continue;
            }
            if ($double) {
                $codePoint *= 2;
                if ($codePoint >= 36) {
                    $codePoint -= 35;
                }
            }
            $sum += $codePoint;
            $double = !$double;
        }

        return $alphabet[(36 - ($sum % 36)) % 36];
    }

    /**
     * Build a full SRI: "sri:{year}.{prefix}.{suffix}+{CHECKCHAR}".
     */
    public static function buildSri(string|int $year, string|int $prefix, string $suffix): string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            throw new \InvalidArgumentException('SRI suffix must not be empty.');
        }
        $body = sprintf('sri:%d.%d.%s', (int)$year, (int)$prefix, $suffix);
        return $body . '+' . self::compute($body);
    }

    /**
     * Validate a full SRI (shape + check character).
     */
    public static function isValid(string $sri): bool
    {
        if (!preg_match('/^sri:(\d{4,})\.(\d+)\.([a-zA-Z0-9][a-zA-Z0-9._:-]*)\+([0-9A-Za-z])$/i', trim($sri), $m)) {
            return false;
        }
        $body = substr($sri, 0, strrpos($sri, '+'));
        $expected = self::compute($body);
        return strtoupper($m[4]) === $expected;
    }

    /**
     * Parse a full SRI into its parts (without validating the check char).
     *
     * @return array{year: string, prefix: string, suffix: string, checkChar: string}|null
     */
    public static function parse(string $sri): ?array
    {
        if (!preg_match('/^sri:(\d{4,})\.(\d+)\.([a-zA-Z0-9][a-zA-Z0-9._:-]*)\+([0-9A-Za-z])$/i', trim($sri), $m)) {
            return null;
        }
        return [
            'year' => $m[1],
            'prefix' => $m[2],
            'suffix' => $m[3],
            'checkChar' => strtoupper($m[4]),
        ];
    }

    /**
     * Extract the clean public ID without 'sri:' prefix and without '+CHECKCHAR'.
     *
     * Examples:
     *   cleanSri("sri:2026.1002.wjpst.1+I") -> "2026.1002.wjpst.1"
     *   cleanSri("2026.1002.wjpst.1+I")     -> "2026.1002.wjpst.1"
     *   cleanSri("sri:2026.1002.wjpst.1")   -> "2026.1002.wjpst.1"
     *   cleanSri("2026.1002.wjpst.1")       -> "2026.1002.wjpst.1"
     */
    public static function cleanSri(string $sri): string
    {
        $clean = trim($sri);
        if (str_starts_with(strtolower($clean), 'sri:')) {
            $clean = substr($clean, 4);
        }
        return (string)preg_replace('/\+([0-9A-Za-z])$/', '', $clean);
    }

    /**
     * Ensure the 'sri:' prefix but strip the '+CHECKCHAR'.
     *
     * Examples:
     *   publicSri("sri:2026.1002.wjpst.1+I") -> "sri:2026.1002.wjpst.1"
     *   publicSri("2026.1002.wjpst.1+I")     -> "sri:2026.1002.wjpst.1"
     */
    public static function publicSri(string $sri): string
    {
        $sri = trim($sri);
        if ($sri === '') {
            return '';
        }
        if (!str_starts_with(strtolower($sri), 'sri:')) {
            $sri = 'sri:' . $sri;
        }
        return (string)preg_replace('/\+([0-9A-Za-z])$/', '', $sri);
    }
}

