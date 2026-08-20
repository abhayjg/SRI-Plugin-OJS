<?php

/**
 * @file tests/StatusResolverTest.php
 *
 * Verifies error-code -> friendly reason mapping against the backend gate
 * table (401 auth / 402 expired+quota / 403 suspended+prefix / 409 dup / ...).
 */

class StatusResolverTest extends SriTestCase
{
    public function run(): array
    {
        $r = new \SRI\Plugin\StatusResolver();

        // Auth
        $this->same('plugins.pubIds.sri.status.failed.auth', $r->resolveError(401, 'API_KEY_INVALID')['key'], '401 api key');
        $this->same('plugins.pubIds.sri.status.failed.auth', $r->resolveError(401)['key'], '401 fallback');

        // Membership / quota
        $this->same('plugins.pubIds.sri.status.failed.expired', $r->resolveError(402, 'ACCOUNT_EXPIRED')['key'], 'expired');
        $this->same('plugins.pubIds.sri.status.failed.quota', $r->resolveError(402, 'QUOTA_EXCEEDED')['key'], 'quota exceeded');
        $this->same('plugins.pubIds.sri.status.failed.quota', $r->resolveError(402, 'NO_QUOTA')['key'], 'no quota');
        $this->same('plugins.pubIds.sri.status.failed.expired', $r->resolveError(402)['key'], '402 fallback');

        // Suspension / prefix
        $this->same('plugins.pubIds.sri.status.failed.suspended', $r->resolveError(403, 'ACCOUNT_SUSPENDED')['key'], 'suspended');
        $this->same('plugins.pubIds.sri.status.failed.prefix', $r->resolveError(403, 'PREFIX_INACTIVE')['key'], 'prefix inactive');
        $this->same('plugins.pubIds.sri.status.failed.prefix', $r->resolveError(404, 'PREFIX_NOT_FOUND')['key'], 'prefix not found');
        $this->same('plugins.pubIds.sri.status.failed.suspended', $r->resolveError(403)['key'], '403 fallback');

        // Record-level
        $this->same('plugins.pubIds.sri.status.failed.notfound', $r->resolveError(404, 'SRI_NOT_FOUND')['key'], 'sri not found');
        $this->same('plugins.pubIds.sri.status.failed.duplicate', $r->resolveError(409, 'CONFLICT')['key'], 'conflict/duplicate');

        // 4xx/5xx buckets
        $this->same('plugins.pubIds.sri.status.failed.ratelimit', $r->resolveError(429)['key'], '429');
        $this->same('plugins.pubIds.sri.status.failed.server', $r->resolveError(503)['key'], '503');
        $this->same('plugins.pubIds.sri.status.failed.unknown', $r->resolveError(600)['key'], 'unknown status');

        // Record status -> states
        $this->same(\SRI\Plugin\StatusResolver::STATE_ACTIVE, $r->fromRecordStatus('ACTIVE'), 'ACTIVE');
        $this->same(\SRI\Plugin\StatusResolver::STATE_PENDING, $r->fromRecordStatus('PENDING_REVIEW'), 'PENDING_REVIEW');
        $this->same(\SRI\Plugin\StatusResolver::STATE_WITHDRAWN, $r->fromRecordStatus('WITHDRAWN'), 'WITHDRAWN');
        $this->same(\SRI\Plugin\StatusResolver::STATE_TOMBSTONED, $r->fromRecordStatus('TOMBSTONED'), 'TOMBSTONED');
        $this->same(\SRI\Plugin\StatusResolver::STATE_NOT_REGISTERED, $r->fromRecordStatus(null), 'null -> not registered');

        // Transport error
        $x = $r->resolveTransportError('timeout');
        $this->same('plugins.pubIds.sri.status.failed.network', $x['key'], 'transport -> network');

        return $this->result();
    }
}
