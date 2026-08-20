<?php

/**
 * @file plugins/pubIds/sri/classes/core/StatePresenter.php
 *
 * Pure, OJS-free presenter that turns a stored SRI + stored status into the
 * display state + translatable label key + reason used by the templates.
 */

namespace SRI\Plugin;

final class StatePresenter
{
    /**
     * @param string|null $storedSri Full SRI stored on the publication, if any.
     * @param array|null  $status    Cached per-submission status (writeStatus format).
     *
     * @return array{state: string, labelKey: string, fullSri: string, reason: string}
     */
    public function present(?string $storedSri, ?array $status): array
    {
        $fullSri = $storedSri ?? (string)($status['fullSri'] ?? '');
        $state = $status['state'] ?? null;

        if ($fullSri !== '') {
            // A full SRI exists: it is at least registered. A cached state that
            // maps to a meaningful status (pending/active/withdrawn/tombstoned)
            // sharpens the label; otherwise fall back to neutral "registered".
            if ($state !== null && $state !== '') {
                $candidate = (new StatusResolver())->fromRecordStatus($state);
                if (!in_array($candidate, [StatusResolver::STATE_NOT_REGISTERED, 'not_registered'], true)) {
                    $state = $candidate;
                }
            } else {
                $state = null;
            }
            $effective = $state ?? 'registered';
            return [
                'state' => $effective,
                'labelKey' => $this->labelFor($effective),
                'fullSri' => $fullSri,
                'reason' => $this->reasonText($status),
            ];
        }

        if ($state === null) {
            return [
                'state' => StatusResolver::STATE_NOT_REGISTERED,
                'labelKey' => 'plugins.pubIds.sri.status.notRegistered',
                'fullSri' => '',
                'reason' => '',
            ];
        }

        return [
            'state' => $state,
            'labelKey' => $this->labelFor($state),
            'fullSri' => '',
            'reason' => $this->reasonText($status),
        ];
    }

    private function labelFor(string $state): string
    {
        return match ($state) {
            StatusResolver::STATE_PENDING => 'plugins.pubIds.sri.status.pending',
            StatusResolver::STATE_ACTIVE => 'plugins.pubIds.sri.status.active',
            StatusResolver::STATE_WITHDRAWN => 'plugins.pubIds.sri.status.withdrawn',
            StatusResolver::STATE_TOMBSTONED => 'plugins.pubIds.sri.status.tombstoned',
            StatusResolver::STATE_FAILED => 'plugins.pubIds.sri.status.failed',
            'queued' => 'plugins.pubIds.sri.status.queued',
            StatusResolver::STATE_NOT_REGISTERED => 'plugins.pubIds.sri.status.notRegistered',
            default => 'plugins.pubIds.sri.status.registered',
        };
    }

    private function reasonText(?array $status): string
    {
        if (!$status) {
            return '';
        }
        if (!empty($status['reasonKey'])) {
            // Message is localized by the adapter via __(key, params); here we expose
            // the raw key so templates can call __() with the params.
            return (string)$status['reasonKey'];
        }
        return (string)($status['message'] ?? '');
    }
}
