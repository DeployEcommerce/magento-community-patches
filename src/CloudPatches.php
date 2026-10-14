<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches;

use Composer\Semver\Semver;

/**
 * Finds the copy of an Adobe patch that an installed magento/magento-cloud-patches
 * already ships, so the two never fight over the same files.
 *
 * ece-patches applies its own copies. If this plugin applied Adobe's published
 * version of the same patch first, ece-patches would find neither its patch nor
 * its reverse applicable and fail the deploy. Applying the cloud copy instead
 * lets ece-patches recognise it as already applied.
 */
class CloudPatches
{
    private const BASE_PACKAGE = 'magento/magento2-base';

    private const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

    /** @var string */
    private $dir;

    /** @var array<string, array<string, string>> title => [constraint => file] */
    private $basePatches;

    public function __construct(string $dir)
    {
        $this->dir = $dir;
        $config = json_decode((string) @file_get_contents($dir . '/patches.json'), true);
        $this->basePatches = is_array($config[self::BASE_PACKAGE] ?? null) ? $config[self::BASE_PACKAGE] : [];
    }

    /**
     * Returns the cloud-patches file that corresponds to a registry patch, if any,
     * with its position in patches.json, which is the order ece-patches applies them in.
     *
     * A cloud entry matches when its title names the same monthly release as the
     * registry id (e.g. "Patch for Oct 2026 monthly release" for 248p5-2026-10-001-CE)
     * or mentions one of the registry entry's CVEs.
     *
     * @param array{id: string, cves?: string[]} $patch
     * @return array{0: string, 1: int}|null file path and apply position
     */
    public function find(array $patch, string $baseVersion): ?array
    {
        $monthly = preg_match('/-(\d{4})-(\d{2})-\d{3}-CE$/', $patch['id'], $m)
            ? sprintf('%s %s monthly release', self::MONTHS[(int) $m[2] - 1], $m[1])
            : null;

        $position = -1;
        foreach ($this->basePatches as $title => $versions) {
            $position++;
            $lower = strtolower((string) $title);
            $matches = ($monthly !== null && strpos($lower, $monthly) !== false)
                || array_filter($patch['cves'] ?? [], static function (string $cve) use ($title): bool {
                    return stripos((string) $title, $cve) !== false;
                });
            if (!$matches || !is_array($versions)) {
                continue;
            }
            foreach ($versions as $constraint => $file) {
                $file = is_array($file) ? ($file['file'] ?? null) : $file;
                if (is_string($file) && Semver::satisfies($baseVersion, (string) $constraint)) {
                    $path = $this->dir . '/patches/' . $file;

                    return is_file($path) ? [$path, $position] : null;
                }
            }
        }

        return null;
    }
}
