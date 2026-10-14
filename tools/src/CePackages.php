<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Tools;

/**
 * The magento/* packages that make up Magento Open Source for a release,
 * resolved from magento/product-community-edition on the Mage-OS mirror.
 *
 * Used as a content check: a patch that touches any vendor/magento package
 * outside this set (an Adobe Commerce module, say) is not shippable, whatever
 * its registry metadata says.
 */
class CePackages
{
    private const METADATA_URL = 'https://mirror.mage-os.org/p2/%s.json';

    /** @var Http */
    private $http;

    /** @var array<string, array<int, array<string, mixed>>|null> */
    private $metadata = [];

    /** @var array<string, string[]|null> */
    private $cache = [];

    public function __construct(Http $http)
    {
        $this->http = $http;
    }

    /**
     * @return string[]|null package names, or null if the release is unknown to the mirror
     */
    public function forRelease(string $version): ?array
    {
        if (!array_key_exists($version, $this->cache)) {
            $this->cache[$version] = $this->resolve($version);
        }

        return $this->cache[$version];
    }

    /**
     * @return string[]|null
     */
    private function resolve(string $version): ?array
    {
        // The release itself must match exactly; only nested metapackage ranges may be approximated.
        $root = $this->version('magento/product-community-edition', $version, true);
        if ($root === null) {
            return null;
        }

        $found = [];
        $queue = [[array_keys($root['require'] ?? []), $root['require'] ?? []]];
        while ($queue !== []) {
            [$names, $constraints] = array_shift($queue);
            foreach ($names as $name) {
                if (isset($found[$name]) || !preg_match('#^(magento|adobe-commerce)/#', $name)) {
                    continue;
                }
                $found[$name] = true;
                if (strpos($name, 'magento/module-') === 0) {
                    continue;
                }
                // Metapackages (inventory, security-package, ...) pull in more modules.
                $nested = $this->version($name, (string) $constraints[$name]);
                if ($nested !== null && ($nested['type'] ?? '') === 'metapackage') {
                    $queue[] = [array_keys($nested['require'] ?? []), $nested['require'] ?? []];
                }
            }
        }
        $names = array_keys($found);
        sort($names);

        return $names;
    }

    /**
     * Finds the release of $package that a metapackage pins: an exact version,
     * or (unless $exact) a release starting with the constraint's numbers for simple ranges.
     *
     * @return array<string, mixed>|null
     */
    private function version(string $package, string $constraint, bool $exact = false): ?array
    {
        if (!array_key_exists($package, $this->metadata)) {
            [$status, $body] = $this->http->get(sprintf(self::METADATA_URL, $package));
            $this->metadata[$package] = $status === 200
                ? (json_decode($body, true)['packages'][$package] ?? null)
                : null;
        }
        foreach ($this->metadata[$package] ?? [] as $release) {
            if ($release['version'] === ltrim($constraint, '=v ')) {
                return $release;
            }
        }
        if ($exact) {
            return null;
        }
        // Metapackages occasionally use ranges; for the content check any matching release's require list will do.
        $prefix = preg_replace('/[^0-9.].*$/', '', ltrim($constraint, '^~>=v '));
        foreach ($this->metadata[$package] ?? [] as $release) {
            if ($prefix !== '' && strpos($release['version'], $prefix) === 0) {
                return $release;
            }
        }

        return null;
    }
}
