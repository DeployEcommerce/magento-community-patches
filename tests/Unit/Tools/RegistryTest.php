<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Test\Unit\Tools;

use DeployEcommerce\CommunityPatches\Tools\Registry;
use PHPUnit\Framework\TestCase;

class RegistryTest extends TestCase
{
    public function testRejectsAnUnknownSchemaVersion(): void
    {
        $this->expectExceptionMessage('Unsupported patch registry schema version');
        new Registry(['_schema' => ['version' => '2.0'], 'patches' => []]);
    }

    public function testRejectsUnknownFieldsSoTheirMeaningIsNeverGuessed(): void
    {
        $this->expectExceptionMessage('Patch x-CE has unknown registry fields: restricted');
        new Registry(['_schema' => ['version' => '1.0'], 'patches' => ['x-CE' => ['area' => 'CE', 'restricted' => true]]]);
    }

    public function testBuildsDownloadUrlsFromTheRegistryBaseUrl(): void
    {
        $registry = new Registry([
            '_schema' => ['version' => '1.0', 'patch_base_url' => 'https://repo.magento.com/patch'],
            'patches' => ['x-CE' => ['file_name' => 'x-CE.diff']],
        ]);

        $this->assertSame('https://repo.magento.com/patch/x-CE.diff', $registry->downloadUrl('x-CE'));
    }
}
