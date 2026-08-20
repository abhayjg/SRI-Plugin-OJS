<?php

/**
 * @file plugins/pubIds/sri/classes/core/StatusResolver.php
 *
 * Maps SRI API error responses (HTTP status + error code) to friendly,
 * translatable status reasons shown inside OJS.
 *
 * This mirrors the backend's gate logic exactly — see the SRI-Backend
 * partnerGuard/registrationService AppErrors. The plugin is a client of those
 * same checks; it only decides how to present the outcome in OJS.
 */

namespace SRI\Plugin;

final class StatusResolver
{
    public const STATE_NOT_REGISTERED = 'not_registered';
    public const STATE_PENDING = 'pending_review';
    public const STATE_ACTIVE = 'active';
    public const STATE_WITHDRAWN = 'withdrawn';
    public const STATE_TOMBSTONED = 'tombstoned';
    public const STATE_FAILED = 'failed';

    /**
     * Map an SRI record status value (as returned in the API payload/data) to
     * a plugin state.
     */
    public function fromRecordStatus(?string $status): string
    {
        return match (strtoupper((string)$status)) {
            'ACTIVE' => self::STATE_ACTIVE,
            'PENDING_REVIEW' => self::STATE_PENDING,
            'WITHDRAWN' => self::STATE_WITHDRAWN,
            'TOMBSTONED' => self::STATE_TOMBSTONED,
            default => self::STATE_NOT_REGISTERED,
        };
    }

    /**
     * Resolve a failure into a translatable reason descriptor.
     *
     * @param int|string $httpStatus HTTP status code from the API.
     * @param string     $errorCode  Error code from {"error":{"code":...}}.
     * @param string     $message    Raw error message (fallback text).
     *
     * @return array{key: string, params: array<string,string>, message: string}
     */
    public function resolveError(int|string $httpStatus, string $errorCode = '', string $message = ''): array
    {
        $status = (int)$httpStatus;
        $code = strtoupper(trim($errorCode));

        $auth = ['API_KEY_REQUIRED', 'API_KEY_INVALID', 'UNAUTHORIZED', 'TOKEN_EXPIRED', 'INVALID_TOKEN', 'FORBIDDEN'];
        $suspended = ['ACCOUNT_SUSPENDED', 'ACCOUNT_CLOSED', 'ACCOUNT_NOT_ACTIVE'];
        $expired = ['ACCOUNT_EXPIRED', 'NO_EXPIRY_SET'];
        $quota = ['NO_QUOTA', 'QUOTA_EXCEEDED', 'PREFIX_QUOTA_EXCEEDED', 'SRI_QUOTA_EXCEEDED'];
        $prefix = ['PREFIX_NOT_FOUND', 'PREFIX_INACTIVE', 'PREFIX_NOT_OWNED'];
        $notFound = ['SRI_NOT_FOUND', 'NOT_FOUND', 'JOB_NOT_FOUND'];
        $duplicate = ['CONFLICT', 'DUPLICATE_SUFFIX', 'SUFFIX_CONFLICT'];

        if ($code !== '' && in_array($code, $auth, true)) {
            return ['key' => 'plugins.pubIds.sri.status.failed.auth', 'params' => [], 'message' => 'Reconnect your SRI API key.'];
        }
        if ($code !== '' && in_array($code, $suspended, true)) {
            return ['key' => 'plugins.pubIds.sri.status.failed.suspended', 'params' => [], 'message' => 'Account suspended — contact SRI support.'];
        }
        if ($code !== '' && in_array($code, $expired, true)) {
            return ['key' => 'plugins.pubIds.sri.status.failed.expired', 'params' => [], 'message' => 'Your SRI membership has expired — renew to register.'];
        }
        if ($code !== '' && in_array($code, $quota, true)) {
            return ['key' => 'plugins.pubIds.sri.status.failed.quota', 'params' => [], 'message' => 'Quota exceeded — upgrade your plan.'];
        }
        if ($code !== '' && in_array($code, $prefix, true)) {
            return ['key' => 'plugins.pubIds.sri.status.failed.prefix', 'params' => [], 'message' => 'Prefix not set up — contact SRI support.'];
        }
        if ($code !== '' && in_array($code, $duplicate, true)) {
            return ['key' => 'plugins.pubIds.sri.status.failed.duplicate', 'params' => [], 'message' => 'Duplicate suffix — retried with a disambiguator or fix manually.'];
        }
        if ($code !== '' && in_array($code, $notFound, true)) {
            return ['key' => 'plugins.pubIds.sri.status.failed.notfound', 'params' => [], 'message' => 'SRI record not found — it may have been removed.'];
        }

        switch ($status) {
            case 401:
                return ['key' => 'plugins.pubIds.sri.status.failed.auth', 'params' => [], 'message' => 'Reconnect your SRI API key.'];
            case 402:
                return ['key' => 'plugins.pubIds.sri.status.failed.expired', 'params' => [], 'message' => 'Membership or quota issue — review your SRI account.'];
            case 403:
                return ['key' => 'plugins.pubIds.sri.status.failed.suspended', 'params' => [], 'message' => 'Access denied — check your SRI account and prefix.'];
            case 404:
                return ['key' => 'plugins.pubIds.sri.status.failed.notfound', 'params' => [], 'message' => 'SRI record not found.'];
            case 409:
                return ['key' => 'plugins.pubIds.sri.status.failed.duplicate', 'params' => [], 'message' => 'Duplicate SRI suffix.'];
            case 400:
            case 422:
                return ['key' => 'plugins.pubIds.sri.status.failed.validation', 'params' => [], 'message' => $message !== '' ? $message : 'The SRI API rejected the registration metadata.'];
            case 429:
                return ['key' => 'plugins.pubIds.sri.status.failed.ratelimit', 'params' => [], 'message' => 'Too many requests — try again shortly.'];
            case 500:
            case 502:
            case 503:
            case 504:
                return ['key' => 'plugins.pubIds.sri.status.failed.server', 'params' => [], 'message' => 'SRI API server error — try again shortly.'];
            default:
                return ['key' => 'plugins.pubIds.sri.status.failed.unknown', 'params' => [], 'message' => $message !== '' ? $message : 'SRI registration failed.'];
        }
    }

    /**
     * Convenience: resolve a thrown/transport error (network, timeout, TLS).
     *
     * @param string $message Transport-level message.
     */
    public function resolveTransportError(string $message): array
    {
        return ['key' => 'plugins.pubIds.sri.status.failed.network', 'params' => [], 'message' => $message !== '' ? $message : 'Could not reach the SRI API.'];
    }
}
