<?php

/**
 * @file tests/RegistrationServiceTest.php
 *
 * End-to-end orchestration tests against an injected (spying) transport:
 * registration payload shape, happy path, gate failures, 409 disambiguation,
 * status polling and bulk submission.
 */

class RegistrationServiceTest extends SriTestCase
{
    private array $calls = [];
    private int $registerCalls = 0;

    public function run(): array
    {
        // ---- Happy path ----
        $this->calls = [];
        $transport = $this->transport([
            '/register' => fn () => ['status' => 201, 'body' => '{"data":{"fullSri":"sri:2026.1001.jor.42+A","status":"ACTIVE","recordId":"r1"}}'],
        ]);
        $service = $this->service($transport);
        $article = $this->article();
        $result = $service->register($article, 1001, 'jor.42');

        $this->isTrue($result['success'], 'register success');
        $this->same('active', $result['state'], 'active state');
        $this->same('sri:2026.1001.jor.42+A', $result['fullSri'], 'fullSri returned');

        $payload = json_decode($this->calls['register']['body'], true);
        $this->same('A Study on SRI', $payload['title'], 'payload title');
        $this->same('OJS_PLUGIN', $payload['source'], 'payload source');
        $this->same('2026', $payload['year'], 'payload year');

        // ---- PENDING_REVIEW ----
        $this->calls = [];
        $transport = $this->transport([
            '/register' => fn () => ['status' => 201, 'body' => '{"data":{"fullSri":"sri:2026.1001.x+B","status":"PENDING_REVIEW"}}'],
        ]);
        $result = $this->service($transport)->register($article, 1001, 'x');
        $this->same('pending_review', $result['state'], 'pending state');

        // ---- Auth failure maps to a friendly reason ----
        $transport = $this->transport([
            '/register' => fn () => ['status' => 401, 'body' => '{"error":{"code":"API_KEY_INVALID","message":"Invalid or expired API key"}}'],
        ]);
        $result = $this->service($transport)->register($article, 1001, 'a');
        $this->isFalse($result['success'], '401 fails');
        $this->same('failed', $result['state'], '401 -> failed state');
        $this->same('plugins.pubIds.sri.status.failed.auth', $result['reason']['key'], '401 reason key');

        // ---- Quota gate ----
        $transport = $this->transport([
            '/register' => fn () => ['status' => 402, 'body' => '{"error":{"code":"QUOTA_EXCEEDED","message":"SRI quota exhausted."}}'],
        ]);
        $result = $this->service($transport)->register($article, 1001, 'a');
        $this->same('plugins.pubIds.sri.status.failed.quota', $result['reason']['key'], 'quota reason key');

        // ---- 409 disambiguation: first call conflicts, retry changes the suffix ----
        $this->calls = [];
        $this->registerCalls = 0;
        $transport = $this->transport([
            '/register' => function () {
                $this->registerCalls++;
                if ($this->registerCalls === 1) {
                    return ['status' => 409, 'body' => '{"error":{"code":"CONFLICT","message":"Resource already exists"}}'];
                }
                return ['status' => 201, 'body' => '{"data":{"fullSri":"sri:2026.1001.jor.42-2+C","status":"ACTIVE"}}'];
            },
        ]);

        $result = $this->service($transport)->registerWithRetry($article, 1001, 'jor.42');
        $this->isTrue($result['success'], 'retry succeeds');
        $this->same('jor.42-2', $result['suffixUsed'], 'retry disambiguator suffix');
        $this->same(2, $result['attempts'], 'two attempts');

        // ---- checkStatus ----
        $transport = $this->transport([
            '/metadata/sri%3A2026.1001.a%2BU' => fn () => ['status' => 200, 'body' => '{"data":{"fullSri":"sri:2026.1001.a+U","status":"ACTIVE"}}'],
        ]);
        $st = $this->service($transport)->checkStatus('sri:2026.1001.a+U');
        $this->isTrue($st['success'], 'checkStatus ok');
        $this->same('active', $st['state'], 'checkStatus state');
        $this->same('ACTIVE', $st['status'], 'checkStatus raw status');

        // ---- bulk submit + poll ----
        $this->calls = [];
        $transport = $this->transport([
            '/register/bulk' => fn () => ['status' => 202, 'body' => '{"data":{"jobId":"job-1","totalRows":2}}'],
            '/bulk-jobs/job-1' => fn () => ['status' => 200, 'body' => '{"data":{"jobId":"job-1","status":"COMPLETED","totalRows":2,"processedRows":2,"successCount":2,"errorCount":0}}'],
        ]);
        $service = $this->service($transport);
        $row = (new \SRI\Plugin\MetadataMapper())->toBulkRow($article, 1001, 'jor.42');
        $bulk = $service->submitBulk([$row, $row]);
        $this->isTrue($bulk['success'], 'bulk submit ok');
        $this->same('job-1', $bulk['jobId'], 'bulk jobId');
        $this->ok(str_starts_with($this->calls['register/bulk']['body'] ?? '', '@'), 'bulk uses file upload (transport sees @path)');

        $poll = $service->getBulkJobStatus('job-1');
        $this->isTrue($poll['success'], 'bulk poll ok');
        $this->same(2, $poll['successCount'], 'bulk successCount');

        return $this->result();
    }

    private function article(): \SRI\Plugin\ArticleData
    {
        $a = new \SRI\Plugin\ArticleData();
        $a->title = 'A Study on SRI';
        $a->creators = [['name' => 'Jane Doe']];
        $a->publicationDate = '2026-03-15';
        $a->targetUrl = 'https://journal.example.org/article/view/42';
        $a->year = 2026;
        return $a;
    }

    private function service(callable $transport): \SRI\Plugin\RegistrationService
    {
        $client = new \SRI\Plugin\ApiClient('https://api.example.org/api/v1', 'sk_test', 5, 15, $transport, 'test');
        return new \SRI\Plugin\RegistrationService($client);
    }

    private function transport(array $routes): callable
    {
        return function (string $method, string $url, string $body, array $headers) use ($routes): array {
            $path = (string)parse_url($url, PHP_URL_PATH); // /api/v1/register
            if (str_starts_with($path, '/api/v1/')) {
                $path = substr($path, strlen('/api/v1/')); // register
            }
            $this->calls[$path] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
            foreach ($routes as $route => $handler) {
                if ($path === ltrim($route, '/')) {
                    return $handler();
                }
            }
            return ['status' => 404, 'body' => '{"error":{"code":"NOT_FOUND","message":"no route"}}'];
        };
    }
}


