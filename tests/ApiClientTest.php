<?php

/**
 * @file tests/ApiClientTest.php
 *
 * Verifies ApiClient request construction, hardening flags and the injectable
 * transport (used by the plugin's unit tests — production always uses cURL).
 */

class ApiClientTest extends SriTestCase
{
    public function run(): array
    {
        $seen = [];
        $transport = function (string $method, string $url, string $body, array $headers) use (&$seen): array {
            $seen = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
            return ['status' => 201, 'body' => '{"data":{"fullSri":"sri:2026.1001.a+U","status":"ACTIVE"}}'];
        };

        $client = new \SRI\Plugin\ApiClient('https://api.example.org/api/v1', 'sk_test_123', 5, 15, $transport, 'SRI-Plugin/1.0 (test)');
        $res = $client->request('POST', '/register', ['suffix' => 'a'], ['X-Extra: 1']);

        $this->isTrue($res['ok'], 'request ok');
        $this->same(201, $res['httpStatus'], 'httpStatus');
        $this->isArray($res['body'], 'parsed body is array');
        $this->same('ACTIVE', $res['body']['data']['status'], 'body data parsed');

        $this->same('https://api.example.org/api/v1/register', $seen['url'], 'base url + path joined single slash');
        $this->same('POST', $seen['method'], 'POST method');
        $this->ok(str_contains($seen['body'], '"suffix":"a"'), 'JSON body encoded');
        $joined = implode("\n", $seen['headers']);
        $this->ok(str_contains($joined, 'X-SRI-API-Key: sk_test_123'), 'API key header present');
        $this->ok(str_contains($seen['headers'][0] ?? '', 'Accept: application/json') || str_contains($joined, 'Accept: application/json'), 'Accept header');

        // Error response shape
        $errTransport = fn () => ['status' => 401, 'body' => '{"error":{"code":"API_KEY_INVALID","message":"Invalid or expired API key"}}'];
        $client2 = new \SRI\Plugin\ApiClient('https://api.example.org/api/v1', 'k', 5, 15, $errTransport);
        $err = $client2->request('GET', '/metadata/x');
        $this->isFalse($err['ok'], 'error ok=false');
        $this->same('Invalid or expired API key', $err['error'], 'error message extracted');

        // Transport throwing -> transportFailure (no exception leaks)
        $throwTransport = function () {
            throw new \RuntimeException('boom');
        };
        $client3 = new \SRI\Plugin\ApiClient('https://api.example.org/api/v1', 'k', 5, 15, $throwTransport);
        $fail = $client3->request('GET', '/metadata/x');
        $this->isFalse($fail['ok'], 'transport exception handled');
        $this->same('boom', $fail['error'], 'transport error propagated');

        return $this->result();
    }

    private function isArray($value, string $label): void
    {
        $this->ok(is_array($value), "{$label} (expected array)");
    }
}
