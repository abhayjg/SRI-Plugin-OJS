<?php

/**
 * @file tests/CheckCharacterTest.php
 *
 * Tests the Luhn mod-36 check character against examples derived from the
 * SRI-Backend algorithm (the expectations below were cross-checked by hand
 * against checksum.ts).
 */

class CheckCharacterTest extends SriTestCase
{
    public function run(): array
    {
        // Fixed known vector hand-derived from the backend's checksum.ts
        // (computeCheckCharacter over "sri:2026.1001.a" -> "U").
        $this->same('U', \SRI\Plugin\CheckCharacter::compute('sri:2026.1001.a'), 'known check char vector');
        $this->isTrue(\SRI\Plugin\CheckCharacter::isValid('sri:2026.1001.a+U'), 'known good SRI validates');
        $this->isFalse(\SRI\Plugin\CheckCharacter::isValid('sri:2026.1001.a+V'), 'known bad SRI rejected');

        // Build + round-trip validation
        $sri = \SRI\Plugin\CheckCharacter::buildSri(2026, 1001, 'jor.123');
        $this->isTrue(\SRI\Plugin\CheckCharacter::isValid($sri), "buildSri output validates");

        $parsed = \SRI\Plugin\CheckCharacter::parse($sri);
        $this->notNull($parsed, 'parse returns parts');
        $this->same('2026', $parsed['year'], 'parsed year');
        $this->same('1001', $parsed['prefix'], 'parsed prefix');
        $this->same('jor.123', $parsed['suffix'], 'parsed suffix');

        // Determinism: same input -> same check char
        $this->same(
            \SRI\Plugin\CheckCharacter::compute('sri:2026.1001.art1'),
            \SRI\Plugin\CheckCharacter::compute('sri:2026.1001.art1'),
            'deterministic check char'
        );

        // Validation rejects tampered identifiers
        $this->isFalse(\SRI\Plugin\CheckCharacter::isValid('sri:2026.1001.art1+X'), 'tampered suffix is invalid');

        // Empty suffix rejected
        $this->assertThrows(fn () => \SRI\Plugin\CheckCharacter::buildSri(2026, 1001, ''), 'empty suffix throws');

        // Regex disallows obviously malformed inputs
        $this->isFalse(\SRI\Plugin\CheckCharacter::isValid('not-an-sri'), 'malformed rejected');
        $this->isFalse(\SRI\Plugin\CheckCharacter::isValid(''), 'empty rejected');

        return $this->result();
    }
}
