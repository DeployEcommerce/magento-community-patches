<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Tools;

/**
 * Brings patches/ and patches.json in line with what the registry allows us to ship.
 */
class Sync
{
    /** @var Registry */
    private $registry;

    /** @var Gate */
    private $gate;

    /** @var string */
    private $root;

    public function __construct(Registry $registry, Gate $gate, string $root)
    {
        $this->registry = $registry;
        $this->gate = $gate;
        $this->root = $root;
    }

    /**
     * @return array{added: string[], removed: string[], reissued: array<string, string>, rejected: array<string, string>}
     */
    public function run(bool $write): array
    {
        $ids = array_keys(array_filter(
            $this->registry->patches(),
            static function (array $entry): bool {
                return ($entry['area'] ?? null) === 'CE';
            }
        ));
        sort($ids);

        $shippable = [];
        $rejected = [];
        foreach ($ids as $id) {
            $reason = $this->gate->reject($id);
            if ($reason === null) {
                $shippable[] = $id;
            } else {
                $rejected[$id] = $reason;
            }
        }

        $existing = $this->shippedIds();
        // Adobe can re-issue a patch under the same id. The old version is kept in
        // patches/superseded/ and the change is reported, so the sync pull request is where it is reviewed.
        $reissued = [];
        foreach ($shippable as $id) {
            $file = $this->patchPath($id);
            if (is_file($file) && file_get_contents($file) !== $this->gate->content($id)) {
                $reissued[$id] = hash_file('sha256', $file);
            }
        }

        $added = array_values(array_diff($shippable, $existing));
        $removed = array_values(array_diff($existing, $shippable));

        if ($write) {
            foreach ($reissued as $id => $sha256) {
                $this->archive($id, $sha256);
            }
            foreach ($removed as $id) {
                unlink($this->patchPath($id));
                unlink($this->patchPath($id) . '.sha256');
            }
            foreach ($shippable as $id) {
                file_put_contents($this->patchPath($id), $this->gate->content($id));
                file_put_contents(
                    $this->patchPath($id) . '.sha256',
                    sprintf("%s  %s.patch\n", $this->registry->get($id)['sha256'], $id)
                );
            }
            $this->writeManifest($shippable);
        }

        return ['added' => $added, 'removed' => $removed, 'reissued' => $reissued, 'rejected' => $rejected];
    }

    /**
     * Keeps the version of a patch we shipped before Adobe re-issued it.
     */
    private function archive(string $id, string $sha256): void
    {
        $dir = $this->root . '/patches/superseded';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create %s.', $dir));
        }
        $name = sprintf('%s.%s.patch', $id, substr($sha256, 0, 12));
        copy($this->patchPath($id), $dir . '/' . $name);
        file_put_contents($dir . '/' . $name . '.sha256', sprintf("%s  %s\n", $sha256, $name));
    }

    /**
     * @param string[] $ids
     */
    private function writeManifest(array $ids): void
    {
        $patches = [];
        foreach ($ids as $id) {
            $entry = $this->registry->get($id);
            $patches[] = [
                'id' => $id,
                'file' => sprintf('patches/%s.patch', $id),
                'sha256' => $entry['sha256'],
                'applies_to' => $this->gate->releases($id),
                'requires' => array_values((array) ($entry['requires'] ?? [])),
                'cves' => array_values((array) ($entry['cves'] ?? [])),
                'released' => $entry['released'] ?? null,
            ];
        }
        file_put_contents(
            $this->root . '/patches.json',
            json_encode(['patches' => $patches], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
    }

    /**
     * @return string[]
     */
    private function shippedIds(): array
    {
        $ids = array_map(
            static function (string $file): string {
                return basename($file, '.patch');
            },
            glob($this->root . '/patches/*.patch') ?: []
        );
        sort($ids);

        return $ids;
    }

    private function patchPath(string $id): string
    {
        return sprintf('%s/patches/%s.patch', $this->root, $id);
    }
}
