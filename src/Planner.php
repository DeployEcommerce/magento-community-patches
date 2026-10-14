<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches;

/**
 * Turns the patches for a release into the steps to apply, split by who owns them.
 *
 * Without magento/magento-cloud-patches every step is Adobe's published patch.
 *
 * With it, ece-patches applies its own copies of some patches. Those copies are listed
 * as `cloud` steps, in the order ece-patches applies them, so the plugin can tell whether
 * ece-patches has already run. Patches it does not cover are `own` steps. Files that
 * Adobe's published patch changes but the cloud copy leaves out are `gap` steps, but only
 * when no cloud patch touches them at all, so they can never collide with ece-patches.
 */
class Planner
{
    /** @var CloudPatches|null */
    private $cloud;

    /** @var string[] */
    private $warnings = [];

    public function __construct(?CloudPatches $cloud)
    {
        $this->cloud = $cloud;
    }

    /**
     * @param array<int, array{id: string, file: string, sha256: string, cves?: string[]}> $patches in apply order
     * @return array{cloud: array<int, array{label: string, content: string}>, own: array<int, array{label: string, content: string}>, gap: array<int, array{label: string, content: string}>}
     */
    public function plan(array $patches, string $packageDir, string $baseVersion): array
    {
        $cloud = [];
        $own = [];
        $missing = [];
        foreach ($patches as $patch) {
            $file = $packageDir . '/' . $patch['file'];
            $content = is_file($file) ? (string) file_get_contents($file) : '';
            if (!hash_equals($patch['sha256'], hash('sha256', $content))) {
                throw new PatchException(sprintf(
                    'Patch %s does not match its expected sha256; refusing to apply it.',
                    $patch['id']
                ));
            }

            $found = $this->cloud !== null ? $this->cloud->find($patch, $baseVersion) : null;
            if ($found === null) {
                $own[] = ['label' => $patch['id'], 'content' => $content];
                continue;
            }

            [$cloudFile, $position] = $found;
            $cloudContent = (string) file_get_contents($cloudFile);
            $cloud[$position] = [
                'label' => sprintf('%s (magento-cloud-patches copy %s)', $patch['id'], basename($cloudFile)),
                'content' => $cloudContent,
            ];
            $covered = Diff::paths($cloudContent);
            foreach (Diff::sections($content) as $path => $section) {
                if (!in_array($path, $covered, true)) {
                    $missing[$patch['id'] . ' (' . basename($cloudFile) . ')'][$path] = $section;
                }
            }
        }
        ksort($cloud);
        $cloud = array_values($cloud);

        $cloudPaths = [];
        foreach ($cloud as $step) {
            $cloudPaths = array_merge($cloudPaths, Diff::paths($step['content']));
        }

        $gap = [];
        foreach ($missing as $label => $sections) {
            foreach ($sections as $path => $section) {
                if (in_array($path, $cloudPaths, true)) {
                    unset($sections[$path]);
                    $this->warnings[] = sprintf(
                        'The magento-cloud-patches copy of %s leaves out changes to %s, which another '
                        . 'magento-cloud-patches patch also changes, so they cannot be added safely.',
                        $label,
                        $path
                    );
                }
            }
            if ($sections !== []) {
                $paths = implode(', ', array_keys($sections));
                $gap[] = [
                    'label' => sprintf('%s changes missing from the magento-cloud-patches copy (%s)', $label, $paths),
                    'content' => implode('', $sections),
                ];
                $this->warnings[] = sprintf(
                    'The magento-cloud-patches copy of %s leaves out changes to %s; they are applied from Adobe\'s published patch.',
                    $label,
                    $paths
                );
            }
        }

        return ['cloud' => $cloud, 'own' => $own, 'gap' => $gap];
    }

    /**
     * Keeps only the steps that touch no file any cloud step touches, so they are safe to
     * apply before ece-patches runs. Warns about the rest.
     *
     * @param array<int, array{label: string, content: string}> $cloud
     * @param array<int, array{label: string, content: string}> $steps
     * @return array<int, array{label: string, content: string}>
     */
    public function independentOf(array $cloud, array $steps): array
    {
        $cloudPaths = [];
        foreach ($cloud as $step) {
            $cloudPaths = array_merge($cloudPaths, Diff::paths($step['content']));
        }

        return array_values(array_filter($steps, function (array $step) use ($cloudPaths): bool {
            if (array_intersect(Diff::paths($step['content']), $cloudPaths) === []) {
                return true;
            }
            $this->warnings[] = sprintf(
                '%s was not applied because it changes files that ece-patches has not patched yet. '
                . 'Run `vendor/bin/ece-patches apply` from the root composer.json post-install-cmd and '
                . 'post-update-cmd scripts so it runs before this plugin.',
                $step['label']
            );

            return false;
        }));
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
