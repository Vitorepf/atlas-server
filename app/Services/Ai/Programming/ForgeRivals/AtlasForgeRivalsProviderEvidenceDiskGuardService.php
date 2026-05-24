<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Fail-closed disk guard for real provider execution.
 *
 * Provider tokens should never be spent when the run directory cannot
 * preserve stdout, patches, receipts, replay material and manifest evidence.
 */
final class AtlasForgeRivalsProviderEvidenceDiskGuardService
{
    private const DEFAULT_MIN_FREE_BYTES_BEFORE_PROVIDER_EVIDENCE = 536870912;

    /**
     * @return array<string,mixed>
     */
    public function check(string $runBase): array
    {
        $configuredFloor = config('atlas_rivals.min_free_bytes_before_provider_evidence');
        if ($configuredFloor === null || $configuredFloor === '') {
            $configuredFloor = env(
                'ATLAS_FORGE_RIVALS_MIN_FREE_BYTES_BEFORE_PROVIDER_EVIDENCE',
                self::DEFAULT_MIN_FREE_BYTES_BEFORE_PROVIDER_EVIDENCE,
            );
        }

        $requiredBytes = max(0, (int) $configuredFloor);
        $probePath = is_dir($runBase) ? $runBase : dirname($runBase);
        $freeBytes = @disk_free_space($probePath);

        if (! is_float($freeBytes) && ! is_int($freeBytes)) {
            return [
                'status' => 'blocked',
                'blockers' => ['provider_evidence_disk_space_probe_failed:'.$probePath],
                'path' => $probePath,
                'required_free_bytes' => $requiredBytes,
                'free_bytes' => null,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
            ];
        }

        $freeBytes = (int) $freeBytes;
        if ($freeBytes < $requiredBytes) {
            return [
                'status' => 'blocked',
                'blockers' => [
                    sprintf(
                        'provider_evidence_disk_space_insufficient:free_bytes=%d:required_bytes=%d:path=%s',
                        $freeBytes,
                        $requiredBytes,
                        $probePath,
                    ),
                ],
                'path' => $probePath,
                'required_free_bytes' => $requiredBytes,
                'free_bytes' => $freeBytes,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
            ];
        }

        return [
            'status' => 'ok',
            'blockers' => [],
            'path' => $probePath,
            'required_free_bytes' => $requiredBytes,
            'free_bytes' => $freeBytes,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }
}
