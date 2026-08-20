<?php

/**
 * @file tests/StatePresenterTest.php
 *
 * Verifies the stored-SRI + cached-status presentation logic.
 */

class StatePresenterTest extends SriTestCase
{
    public function run(): array
    {
        $p = new \SRI\Plugin\StatePresenter();

        // Nothing stored, nothing cached -> not registered
        $r = $p->present(null, null);
        $this->same('not_registered', $r['state'], 'nothing -> not registered');
        $this->same('plugins.pubIds.sri.status.notRegistered', $r['labelKey'], 'label not registered');

        // Full Sri stored, no cached status -> at least registered
        $r = $p->present('sri:2026.1001.jor.42+A', null);
        $this->same('sri:2026.1001.jor.42+A', $r['fullSri'], 'fullSri surfaced');
        $this->same(true, in_array($r['state'], ['active', 'pending_review', 'registered', 'withdrawn', 'tombstoned'], true), 'registered-ish state');

        // Full Sri + cached pending
        $r = $p->present('sri:2026.1001.jor.42+A', ['state' => 'pending_review', 'fullSri' => 'sri:2026.1001.jor.42+A']);
        $this->same('pending_review', $r['state'], 'pending state honored');
        $this->same('plugins.pubIds.sri.status.pending', $r['labelKey'], 'pending label');

        // Failed with cached reason
        $r = $p->present(null, ['state' => 'failed', 'reasonKey' => 'plugins.pubIds.sri.status.failed.quota']);
        $this->same('failed', $r['state'], 'failed state');
        $this->same('plugins.pubIds.sri.status.failed.quota', $r['reason'], 'failed reason key surfaced');

        return $this->result();
    }
}
