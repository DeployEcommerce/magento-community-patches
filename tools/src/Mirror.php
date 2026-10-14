<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Tools;

/**
 * Pristine Magento Open Source files from the Mage-OS mirror, which republishes
 * Adobe's Magento Open Source package archives unchanged.
 */
class Mirror
{
    private const METADATA_URL = 'https://mirror.mage-os.org/p2/%s.json';

    /** @var Http */
    private $http;

    /** @var string */
    private $cacheDir;

    public function __construct(Http $http, string $cacheDir)
    {
        $this->http = $http;
        $this->cacheDir = $cacheDir;
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
    }

    /**
     * The exact package versions that make up a Magento Open Source release.
     *
     * @return array<string, string> package => version
     */
    public function releasePackages(string $release): array
    {
        $root = $this->find('magento/product-community-edition', $release);
        if ($root === null) {
            throw new \RuntimeException(sprintf('The mirror does not have Magento Open Source %s.', $release));
        }
        $packages = ['magento/magento2-base' => $release];
        foreach ($root['require'] ?? [] as $name => $constraint) {
            if (strpos($name, 'magento/') === 0 && preg_match('/^[0-9][0-9.]*(-p\d+)?$/', (string) $constraint)) {
                $packages[$name] = (string) $constraint;
            }
        }

        return $packages;
    }

    /**
     * Writes the pristine copy of each path (as a project-root-relative path) into $dir.
     * Paths outside vendor/ come from magento/magento2-base, which installs to the project root.
     *
     * @param string[] $paths
     * @param array<string, string> $packages package => version
     */
    public function extract(array $paths, array $packages, string $dir): void
    {
        foreach ($paths as $path) {
            if (preg_match('#^vendor/(magento/[^/]+)/(.+)$#', $path, $m)) {
                [$package, $inner] = [$m[1], $m[2]];
            } elseif (strpos($path, 'vendor/') !== 0) {
                [$package, $inner] = ['magento/magento2-base', $path];
            } else {
                continue;
            }
            if (!isset($packages[$package])) {
                continue;
            }
            $zip = new \ZipArchive();
            if ($zip->open($this->archive($package, $packages[$package])) !== true) {
                throw new \RuntimeException(sprintf('Cannot open the %s %s archive.', $package, $packages[$package]));
            }
            $content = $zip->getFromName($inner);
            $zip->close();
            if ($content !== false) {
                @mkdir(dirname($dir . '/' . $path), 0777, true);
                file_put_contents($dir . '/' . $path, $content);
            }
        }
    }

    private function archive(string $package, string $version): string
    {
        $file = sprintf('%s/%s-%s.zip', $this->cacheDir, str_replace('/', '__', $package), $version);
        if (!is_file($file)) {
            [$status, $body] = $this->http->get(sprintf('https://mirror.mage-os.org/packages/%s-%s.zip', $package, $version));
            if ($status !== 200) {
                throw new \RuntimeException(sprintf('Mirror returned HTTP %d for %s %s.', $status, $package, $version));
            }
            file_put_contents($file, $body);
        }

        return $file;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $package, string $version): ?array
    {
        [$status, $body] = $this->http->get(sprintf(self::METADATA_URL, $package));
        if ($status !== 200) {
            return null;
        }
        foreach (json_decode($body, true)['packages'][$package] ?? [] as $release) {
            if ($release['version'] === $version) {
                return $release;
            }
        }

        return null;
    }
}
