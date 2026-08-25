<?php

/**
 * @file tests/AdapterRoutingTest.php
 *
 * Guards the adapter URL-building contract without requiring an OJS runtime.
 */

class AdapterRoutingTest extends SriTestCase
{
    public function run(): array
    {
        $root = dirname(__DIR__);
        $adapters = [
            $root . '/plugin34/SriPubIdPlugin.php',
            $root . '/plugin33/SriPubIdPlugin.inc.php',
        ];

        foreach ($adapters as $adapter) {
            $source = (string)file_get_contents($adapter);
            $name = basename($adapter);

            $this->ok(str_contains($source, 'function componentUrl'), "{$name} has componentUrl helper");
            $this->same(
                0,
                preg_match_all('/\\$request->url\\(null, null, [\'\"]manage[\'\"]/', $source),
                "{$name} has no ambient manage URL calls"
            );
            $this->ok(
                str_contains($source, 'grid.settings.plugins.SettingsPluginGridHandler'),
                "{$name} targets the settings plugin component handler"
            );
        }

        return $this->result();
    }
}
