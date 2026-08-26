<?php

/**
 * @file tests/ResolverUrlTest.php
 *
 * Tests resolving URL logic: dev (port 4000 -> port 3000), prod (api-sri.scitekhub.com -> sri.scitekhub.com),
 * and clean SRI identifier extraction (stripping 'sri:' and '+CHECKCHAR').
 */

use SRI\Plugin\CheckCharacter;

class ResolverUrlTest extends SriTestCase
{
    private function resolveUrl(string $apiUrl, string $resolverUrlSetting, string $pubId): string
    {
        $resolver = trim($resolverUrlSetting);
        if ($resolver === '') {
            if ($apiUrl === '') {
                $resolver = 'https://sri.scitekhub.com';
            } elseif (preg_match('#^https?://(localhost|127\.0\.0\.1):4000#i', $apiUrl, $m)) {
                $proto = str_starts_with(strtolower($apiUrl), 'https') ? 'https' : 'http';
                $resolver = $proto . '://' . $m[1] . ':3000';
            } elseif (preg_match('#^https?://api-sri\.scitekhub\.com#i', $apiUrl) || preg_match('#^https?://api\.scitekhub\.com#i', $apiUrl)) {
                $resolver = 'https://sri.scitekhub.com';
            } else {
                $resolver = (string)preg_replace('#/api/v1/?$#i', '', $apiUrl);
            }
        }
        $origin = rtrim($resolver, '/');
        $cleanSri = CheckCharacter::cleanSri($pubId);
        if ($cleanSri === '') {
            return '';
        }
        return $origin . '/' . $cleanSri;
    }

    public function run(): array
    {
        // 1. Local dev port 4000 -> 3000
        $url1 = $this->resolveUrl('http://localhost:4000/api/v1', '', 'sri:2026.1002.wjpst.1-6+S');
        $this->same('http://localhost:3000/2026.1002.wjpst.1-6', $url1, 'Localhost 4000 maps to frontend 3000 with clean SRI');

        $url2 = $this->resolveUrl('http://127.0.0.1:4000/api/v1', '', 'sri:2026.1001.sample.1+K');
        $this->same('http://127.0.0.1:3000/2026.1001.sample.1', $url2, '127.0.0.1 4000 maps to frontend 3000');

        // 2. Production API -> sri.scitekhub.com
        $url3 = $this->resolveUrl('https://api-sri.scitekhub.com/api/v1', '', 'sri:2026.1002.wjpst.1-6+S');
        $this->same('https://sri.scitekhub.com/2026.1002.wjpst.1-6', $url3, 'Production api-sri maps to sri.scitekhub.com');

        $url4 = $this->resolveUrl('https://api.scitekhub.com/api/v1', '', 'sri:2026.1002.wjpst.1-6+S');
        $this->same('https://sri.scitekhub.com/2026.1002.wjpst.1-6', $url4, 'Production api.scitekhub.com maps to sri.scitekhub.com');

        // 3. Custom resolver setting override
        $url5 = $this->resolveUrl('http://localhost:4000/api/v1', 'https://custom-resolver.org', 'sri:2026.1002.wjpst.1-6+S');
        $this->same('https://custom-resolver.org/2026.1002.wjpst.1-6', $url5, 'Explicit resolver URL setting overrides auto origin');

        // 4. pubId format variations
        $url6 = $this->resolveUrl('https://sri.scitekhub.com', '', '2026.1002.wjpst.1-6+S');
        $this->same('https://sri.scitekhub.com/2026.1002.wjpst.1-6', $url6, 'PubId without sri: prefix still strips check character');

        $url7 = $this->resolveUrl('https://sri.scitekhub.com', '', '2026.1002.wjpst.1-6');
        $this->same('https://sri.scitekhub.com/2026.1002.wjpst.1-6', $url7, 'Already clean pubId stays intact');

        return $this->result();
    }
}
