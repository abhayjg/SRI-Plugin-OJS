<?php

/**
 * @file plugins/pubIds/sri/classes/core/RegistrationService.php
 *
 * OJS-version-independent orchestration of the SRI registration lifecycle:
 *
 *   - register():          POST /api/v1/register   (single article)
 *   - registerWithRetry(): register + automatic 409 disambiguation
 *   - checkStatus():       GET  /api/v1/metadata/{fullSri}
 *   - updateMetadata():    PATCH /api/v1/metadata/{fullSri}  (re-deposit on edit)
 *   - submitBulk():        POST /api/v1/register/bulk        (multipart CSV)
 *   - getBulkJobStatus():  GET  /api/v1/bulk-jobs/{id}
 *
 * Result objects are plain arrays; the OJS adapter maps them to stored status
 * and translatable messages.
 */

namespace SRI\Plugin;

final class RegistrationService
{
    private ApiClient $api;

    private MetadataMapper $mapper;

    private SuffixGenerator $suffixes;

    private StatusResolver $statuses;

    private int $maxConflictRetries = 5;

    public function __construct(
        ApiClient $api,
        ?MetadataMapper $mapper = null,
        ?SuffixGenerator $suffixes = null,
        ?StatusResolver $statuses = null
    ) {
        $this->api = $api;
        $this->mapper = $mapper ?? new MetadataMapper();
        $this->suffixes = $suffixes ?? new SuffixGenerator();
        $this->statuses = $statuses ?? new StatusResolver();
    }

    /**
     * Register a single article.
     *
     * @return array{success: bool, state: string, fullSri?: string, recordId?: string,
     *                httpStatus: int, code?: string, message?: string, reason?: array}
     */
    public function register(ArticleData $article, int|string $prefix, string $suffix): array
    {
        $payload = $this->mapper->toRegistrationPayload($article, $prefix, $suffix);
        $response = $this->api->request('POST', '/register', $payload);

        if (!$response['ok']) {
            $resolved = $this->statuses->resolveError($response['httpStatus'], $this->errorCode($response), $response['error'] ?? '');
            return $this->failure($response, $resolved);
        }

        $data = is_array($response['body']) ? ($response['body']['data'] ?? []) : [];
        $fullSri = (string)($data['fullSri'] ?? ($data['sri'] ?? ''));
        $status = (string)($data['status'] ?? '');

        return [
            'success' => true,
            'state' => $this->statuses->fromRecordStatus($status),
            'fullSri' => $fullSri,
            'recordId' => (string)($data['recordId'] ?? ''),
            'sri' => (string)($data['sri'] ?? ''),
            'qualityScore' => $data['qualityScore'] ?? null,
            'qualityBadge' => $data['qualityBadge'] ?? null,
            'httpStatus' => $response['httpStatus'],
        ];
    }

    /**
     * Register with automatic handling of 409 duplicate-suffix responses:
     * retries with a disambiguator ("suffix-2", "suffix-3", ...).
     *
     * @return array Same shape as register(), plus 'attempts' used.
     */
    public function registerWithRetry(ArticleData $article, int|string $prefix, string $suffix): array
    {
        $cursor = $suffix;
        for ($attempt = 0; $attempt <= $this->maxConflictRetries; $attempt++) {
            $result = $this->register($article, $prefix, $cursor);
            if ($result['success']) {
                $result['attempts'] = $attempt + 1;
                $result['suffixUsed'] = $cursor;
                return $result;
            }
            if ((int)$result['httpStatus'] !== 409) {
                $result['suffixUsed'] = $cursor;
                $result['attempts'] = $attempt + 1;
                return $result;
            }
            // 409: the (prefix, suffix, year) combination exists. Disambiguate.
            $cursor = $this->suffixes->disambiguate($suffix, $attempt + 1);
        }
        $result['suffixUsed'] = $cursor;
        $result['attempts'] = $this->maxConflictRetries + 1;
        $result['failedAfterConflictRetries'] = true;
        return $result;
    }

    /**
     * Check the current registration status of a full SRI (for polling
     * PENDING_REVIEW -> ACTIVE and for "Refresh status").
     *
     * @return array{success: bool, state: string, status?: string, httpStatus: int,
     *                reason?: array, data?: array}
     */
    public function checkStatus(string $fullSri): array
    {
        $response = $this->api->request('GET', '/metadata/' . rawurlencode($fullSri));
        if (!$response['ok']) {
            $resolved = $this->statuses->resolveError($response['httpStatus'], $this->errorCode($response), $response['error'] ?? '');
            return $this->failure($response, $resolved);
        }
        $data = is_array($response['body']) ? ($response['body']['data'] ?? $response['body']) : [];
        $status = (string)($data['status'] ?? '');
        return [
            'success' => true,
            'state' => $this->statuses->fromRecordStatus($status),
            'status' => $status,
            'fullSri' => (string)($data['fullSri'] ?? $fullSri),
            'httpStatus' => $response['httpStatus'],
            'data' => is_array($data) ? $data : [],
        ];
    }

    /**
     * Re-deposit metadata on edit (PATCH /api/v1/metadata/{fullSri}).
     *
     * NOTE: the SRI-Backend metadata PATCH route is currently JWT-guarded
     * (authenticate + ownerGuard). API-key clients cannot update metadata yet;
     * the plugin surfaces a clear reason and directs to the dashboard when the
     * API key is rejected. This is a documented backend follow-up (plan Open
     * Question — wire API-key auth onto metadata PATCH).
     *
     * @return array{success: bool, httpStatus: int, code?: string, message?: string, reason?: array}
     */
    public function updateMetadata(string $fullSri, ArticleData $article): array
    {
        $payload = $this->mapper->toUpdatePayload($article);
        $response = $this->api->request('PATCH', '/metadata/' . rawurlencode($fullSri), $payload);
        if (!$response['ok']) {
            $resolved = $this->statuses->resolveError($response['httpStatus'], $this->errorCode($response), $response['error'] ?? '');
            return $this->failure($response, $resolved);
        }
        return [
            'success' => true,
            'httpStatus' => $response['httpStatus'],
            'body' => $response['body'],
        ];
    }

    /**
     * Submit a batch of articles for bulk registration via the multipart CSV
     * upload endpoint. Returns the backend jobId for subsequent polling.
     *
     * @param array<int, array<string, string>> $rows  Associative bulk rows
     *                                                 (see MetadataMapper::toBulkRow).
     *
     * @return array{success: bool, jobId?: string, totalRows?: int, httpStatus: int,
     *                code?: string, message?: string, reason?: array}
     */
    public function submitBulk(array $rows): array
    {
        if (count($rows) === 0) {
            throw new \InvalidArgumentException('Cannot submit an empty bulk batch.');
        }

        $csv = $this->mapper->toCsv($rows);
        $tmp = tempnam(sys_get_temp_dir(), 'sri_bulk_');
        if ($tmp === false) {
            return ['success' => false, 'httpStatus' => 0, 'code' => 'FS_ERROR', 'message' => 'Could not create a temporary file for bulk upload.'];
        }
        if (file_put_contents($tmp, $csv) === false) {
            @unlink($tmp);
            return ['success' => false, 'httpStatus' => 0, 'code' => 'FS_ERROR', 'message' => 'Could not write the bulk CSV to disk.'];
        }

        try {
            $response = $this->api->upload(
                '/register/bulk',
                $tmp,
                'sri-bulk.csv',
                'text/csv'
            );
        } finally {
            @unlink($tmp);
        }

        if (!$response['ok']) {
            $resolved = $this->statuses->resolveError($response['httpStatus'], $this->errorCode($response), $response['error'] ?? '');
            return $this->failure($response, $resolved);
        }

        $data = is_array($response['body']) ? ($response['body']['data'] ?? []) : [];
        return [
            'success' => true,
            'jobId' => (string)($data['jobId'] ?? ''), // fallback: query string
            'totalRows' => isset($data['totalRows']) ? (int)$data['totalRows'] : count($rows),
            'httpStatus' => $response['httpStatus'],
        ];
    }

    /**
     * Poll a bulk registration job.
     *
     * @return array{success: bool, httpStatus: int, status?: string, jobId?: string,
     *                totalRows?: int, processedRows?: int, successCount?: int,
     *                errorCount?: int, errors?: array, createdSris?: array, reason?: array}
     */
    public function getBulkJobStatus(string $jobId): array
    {
        $response = $this->api->request('GET', '/bulk-jobs/' . rawurlencode($jobId));
        if (!$response['ok']) {
            $resolved = $this->statuses->resolveError($response['httpStatus'], $this->errorCode($response), $response['error'] ?? '');
            $failure = $this->failure($response, $resolved);
            $failure['jobId'] = $jobId;
            return $failure;
        }
        $data = is_array($response['body']) ? ($response['body']['data'] ?? $response['body']) : [];
        return [
            'success' => true,
            'httpStatus' => $response['httpStatus'],
            'jobId' => (string)($data['jobId'] ?? $jobId),
            'status' => (string)($data['status'] ?? ''),
            'totalRows' => isset($data['totalRows']) ? (int)$data['totalRows'] : null,
            'processedRows' => isset($data['processedRows']) ? (int)$data['processedRows'] : null,
            'successCount' => isset($data['successCount']) ? (int)$data['successCount'] : null,
            'errorCount' => isset($data['errorCount']) ? (int)$data['errorCount'] : null,
            'errors' => $data['errors'] ?? [],
            'createdSris' => $data['createdSris'] ?? [],
        ];
    }

    /**
     * @return array{success: false, state: string, httpStatus: int, code?: string, message: string, reason: array}
     */
    private function failure(array $response, array $resolved): array
    {
        return [
            'success' => false,
            'state' => StatusResolver::STATE_FAILED,
            'httpStatus' => $response['httpStatus'],
            'code' => $this->errorCode($response),
            'message' => $response['error'] ?? '',
            'reason' => $resolved,
        ];
    }

    private function errorCode(array $response): string
    {
        $body = is_array($response['body']) ? $response['body'] : [];
        if (isset($body['error']['code'])) {
            return (string)$body['error']['code'];
        }
        if (isset($body['code'])) {
            return (string)$body['code'];
        }
        return '';
    }
}
