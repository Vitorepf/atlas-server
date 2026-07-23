<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Frontend\RivalReplay;

use Illuminate\Support\Str;

/**
 * Shared leaf helpers for AtlasFrontendRivalReplayHarnessService and its
 * extracted RivalReplay sections (GOD-DEBULK split). Pure, stateless,
 * container-resolved so the façade and every Section reference one copy
 * instead of duplicating it. Bodies are byte-identical to the pre-split
 * façade originals.
 */
class RivalReplaySupport
{
    /**
     * @return array<int,array<string,string>>
     */
    public function cases(): array
    {
        return [
            ['id' => 'saas_dashboard_repair', 'intent' => 'Repair dense SaaS dashboard UI without losing information density.'],
            ['id' => 'ecommerce_product_page', 'intent' => 'Create production-grade commerce product page with visual proof.'],
            ['id' => 'mobile_app_onboarding', 'intent' => 'Design mobile onboarding with responsive states and accessibility.'],
            ['id' => 'design_system_migration', 'intent' => 'Migrate UI to a company design system without drift.'],
            ['id' => 'live_mode_repair_loop', 'intent' => 'Use live browser selection, preview, accept/discard and source recovery.'],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function systems(): array
    {
        return [
            ['id' => 'atlas_frontend', 'kind' => 'local_runtime'],
            ['id' => 'pbakaus_impeccable', 'kind' => 'external_rival'],
            ['id' => 'claude_design_plugin', 'kind' => 'external_rival'],
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function scoreReviewedManifestHashes(array $manifest, ?string $evidencePackVerificationHash): array
    {
        return [
            'output_artifact_hash' => is_string($manifest['output_artifact_hash'] ?? null) ? (string) $manifest['output_artifact_hash'] : null,
            'screenshot_hashes' => array_values((array) ($manifest['screenshot_hashes'] ?? [])),
            'anti_slop_report_hash' => is_string($manifest['anti_slop_report_hash'] ?? null) ? (string) $manifest['anti_slop_report_hash'] : null,
            'verification_hashes' => array_values((array) ($manifest['verification_hashes'] ?? [])),
            'run_packet_hash' => is_string($manifest['run_packet_hash'] ?? null) ? (string) $manifest['run_packet_hash'] : null,
            'evidence_pack_verification_hash' => $evidencePackVerificationHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    public function hasForbiddenRawFields(array $manifest): bool
    {
        $forbidden = ['raw_prompt', 'prompt', 'source', 'raw_source', 'customer_source', 'customer_data'];

        return $this->containsForbiddenKeyRecursive($manifest, $forbidden);
    }

    /**
     * @param  array<int,string>  $forbidden
     */
    public function containsForbiddenKeyRecursive(array $payload, array $forbidden): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(Str::snake($key), $forbidden, true)) {
                return true;
            }

            if (is_array($value) && $this->containsForbiddenKeyRecursive($value, $forbidden)) {
                return true;
            }
        }

        return false;
    }
}
