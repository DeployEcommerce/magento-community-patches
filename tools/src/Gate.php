<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Tools;

/**
 * Decides which registry patches this package may ship.
 *
 * A patch ships only when every one of these holds; anything unexpected rejects it:
 *  - its id ends in -CE and its registry area is CE
 *  - the registry attaches no entitlement to it
 *  - Adobe serves it without authentication: HTTP 200 straight away, no redirect
 *  - the downloaded bytes match the registry sha256
 *  - every file it touches belongs to Magento Open Source
 *  - every patch it requires passes the same checks
 *
 * It ships only for the releases in its applies_to that are public Magento Open Source
 * releases and that all of its prerequisites also ship for; with none left, it is rejected.
 *
 * Patches that fail the metadata checks are never downloaded.
 */
class Gate
{
    /** @var Registry */
    private $registry;

    /** @var Http */
    private $http;

    /** @var CePackages */
    private $cePackages;

    /** @var array<string, string|null> id => rejection reason, or null when shippable */
    private $verdicts = [];

    /** @var array<string, string> id => patch content */
    private $contents = [];

    /** @var array<string, string[]> id => releases it ships for */
    private $releases = [];

    public function __construct(Registry $registry, Http $http, CePackages $cePackages)
    {
        $this->registry = $registry;
        $this->http = $http;
        $this->cePackages = $cePackages;
    }

    /**
     * @return string|null why the patch cannot ship, or null if it can
     */
    public function reject(string $id): ?string
    {
        if (!array_key_exists($id, $this->verdicts)) {
            $this->verdicts[$id] = 'requires cycle';
            $this->verdicts[$id] = $this->check($id);
        }

        return $this->verdicts[$id];
    }

    public function content(string $id): string
    {
        if ($this->reject($id) !== null) {
            throw new \LogicException(sprintf('Patch %s is not shippable.', $id));
        }

        return $this->contents[$id];
    }

    /**
     * @return string[] the releases a shippable patch is published for
     */
    public function releases(string $id): array
    {
        if ($this->reject($id) !== null) {
            throw new \LogicException(sprintf('Patch %s is not shippable.', $id));
        }

        return $this->releases[$id];
    }

    private function check(string $id): ?string
    {
        $entry = $this->registry->get($id);
        if ($entry === null) {
            return 'not in the registry';
        }
        if (substr($id, -3) !== '-CE' || ($entry['area'] ?? null) !== 'CE') {
            return 'not a Magento Open Source (CE) patch';
        }
        if (!empty($entry['entitlements'])) {
            return sprintf('restricted by entitlement: %s', implode(', ', (array) $entry['entitlements']));
        }
        $releases = array_values(array_filter(
            array_map('strval', (array) $entry['applies_to']),
            function (string $version): bool {
                return $this->cePackages->forRelease($version) !== null;
            }
        ));
        foreach ((array) ($entry['requires'] ?? []) as $required) {
            $reason = $this->reject((string) $required);
            if ($reason !== null) {
                return sprintf('requires %s, which cannot ship (%s)', $required, $reason);
            }
            $releases = array_values(array_intersect($releases, $this->releases[(string) $required]));
        }
        if ($releases === []) {
            return 'applies to no public Magento Open Source release';
        }

        [$status, $body] = $this->http->get($this->registry->downloadUrl($id));
        if ($status !== 200) {
            return sprintf('not publicly downloadable (HTTP %d)', $status);
        }
        if (!hash_equals((string) $entry['sha256'], hash('sha256', $body))) {
            return 'download does not match the registry sha256';
        }

        $reason = $this->checkPaths($body, $releases);
        if ($reason !== null) {
            return $reason;
        }
        $this->contents[$id] = $body;
        $this->releases[$id] = $releases;

        return null;
    }

    /**
     * @param string[] $releases
     */
    private function checkPaths(string $patch, array $releases): ?string
    {
        preg_match_all('#^(?:---|\+\+\+) (?:a|b)/(\S+)#m', $patch, $matches);
        $paths = array_unique($matches[1]);
        if ($paths === []) {
            return 'patch touches no files';
        }
        foreach ($releases as $version) {
            $packages = (array) $this->cePackages->forRelease($version);
            foreach ($paths as $path) {
                if (strpos($path, '..') !== false) {
                    return sprintf('unsafe path %s', $path);
                }
                if (preg_match('#^vendor/(magento/[^/]+)/#', $path, $m)) {
                    if (!in_array($m[1], $packages, true)) {
                        return sprintf('touches %s, which is not part of Magento Open Source %s', $m[1], $version);
                    }
                } elseif (strpos($path, 'vendor/') === 0 && strpos($path, 'vendor/bin/') !== 0) {
                    return sprintf('touches %s, outside Magento Open Source packages', $path);
                }
            }
        }

        return null;
    }
}
