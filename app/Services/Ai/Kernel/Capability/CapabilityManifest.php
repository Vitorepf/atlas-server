<?php

namespace App\Services\Ai\Kernel\Capability;

use App\Services\Ai\Support\AiStringListNormalizer;

final readonly class CapabilityManifest
{
    /**
     * @param  array<int,string>  $requiredSurfaces
     * @param  array<int,string>  $optionalSurfaces
     * @param  array<int,array{surface:string,reason:string}>  $notSupported
     * @param  array<int,string>  $testSuite
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public string $schemaVersion,
        public string $id,
        public string $version,
        public string $title,
        public string $owner,
        public string $description,
        public array $requiredSurfaces,
        public array $optionalSurfaces,
        public array $notSupported,
        public array $testSuite,
        public array $raw,
    ) {}

    /**
     * @param  array<string,mixed>  $manifest
     */
    public static function fromArray(string $id, array $manifest): self
    {
        $manifestId = self::string($manifest['id'] ?? $id);

        return new self(
            schemaVersion: self::string($manifest['schema_version'] ?? 'atlas.capability.v1'),
            id: $manifestId !== '' ? $manifestId : $id,
            version: self::string($manifest['version'] ?? '0.0.0'),
            title: self::string($manifest['title'] ?? $id),
            owner: self::string($manifest['owner'] ?? 'atlas.core'),
            description: self::string($manifest['description'] ?? ''),
            requiredSurfaces: AiStringListNormalizer::uniqueTrimmedCastItemsToStrings($manifest['required_surfaces'] ?? []),
            optionalSurfaces: AiStringListNormalizer::uniqueTrimmedCastItemsToStrings($manifest['optional_surfaces'] ?? []),
            notSupported: self::notSupported($manifest['not_supported'] ?? []),
            testSuite: AiStringListNormalizer::uniqueTrimmedCastItemsToStrings($manifest['test_suite'] ?? []),
            raw: $manifest,
        );
    }

    public function explicitlyNotSupportedBy(string $surfaceId): bool
    {
        foreach ($this->notSupported as $entry) {
            if ($entry['surface'] === $surfaceId && $entry['reason'] !== '') {
                return true;
            }
        }

        return false;
    }

    private static function string(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * @return array<int,array{surface:string,reason:string}>
     */
    private static function notSupported(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            $surface = self::string($value['surface'] ?? '');
            $reason = self::string($value['reason'] ?? '');
            if ($surface === '') {
                continue;
            }

            $normalized[] = [
                'surface' => $surface,
                'reason' => $reason,
            ];
        }

        return $normalized;
    }
}
