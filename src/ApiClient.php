<?php

/**
 * @file plugins/pubIds/sri/classes/core/ApiClient.php
 *
 * Minimal, hardened HTTP client for the SRI REST API.
 *
 * Security invariants (see SECURITY.md):
 *   - TLS is ALWAYS verified (CURLOPT_SSL_VERIFYPEER / VERIFYHOST on).
 *   - Every call is bounded by hard connect + total timeouts.
 *   - The API key is sent as the X-SRI-API-Key header (never in query strings).
 *   - Redirects are not followed by default (the SRI API is a direct JSON API),
 *     making certificate-pinning behaviour predictable.
 *
 * A callable "transport" can be injected for unit tests; production always
 * uses cURL (extension-agnostic: throws if curl is unavailable).
 */

namespace SRI\Plugin;

final class ApiClient
{
    /** @var string Base URL, no trailing slash (e.g. https://api.sri.scitekhub.com/api/v1) */
    private string $baseUrl;

    /** @var string API key (X-SRI-API-Key header value). */
    private string $apiKey;

    private int $connectTimeout;

    private int $timeout;

    /** @var null|callable(string $method, string $url, string $body, array $headers): array */
    private $transport;

    private string $userAgent;

    /**
     * @param null|callable $transport Optional test transport. Signature:
     *        fn(string $method, string $url, string $body, array $headers): array
     *        returning ['status' => int, 'body' => string].
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        int $connectTimeout = 10,
        int $timeout = 30,
        ?callable $transport = null,
        string $userAgent = 'SRI-Plugin/1.0'
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->connectTimeout = max(1, $connectTimeout);
        $this->timeout = max(1, $timeout);
        $this->transport = $transport;
        $this->userAgent = $userAgent;
    }

    /**
     * Perform a JSON request.
     *
     * @param string                 $method  GET|POST|PATCH
     * @param string                 $path    Leading-slash path e.g. /register
     * @param array<string, mixed>   $body    Request body (JSON-encoded)
     * @param array<string, string>  $extraHeaders
     *
     * @return array{ok: bool, httpStatus: int, body: mixed, raw: string, error?: string}
     */
    public function request(string $method, string $path, array $body = [], array $extraHeaders = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $json = $body === [] ? '{}' : (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = array_merge([
            'Accept: application/json',
            'Content-Type: application/json',
            'X-SRI-API-Key: ' . $this->apiKey,
            'User-Agent: ' . $this->userAgent,
        ], $extraHeaders);

        if (is_callable($this->transport)) {
            try {
                $response = ($this->transport)($method, $url, $json, $headers);
            } catch (\Throwable $e) {
                return $this->transportFailure($e->getMessage());
            }
            $status = (int)($response['status'] ?? 0);
            $raw = (string)($response['body'] ?? '');
            return $this->shape($status, $raw);
        }

        return $this->curlRequest($method, $url, $json, $headers);
    }

    /**
     * Send a multipart/form-data upload (bulk registration) using a file path.
     *
     * @param array<string, string> $fields  Extra form fields.
     *
     * @return array{ok: bool, httpStatus: int, body: mixed, raw: string, error?: string}
     */
    public function upload(string $path, string $filePath, string $filename, string $mimeType, array $fields = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = [
            'Accept: application/json',
            'X-SRI-API-Key: ' . $this->apiKey,
            'User-Agent: ' . $this->userAgent,
        ];

        if (is_callable($this->transport)) {
            // A test transport can swap in a raw multipart body string.
            $response = ($this->transport)('UPLOAD', $url, '@' . $filePath, $headers);
            $status = (int)($response['status'] ?? 0);
            $raw = (string)($response['body'] ?? '');
            return $this->shape($status, $raw);
        }

        if (!function_exists('curl_init')) {
            return $this->transportFailure('The PHP cURL extension is required to talk to the SRI API.');
        }

        $post = $fields;
        $post['file'] = new \CURLFile($filePath, $mimeType, $filename);

        $ch = curl_init($url);
        if ($ch === false) {
            return $this->transportFailure('Could not initialize cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return $this->execute($ch, $url);
    }

    private function curlRequest(string $method, string $url, string $json, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return $this->transportFailure('The PHP cURL extension is required to talk to the SRI API.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return $this->transportFailure('Could not initialize cURL.');
        }

        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $this->userAgent,
        ];

        if ($method === 'PATCH') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
            $options[CURLOPT_POSTFIELDS] = $json;
            $options[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Length: ' . strlen($json)]);
        } elseif ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $json;
        } else { // GET
            $options[CURLOPT_HTTPGET] = true;
            $options[CURLOPT_HTTPHEADER] = array_filter($headers, static fn ($h) => stripos($h, 'content-type:') !== 0);
        }

        curl_setopt_array($ch, $options);
        return $this->execute($ch, $url);
    }

    /**
     * @param \CurlHandle $ch
     */
    private function execute($ch, string $url): array
    {
        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $friendly = $this->describeCurlError($errno, $error, $url);
            return ['ok' => false, 'httpStatus' => $httpStatus, 'body' => null, 'raw' => '', 'error' => $friendly];
        }

        $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $this->shape($httpStatus, (string)$raw);
    }

    private function shape(int $status, string $raw): array
    {
        $decoded = json_decode($raw, true);
        $ok = $status >= 200 && $status < 300;
        return [
            'ok' => $ok,
            'httpStatus' => $status,
            'body' => $decoded !== null ? $decoded : $raw,
            'raw' => $raw,
            'error' => $ok ? '' : $this->errorMessage($status, $decoded),
        ];
    }

    private function transportFailure(string $message): array
    {
        return ['ok' => false, 'httpStatus' => 0, 'body' => null, 'raw' => '', 'error' => $message];
    }

    private function errorMessage(int $status, mixed $decoded): string
    {
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            return (string)$decoded['error']['message'];
        }
        return 'HTTP ' . $status;
    }

    private function describeCurlError(int $errno, string $error, string $url): string
    {
        return match ($errno) {
            CURLE_OPERATION_TIMEOUTED, CURLE_OPERATION_TIMEOUTED + 0 => 'Request to the SRI API timed out: ' . $url,
            CURLE_COULDNT_CONNECT => 'Could not connect to the SRI API: ' . $url,
            CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CERTPROBLEM => 'TLS verification failed talking to the SRI API: ' . $url,
            default => $error !== '' ? $error : 'Unknown cURL error',
        };
    }
}
