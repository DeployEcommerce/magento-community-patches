<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Installs the plugin into throwaway projects that contain stand-in Magento
 * packages, then checks composer applies the patches on its own.
 */
class ComposerInstallTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcp-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private static function change(string $file, string $from, string $to): string
    {
        $path = "vendor/magento/magento2-base/$file";

        return "diff --git a/$path b/$path\n--- a/$path\n+++ b/$path\n@@ -1 +1 @@\n-$from\n+$to\n";
    }

    public function testPatchIsAppliedOnInstallAndSkippedOnReinstall(): void
    {
        $this->buildProject(['248p5-2026-10-001-CE' => self::change('hello.txt', 'unpatched', 'patched')]);

        [$code, $output] = $this->composer('install');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Applied 248p5-2026-10-001-CE', $output);
        $this->assertSame("patched\n", $this->read('hello.txt'));

        [$code, $output] = $this->composer('install');
        $this->assertSame(0, $code, $output);
        $this->assertStringNotContainsString('Applied', $output);
    }

    public function testInstallFailsWhenThePatchHashDoesNotMatch(): void
    {
        $this->buildProject(['248p5-2026-10-001-CE' => self::change('hello.txt', 'unpatched', 'patched')], [], true);

        [$code, $output] = $this->composer('install');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('does not match its expected sha256', $output);
        $this->assertSame("unpatched\n", $this->read('hello.txt'));
    }

    public function testAFailingPatchLeavesEveryProjectFileUntouched(): void
    {
        $this->buildProject([
            '248p5-2026-09-001-CE' => self::change('hello.txt', 'unpatched', 'patched'),
            '248p5-2026-10-001-CE' => self::change('email.txt', 'something else', 'patched'),
        ]);

        [$code, $output] = $this->composer('install');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('248p5-2026-10-001-CE cannot be applied', $output);
        $this->assertStringContainsString('No project files were changed', $output);
        $this->assertSame("unpatched\n", $this->read('hello.txt'));
    }

    public function testASequentialChainCanBeReinstalled(): void
    {
        $this->buildProject([
            '248p5-2026-09-001-CE' => self::change('hello.txt', 'unpatched', 'patched'),
            '248p5-2026-10-001-CE' => self::change('hello.txt', 'patched', 'patched again'),
        ]);

        [$code, $output] = $this->composer('install');
        $this->assertSame(0, $code, $output);
        $this->assertSame("patched again\n", $this->read('hello.txt'));

        // September can no longer be recognised on its own once October is applied.
        [$code, $output] = $this->composer('install');
        $this->assertSame(0, $code, $output);
        $this->assertStringNotContainsString('Applied', $output);
    }

    public function testInstallFailsOnAdobeCommerceAndPointsToCloudPatches(): void
    {
        $this->buildProject(['248p5-2026-10-001-CE' => self::change('hello.txt', 'unpatched', 'patched')], [], false, [], true);

        [$code, $output] = $this->composer('install');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('magento/product-enterprise-edition is installed', $output);
        $this->assertStringContainsString('    composer remove deployecommerce/magento-community-patches', $output);
        $this->assertStringContainsString('    composer require magento/magento-cloud-patches', $output);
        $this->assertSame("unpatched\n", $this->read('hello.txt'));
    }

    public function testBeforeEcePatchesRunsOnlyFilesItWillNeverPatchAreTouched(): void
    {
        $hello = self::change('hello.txt', 'unpatched', 'patched');
        $email = self::change('email.txt', 'unpatched', 'patched');
        $this->buildProject(
            ['248p5-2026-10-001-CE' => $hello . $email],
            [self::CLOUD_OCT => ['Patch for Oct 2026 monthly release', $hello]]
        );

        [$code, $output] = $this->composer('install');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('leaves out changes to vendor/magento/magento2-base/email.txt', $output);
        $this->assertSame("unpatched\n", $this->read('hello.txt'), 'left for ece-patches');
        $this->assertSame("patched\n", $this->read('email.txt'));

        // ece-patches then runs in the deploy pipeline...
        $this->applyCloudPatch(self::CLOUD_OCT);
        $this->assertSame("patched\n", $this->read('hello.txt'));

        // ...and the next install finds everything in place.
        [$code, $output] = $this->composer('install');
        $this->assertSame(0, $code, $output);
        $this->assertStringNotContainsString('Applied', $output);
    }

    public function testBeforeEcePatchesRunsAPatchSharingItsFilesIsHeldBack(): void
    {
        $this->buildProject(
            [
                '248p5-2026-09-001-CE' => self::change('hello.txt', 'unpatched', 'patched'),
                '248p5-2026-10-001-CE' => self::change('hello.txt', 'patched', 'patched again'),
            ],
            ['MCLOUD-15053__Sep_2026_monthly_release_ce__2.4.8-p5.patch' => [
                'Patch for Sep 2026 monthly release',
                self::change('hello.txt', 'unpatched', 'patched'),
            ]]
        );

        [$code, $output] = $this->composer('install');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('248p5-2026-10-001-CE was not applied because it changes files that ece-patches has not patched yet', $output);
        $this->assertSame("unpatched\n", $this->read('hello.txt'));
    }

    public function testRunsAfterEcePatchesInRootScriptsAndFillsTheGap(): void
    {
        $hello = self::change('hello.txt', 'unpatched', 'patched');
        $email = self::change('email.txt', 'unpatched', 'patched');
        $this->buildProject(
            ['248p5-2026-10-001-CE' => $hello . $email],
            [self::CLOUD_OCT => ['Patch for Oct 2026 monthly release', $hello]],
            false,
            // Stands in for `vendor/bin/ece-patches apply` in the root scripts. A first install
            // without a lock file runs as an update, so both events need it, as in real projects.
            [
                'post-install-cmd' => ['git apply vendor/magento/magento-cloud-patches/patches/' . self::CLOUD_OCT],
                'post-update-cmd' => ['git apply vendor/magento/magento-cloud-patches/patches/' . self::CLOUD_OCT],
            ]
        );

        [$code, $output] = $this->composer('install');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Applied 248p5-2026-10-001-CE (MCLOUD-15634', $output);
        $this->assertSame("patched\n", $this->read('hello.txt'));
        $this->assertSame("patched\n", $this->read('email.txt'));
    }

    private const CLOUD_OCT = 'MCLOUD-15634__Oct_2026_monthly_release_ce__2.4.8-p5.patch';

    private function applyCloudPatch(string $file): void
    {
        exec(sprintf(
            'cd %s && git apply %s 2>&1',
            escapeshellarg($this->dir . '/project'),
            escapeshellarg('vendor/magento/magento-cloud-patches/patches/' . $file)
        ), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    /**
     * @param array<string, string> $patches id => diff, in requires order
     * @param array<string, array{0: string, 1: string}> $cloudPatches file name => [title, diff], shipped by a stand-in magento-cloud-patches
     * @param array<string, string[]> $scripts root composer.json scripts
     * @param bool $enterprise also install a stand-in magento/product-enterprise-edition
     */
    private function buildProject(array $patches, array $cloudPatches = [], bool $badHash = false, array $scripts = [], bool $enterprise = false): void
    {
        $root = dirname(__DIR__, 2);
        $repositories = [];
        $require = ['deployecommerce/magento-community-patches' => '1.0.0', 'magento/magento2-base' => '2.4.8-p5'];

        $plugin = $this->dir . '/plugin';
        mkdir($plugin . '/patches', 0777, true);
        exec(sprintf('cp -R %s %s', escapeshellarg($root . '/src'), escapeshellarg($plugin)));
        $pluginComposer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $pluginComposer['version'] = '1.0.0';
        unset($pluginComposer['require-dev'], $pluginComposer['autoload-dev']);
        $this->writeJson($plugin . '/composer.json', $pluginComposer);
        $manifest = [];
        $previous = [];
        foreach ($patches as $id => $diff) {
            file_put_contents("$plugin/patches/$id.patch", $diff);
            $manifest[] = [
                'id' => $id,
                'file' => "patches/$id.patch",
                'sha256' => $badHash ? str_repeat('0', 64) : hash('sha256', $diff),
                'applies_to' => ['2.4.8-p5'],
                'requires' => $previous,
                'cves' => [],
            ];
            $previous = [$id];
        }
        $this->writeJson($plugin . '/patches.json', ['patches' => $manifest]);
        $repositories[] = ['type' => 'path', 'url' => $plugin, 'options' => ['symlink' => false]];

        $base = $this->dir . '/base';
        mkdir($base);
        file_put_contents($base . '/hello.txt', "unpatched\n");
        file_put_contents($base . '/email.txt', "unpatched\n");
        $this->writeJson($base . '/composer.json', ['name' => 'magento/magento2-base', 'version' => '2.4.8-p5']);
        $repositories[] = ['type' => 'path', 'url' => $base, 'options' => ['symlink' => false]];

        if ($cloudPatches !== []) {
            $cloud = $this->dir . '/cloud';
            mkdir($cloud . '/patches', 0777, true);
            $config = [];
            foreach ($cloudPatches as $file => [$title, $diff]) {
                file_put_contents("$cloud/patches/$file", $diff);
                $config['magento/magento2-base'][$title] = ['2.4.8-p5' => $file];
            }
            $this->writeJson($cloud . '/patches.json', $config);
            $this->writeJson($cloud . '/composer.json', ['name' => 'magento/magento-cloud-patches', 'version' => '1.1.22']);
            $repositories[] = ['type' => 'path', 'url' => $cloud, 'options' => ['symlink' => false]];
            $require['magento/magento-cloud-patches'] = '1.1.22';
        }

        if ($enterprise) {
            $ee = $this->dir . '/ee';
            mkdir($ee);
            $this->writeJson($ee . '/composer.json', ['name' => 'magento/product-enterprise-edition', 'version' => '2.4.8-p5']);
            $repositories[] = ['type' => 'path', 'url' => $ee, 'options' => ['symlink' => false]];
            $require['magento/product-enterprise-edition'] = '2.4.8-p5';
        }

        mkdir($this->dir . '/project');
        $this->writeJson($this->dir . '/project/composer.json', [
            'name' => 'test/project',
            'repositories' => $repositories,
            'require' => $require,
            'scripts' => (object) $scripts,
            'config' => ['allow-plugins' => ['deployecommerce/magento-community-patches' => true]],
        ]);
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($this->dir . '/project/vendor/magento/magento2-base/' . $file);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function composer(string $command): array
    {
        // Run composer under the PHP that runs the tests, so each PHP version is really exercised.
        exec(sprintf(
            'cd %s && COMPOSER_HOME=%s %s %s %s --no-interaction --no-progress 2>&1',
            escapeshellarg($this->dir . '/project'),
            escapeshellarg($this->dir . '/composer-home'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(trim((string) shell_exec('command -v composer'))),
            $command
        ), $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $file, array $data): void
    {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
