<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Test\Unit;

use DeployEcommerce\CommunityPatches\Manifest;
use DeployEcommerce\CommunityPatches\ManifestException;
use PHPUnit\Framework\TestCase;

class ManifestTest extends TestCase
{
    private static function patch(string $id, array $appliesTo, array $requires = []): array
    {
        return ['id' => $id, 'file' => "patches/$id.patch", 'sha256' => str_repeat('0', 64),
            'applies_to' => $appliesTo, 'requires' => $requires];
    }

    public function testOrdersPatchesAfterTheirPrerequisites(): void
    {
        $manifest = new Manifest([
            self::patch('248p5-2026-10-001-CE', ['2.4.8-p5'], ['248p5-2026-07-001-CE', '248p5-2026-09-001-CE']),
            self::patch('248p5-2026-09-001-CE', ['2.4.8-p5'], ['248p5-2026-08-001-CE']),
            self::patch('248p5-2026-08-001-CE', ['2.4.8-p5'], ['248p5-2026-07-001-CE']),
            self::patch('248p5-2026-07-001-CE', ['2.4.8-p5']),
        ]);

        $this->assertSame(
            ['248p5-2026-07-001-CE', '248p5-2026-08-001-CE', '248p5-2026-09-001-CE', '248p5-2026-10-001-CE'],
            array_column($manifest->forVersion('2.4.8-p5'), 'id')
        );
    }

    public function testSelectsOnlyPatchesForTheInstalledVersion(): void
    {
        $manifest = new Manifest([
            self::patch('249-VULN-39341-CE', ['2.4.9']),
            self::patch('248p5-VULN-39341-CE', ['2.4.8-p5', '2.4.8-p4']),
        ]);

        $this->assertSame(['248p5-VULN-39341-CE'], array_column($manifest->forVersion('2.4.8-p4'), 'id'));
        $this->assertSame([], $manifest->forVersion('2.4.7-p10'));
    }

    public function testFailsWhenAPrerequisiteIsNotShipped(): void
    {
        $manifest = new Manifest([self::patch('246p15-2026-10-001-CE', ['2.4.6-p15'], ['246p15-2026-09-001-CE'])]);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('246p15-2026-09-001-CE is required for Magento 2.4.6-p15');
        $manifest->forVersion('2.4.6-p15');
    }

    public function testFailsOnARequiresCycle(): void
    {
        $manifest = new Manifest([self::patch('a', ['1'], ['b']), self::patch('b', ['1'], ['a'])]);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('requires cycle');
        $manifest->forVersion('1');
    }

    public function testRejectsDuplicateIds(): void
    {
        $this->expectException(ManifestException::class);
        new Manifest([self::patch('a', ['1']), self::patch('a', ['1'])]);
    }
}
