<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches;

/**
 * The list of patches this package ships, read from patches.json.
 *
 * Each entry mirrors Adobe's patch registry: id, applies_to, requires, cves,
 * released and sha256, plus the path of the patch file inside this package.
 */
class Manifest
{
    /** @var array<string, array{id: string, file: string, sha256: string, applies_to: string[], requires: string[], cves: string[]}> */
    private $patches = [];

    /**
     * @param array<int, array<string, mixed>> $patches
     */
    public function __construct(array $patches)
    {
        foreach ($patches as $patch) {
            $id = (string) $patch['id'];
            if (isset($this->patches[$id])) {
                throw new ManifestException(sprintf('Patch %s is listed more than once.', $id));
            }
            $this->patches[$id] = [
                'id' => $id,
                'file' => (string) $patch['file'],
                'sha256' => (string) $patch['sha256'],
                'applies_to' => array_map('strval', (array) $patch['applies_to']),
                'requires' => array_map('strval', (array) ($patch['requires'] ?? [])),
                'cves' => array_map('strval', (array) ($patch['cves'] ?? [])),
            ];
        }
    }

    public static function fromFile(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['patches']) || !is_array($data['patches'])) {
            throw new ManifestException(sprintf('%s is not a valid patch manifest.', $path));
        }

        return new self($data['patches']);
    }

    /**
     * Returns the patches for a Magento base version, each after the patches it requires.
     *
     * @return array<int, array{id: string, file: string, sha256: string, applies_to: string[], requires: string[], cves: string[]}>
     */
    public function forVersion(string $baseVersion): array
    {
        $selected = array_filter(
            $this->patches,
            static function (array $patch) use ($baseVersion): bool {
                return in_array($baseVersion, $patch['applies_to'], true);
            }
        );
        ksort($selected);

        $ordered = [];
        $state = [];
        foreach (array_keys($selected) as $id) {
            $this->visit($id, $selected, $state, $ordered, $baseVersion);
        }

        return $ordered;
    }

    /**
     * Depth-first topological sort over the requires graph.
     *
     * @param array<string, array<string, mixed>> $selected
     * @param array<string, string> $state
     * @param array<int, array<string, mixed>> $ordered
     */
    private function visit(string $id, array $selected, array &$state, array &$ordered, string $baseVersion): void
    {
        if (($state[$id] ?? '') === 'done') {
            return;
        }
        if (($state[$id] ?? '') === 'visiting') {
            throw new ManifestException(sprintf('Patch %s is part of a requires cycle.', $id));
        }
        if (!isset($selected[$id])) {
            throw new ManifestException(sprintf(
                'Patch %s is required for Magento %s but is not shipped by this package.',
                $id,
                $baseVersion
            ));
        }

        $state[$id] = 'visiting';
        foreach ($selected[$id]['requires'] as $required) {
            $this->visit($required, $selected, $state, $ordered, $baseVersion);
        }
        $state[$id] = 'done';
        $ordered[] = $selected[$id];
    }
}
