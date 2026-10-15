<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Test\Unit\Tools;

use DeployEcommerce\CommunityPatches\Tools\Gate;
use DeployEcommerce\CommunityPatches\Tools\Registry;
use DeployEcommerce\CommunityPatches\Tools\Sync;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/GateTest.php';

class SyncTest extends TestCase
{
    private const BASE = 'https://repo.magento.com/patch/';
    private const ID = '248p5-2026-10-001-CE';
    private const OLD = "diff --git a/vendor/magento/framework/A.php b/vendor/magento/framework/A.php\n--- a/vendor/magento/framework/A.php\n+++ b/vendor/magento/framework/A.php\n@@ -1 +1 @@\n-old\n+first\n";
    private const NEW = "diff --git a/vendor/magento/framework/A.php b/vendor/magento/framework/A.php\n--- a/vendor/magento/framework/A.php\n+++ b/vendor/magento/framework/A.php\n@@ -1 +1 @@\n-old\n+second\n";

    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sync-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/patches', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function sync(string $body): Sync
    {
        $http = new FakeHttp();
        $http->responses[self::BASE . self::ID . '.diff'] = [200, $body];
        $registry = new Registry(['_schema' => ['version' => '1.0', 'patch_base_url' => rtrim(self::BASE, '/')], 'patches' => [
            self::ID => [
                'applies_to' => ['2.4.8-p5'],
                'area' => 'CE',
                'requires' => [],
                'cves' => [],
                'released' => '2026-10-13',
                'file_name' => self::ID . '.diff',
                'sha256' => hash('sha256', $body),
            ],
        ]]);

        return new Sync($registry, new Gate($registry, $http, new FakeCePackages(['2.4.8-p5' => ['magento/framework']])), $this->root);
    }

    public function testKeepsTheOldVersionWhenAdobeReissuesAPatch(): void
    {
        file_put_contents($this->root . '/patches/' . self::ID . '.patch', self::OLD);
        $old = hash('sha256', self::OLD);

        $result = $this->sync(self::NEW)->run(true);

        $this->assertSame([self::ID => $old], $result['reissued']);
        $this->assertSame([], $result['added']);
        $this->assertSame(self::NEW, file_get_contents($this->root . '/patches/' . self::ID . '.patch'));
        $archived = sprintf('%s/patches/superseded/%s.%s.patch', $this->root, self::ID, substr($old, 0, 12));
        $this->assertSame(self::OLD, file_get_contents($archived));
        $this->assertSame(
            sprintf("%s  %s\n", $old, basename($archived)),
            file_get_contents($archived . '.sha256')
        );
    }

    public function testDryRunReportsAReissueWithoutWriting(): void
    {
        file_put_contents($this->root . '/patches/' . self::ID . '.patch', self::OLD);

        $result = $this->sync(self::NEW)->run(false);

        $this->assertSame([self::ID], array_keys($result['reissued']));
        $this->assertSame(self::OLD, file_get_contents($this->root . '/patches/' . self::ID . '.patch'));
        $this->assertDirectoryDoesNotExist($this->root . '/patches/superseded');
    }

    public function testAnUnchangedPatchIsNotReissued(): void
    {
        file_put_contents($this->root . '/patches/' . self::ID . '.patch', self::NEW);

        $result = $this->sync(self::NEW)->run(true);

        $this->assertSame([], $result['reissued']);
        $this->assertDirectoryDoesNotExist($this->root . '/patches/superseded');
    }
}
