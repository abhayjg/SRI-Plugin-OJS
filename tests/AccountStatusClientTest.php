<?php

/**
 * @file tests/AccountStatusClientTest.php
 *
 * Verifies the version-independent client contract for account status polling.
 */

class AccountStatusClientTest extends SriTestCase
{
    public function run(): array
    {
        $calls = [];
        $transport = function (string $method, string $url, string $body, array $headers) use (&$calls): array {
            $calls[] = compact('method', 'url', 'body', 'headers');
            return [
                'status' => 200,
                'body' => json_encode([
                    'data' => [
                        'accountStatus' => 'ACTIVE',
                        'partnerStatus' => 'ACTIVE',
                        'membership' => [
                            'expiresAt' => '2027-01-15T00:00:00+00:00',
                            'daysRemaining' => 148,
                        ],
                        'quota' => [
                            'sriQuota' => 500,
                            'srisUsed' => 312,
                            'remaining' => 188,
                        ],
                        'prefixQuota' => [
                            'assigned' => 2,
                            'used' => 2,
                            'remaining' => 0,
                        ],
                        'prefixes' => [
                            [
                                'prefix' => 1001,
                                'status' => 'ACTIVE',
                                'autoApprove' => false,
                                'journalName' => 'Journal of SRI',
                            ],
                        ],
                        'blockedReason' => null,
                    ],
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $client = new \SRI\Plugin\ApiClient(
            'https://api.example.org/api/v1',
            'sri_test_key',
            5,
            15,
            $transport,
            'test'
        );
        $service = new \SRI\Plugin\RegistrationService($client);

        $this->isTrue(method_exists($service, 'checkAccountStatus'), 'account status client method exists');
        if (method_exists($service, 'checkAccountStatus')) {
            $result = $service->checkAccountStatus();
            $this->isTrue($result['success'], 'account status request succeeds');
            $this->same('ACTIVE', $result['accountStatus'], 'account status returned');
            $this->same(188, $result['quota']['remaining'], 'quota remaining returned');
            $this->same(1001, $result['prefixes'][0]['prefix'], 'prefix returned');
        }

        $this->same(1, count($calls), 'one account status request made');
        $this->same('GET', $calls[0]['method'] ?? null, 'account status uses GET');
        $this->same('https://api.example.org/api/v1/account/status', $calls[0]['url'] ?? null, 'account status path');

        return $this->result();
    }
}
