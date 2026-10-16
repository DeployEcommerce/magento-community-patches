<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Test\Unit\Tools;

use DeployEcommerce\CommunityPatches\Tools\CePackages;
use DeployEcommerce\CommunityPatches\Tools\Gate;
use DeployEcommerce\CommunityPatches\Tools\Http;
use DeployEcommerce\CommunityPatches\Tools\Registry;
use DeployEcommerce\CommunityPatches\Tools\TransientHttpException;
use PHPUnit\Framework\TestCase;

class GateTest extends TestCase
{
    private const BASE = 'https://repo.magento.com/patch/';

    /** @var FakeHttp */
    private $http;

    /** @var array<string, array<string, mixed>> */
    private $entries = [];

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
    }

    private static function diff(string $path): string
    {
        return "diff --git a/$path b/$path\n--- a/$path\n+++ b/$path\n@@ -1 +1 @@\n-old\n+new\n";
    }

    private function add(string $id, string $body, array $overrides = [], int $status = 200): void
    {
        $this->entries[$id] = array_merge([
            'applies_to' => ['2.4.8-p5'],
            'area' => 'CE',
            'requires' => [],
            'cves' => [],
            'released' => '2026-10-13',
            'file_name' => "$id.diff",
            'sha256' => hash('sha256', $body),
        ], $overrides);
        $this->http->responses[self::BASE . "$id.diff"] = [$status, $body];
    }

    private function gate(): Gate
    {
        $registry = new Registry(['_schema' => ['version' => '1.0', 'patch_base_url' => rtrim(self::BASE, '/')], 'patches' => $this->entries]);

        return new Gate($registry, $this->http, new FakeCePackages([
            '2.4.8-p5' => ['magento/framework', 'magento/module-customer'],
            '2.4.8-p4' => ['magento/framework', 'magento/module-customer'],
        ]));
    }

    public function testShipsAPublicCePatchWithItsPrerequisites(): void
    {
        $this->add('248p5-2026-09-001-CE', self::diff('vendor/magento/framework/A.php'));
        $this->add('248p5-2026-10-001-CE', self::diff('vendor/magento/module-customer/B.php'), ['requires' => ['248p5-2026-09-001-CE']]);

        $gate = $this->gate();
        $this->assertNull($gate->reject('248p5-2026-10-001-CE'));
        $this->assertSame(self::diff('vendor/magento/module-customer/B.php'), $gate->content('248p5-2026-10-001-CE'));
    }

    public function testRejectsEntitledCePatchWithoutDownloadingIt(): void
    {
        $this->add('246p15-2026-10-001-CE', self::diff('vendor/magento/framework/A.php'), ['entitlements' => ['ee']]);

        $this->assertSame('restricted by entitlement: ee', $this->gate()->reject('246p15-2026-10-001-CE'));
        $this->assertSame([], $this->http->requested);
    }

    public function testRejectsCommercePatchWithoutDownloadingIt(): void
    {
        $this->add('248p5-2026-10-001-EE', self::diff('vendor/magento/module-reward/A.php'), ['area' => 'EE']);

        $this->assertSame('not a Magento Open Source (CE) patch', $this->gate()->reject('248p5-2026-10-001-EE'));
        $this->assertSame([], $this->http->requested);
    }

    public function testRejectsAPatchThatRedirectsToALogin(): void
    {
        $this->add('248p5-2026-10-001-CE', self::diff('vendor/magento/framework/A.php'), [], 302);

        $this->assertSame('not publicly downloadable (HTTP 302)', $this->gate()->reject('248p5-2026-10-001-CE'));
    }

    public function testRejectsADownloadThatDoesNotMatchTheRegistryHash(): void
    {
        $this->add('248p5-2026-10-001-CE', self::diff('vendor/magento/framework/A.php'), ['sha256' => str_repeat('0', 64)]);

        $this->assertSame('download does not match the registry sha256', $this->gate()->reject('248p5-2026-10-001-CE'));
    }

    public function testRejectsAPatchTouchingACommerceOnlyPackage(): void
    {
        $this->add('248p5-2026-10-001-CE', self::diff('vendor/magento/module-reward/A.php'));

        $this->assertSame(
            'touches magento/module-reward, which is not part of Magento Open Source 2.4.8-p5',
            $this->gate()->reject('248p5-2026-10-001-CE')
        );
    }

    public function testRejectsAPatchWhosePrerequisiteCannotShipAndDoesNotDownloadIt(): void
    {
        $this->add('248p5-2026-09-001-CE', self::diff('vendor/magento/framework/A.php'), ['entitlements' => ['ee']]);
        $this->add('248p5-2026-10-001-CE', self::diff('vendor/magento/framework/B.php'), ['requires' => ['248p5-2026-09-001-CE']]);

        $this->assertSame(
            'requires 248p5-2026-09-001-CE, which cannot ship (restricted by entitlement: ee)',
            $this->gate()->reject('248p5-2026-10-001-CE')
        );
        $this->assertSame([], $this->http->requested);
    }

    public function testRejectsAPatchForNoPublicOpenSourceReleaseWithoutDownloadingIt(): void
    {
        $this->add('244p18-VULN-39341-CE', self::diff('vendor/magento/framework/A.php'), ['applies_to' => ['2.4.4-p18']]);

        $this->assertSame('applies to no public Magento Open Source release', $this->gate()->reject('244p18-VULN-39341-CE'));
        $this->assertSame([], $this->http->requested);
    }

    public function testShipsOnlyForPublicReleasesTheChainAlsoShipsFor(): void
    {
        $this->add('a-CE', self::diff('vendor/magento/framework/A.php'), ['applies_to' => ['2.4.8-p5', '2.4.8-p4', '2.4.4-p14']]);
        $this->add('b-CE', self::diff('vendor/magento/framework/B.php'), ['applies_to' => ['2.4.8-p5', '2.4.8-p4'], 'requires' => ['a-CE']]);

        $gate = $this->gate();
        $this->assertSame(['2.4.8-p5', '2.4.8-p4'], $gate->releases('a-CE'));
        $this->assertSame(['2.4.8-p5', '2.4.8-p4'], $gate->releases('b-CE'));
    }

    public function testRejectsAPathTraversal(): void
    {
        $this->add('248p5-2026-10-001-CE', self::diff('vendor/magento/framework/../../../etc/passwd'));

        $this->assertStringStartsWith('unsafe path', (string) $this->gate()->reject('248p5-2026-10-001-CE'));
    }

    public function testRetriesARateLimitedDownload(): void
    {
        $this->add('248p5-2026-10-001-CE', self::diff('vendor/magento/framework/A.php'));
        $this->http->queued[self::BASE . '248p5-2026-10-001-CE.diff'] = [[429, '', []]];

        $this->assertNull($this->gate()->reject('248p5-2026-10-001-CE'));
        $this->assertCount(2, $this->http->requested);
        $this->assertSame([5], $this->http->waited);
    }

    public function testHonoursRetryAfterUpToAMinute(): void
    {
        $this->add('248p5-2026-10-001-CE', self::diff('vendor/magento/framework/A.php'));
        $this->http->queued[self::BASE . '248p5-2026-10-001-CE.diff'] = [
            [503, '', ['HTTP/1.1 503 Service Unavailable', 'retry-after: 20']],
            [429, '', ['HTTP/1.1 429 Too Many Requests', 'Retry-After: 3600']],
        ];

        $this->assertNull($this->gate()->reject('248p5-2026-10-001-CE'));
        $this->assertSame([20, 60], $this->http->waited);
    }

    public function testGivesUpOnADownloadThatStaysRateLimitedInsteadOfRejectingIt(): void
    {
        $this->add('248p5-2026-10-001-CE', self::diff('vendor/magento/framework/A.php'), [], 429);

        $this->expectException(TransientHttpException::class);
        try {
            $this->gate()->reject('248p5-2026-10-001-CE');
        } finally {
            $this->assertCount(3, $this->http->requested);
        }
    }
}

class FakeHttp extends Http
{
    /** @var array<string, array{0: int, 1: string}> */
    public $responses = [];

    /** @var array<string, array<array{0: int, 1: string, 2: string[]}>> responses served, in order, before $responses */
    public $queued = [];

    /** @var string[] */
    public $requested = [];

    /** @var int[] */
    public $waited = [];

    protected function request(string $url): array
    {
        $this->requested[] = $url;
        if (!empty($this->queued[$url])) {
            return array_shift($this->queued[$url]);
        }
        [$status, $body] = $this->responses[$url] ?? [404, ''];

        return [$status, $body, []];
    }

    protected function wait(int $seconds): void
    {
        $this->waited[] = $seconds;
    }
}

class FakeCePackages extends CePackages
{
    /** @var array<string, string[]> */
    private $releases;

    /**
     * @param array<string, string[]> $releases
     */
    public function __construct(array $releases)
    {
        $this->releases = $releases;
    }

    public function forRelease(string $version): ?array
    {
        return $this->releases[$version] ?? null;
    }
}
