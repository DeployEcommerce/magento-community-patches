<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Tools;

/**
 * Adobe's patch registry: https://repo.magento.com/patch/patch-registry.json
 *
 * Fails closed on anything unexpected, because the shipping decision depends
 * on every field meaning what we think it means.
 */
class Registry
{
    public const URL = 'https://repo.magento.com/patch/patch-registry.json';

    private const SCHEMA_VERSION = '1.0';

    /** Fields we know about. `entitlements` is used by Adobe but missing from the schema's own field list. */
    private const KNOWN_FIELDS = ['applies_to', 'area', 'requires', 'cves', 'released', 'file_name', 'sha256', 'entitlements'];

    /** @var array<string, array<string, mixed>> */
    private $patches;

    /** @var string */
    private $baseUrl;

    /**
     * @param array<string, mixed> $data decoded registry JSON
     */
    public function __construct(array $data)
    {
        $version = $data['_schema']['version'] ?? null;
        if ($version !== self::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf('Unsupported patch registry schema version: %s', var_export($version, true)));
        }
        if (!isset($data['patches']) || !is_array($data['patches'])) {
            throw new \RuntimeException('Patch registry has no patches.');
        }
        foreach ($data['patches'] as $id => $entry) {
            $unknown = array_diff(array_keys($entry), self::KNOWN_FIELDS);
            if ($unknown !== []) {
                throw new \RuntimeException(sprintf('Patch %s has unknown registry fields: %s', $id, implode(', ', $unknown)));
            }
        }
        $this->patches = $data['patches'];
        $this->baseUrl = rtrim((string) ($data['_schema']['patch_base_url'] ?? 'https://repo.magento.com/patch'), '/');
    }

    public static function fetch(Http $http): self
    {
        [$status, $body] = $http->get(self::URL);
        if ($status !== 200) {
            throw new \RuntimeException(sprintf('Patch registry returned HTTP %d.', $status));
        }

        return new self((array) json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function patches(): array
    {
        return $this->patches;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        return $this->patches[$id] ?? null;
    }

    public function downloadUrl(string $id): string
    {
        return $this->baseUrl . '/' . rawurlencode((string) $this->patches[$id]['file_name']);
    }
}
