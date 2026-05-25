<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasFrontendPublicationAttestationService
{
    public const SCHEMA_VERSION = 'atlas.frontend.delivery_handoff_publication_attestation.v1';

    /**
     * @param  array<string,mixed>  $publication
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function attest(array $publication, array $options = []): array
    {
        $claimedPublicDistributionAllowed = (bool) data_get($publication, 'claim_policy.public_distribution_claim_allowed');
        $status = $this->status($publication, $claimedPublicDistributionAllowed);
        $publicDistributionAllowed = $claimedPublicDistributionAllowed && $status === 'public_verified';
        $worldBestClaimAllowed = (bool) ($options['world_best_claim_allowed'] ?? false);
        $rerunAction = is_string($options['rerun_action'] ?? null)
            ? (string) $options['rerun_action']
            : 'rerun_atlas_frontend_publish_verify_with_receipt';

        $attestation = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'publication_report_status' => $publication['status'] ?? ($options['default_publication_status'] ?? 'not_requested'),
            'publication_report_schema_version' => $publication['schema_version'] ?? null,
            'publication_report_hash' => is_string($publication['file_hash'] ?? null) ? $publication['file_hash'] : null,
            'publication_hash' => is_string($publication['publication_hash'] ?? null) ? $publication['publication_hash'] : null,
            'bundle_hash' => is_string($publication['bundle_hash'] ?? null) ? $publication['bundle_hash'] : null,
            'bundle_manifest_hash' => is_string($publication['bundle_manifest_hash'] ?? null) ? $publication['bundle_manifest_hash'] : null,
            'public_receipt_status' => data_get($publication, 'public_receipt.status'),
            'public_receipt_hash' => data_get($publication, 'public_receipt.receipt_hash'),
            'public_url_hash' => data_get($publication, 'public_receipt.public_url_hash'),
            'frontend_app_scope' => [
                'status' => data_get($publication, 'frontend_app_scope.status', 'repo_root'),
                'relative_name_hash' => data_get($publication, 'frontend_app_scope.relative_name_hash'),
            ],
            'world_best_claim_allowed' => $worldBestClaimAllowed,
            'required_next_actions' => $publicDistributionAllowed ? [] : [
                'publish_bundle_to_https',
                'verify_http_200_and_index_hash',
                'provide_operator_approved_publication_receipt',
                $rerunAction,
            ],
            'claim_policy' => [
                'local_bundle_is_not_public_distribution' => true,
                'receipt_template_is_not_public_verification' => true,
                'public_distribution_claim_allowed' => $publicDistributionAllowed,
                'public_distribution_requires_verified_publication_report' => true,
                'raw_public_url_returned' => false,
                'world_best_claim_allowed' => $worldBestClaimAllowed,
            ],
        ];
        $attestation['attestation_hash'] = MissionCanonicalHash::sha256($attestation);

        return $attestation;
    }

    /**
     * @param  array<string,mixed>  $publication
     */
    private function status(array $publication, bool $publicDistributionAllowed): string
    {
        $publicationStatus = (string) ($publication['status'] ?? 'not_requested');

        return match (true) {
            $publicationStatus === 'missing' => 'missing_report',
            $publicationStatus === 'not_requested' => 'not_requested',
            $publicationStatus !== 'not_requested'
                && $publicationStatus !== 'missing'
                && ($publication['schema_version'] ?? null) !== AtlasFrontendPublicationVerifierService::SCHEMA_VERSION => 'invalid_report_schema',
            $publicationStatus === 'blocked' => 'blocked',
            $publicDistributionAllowed => 'public_verified',
            $publicationStatus === 'local_ready' => 'local_bundle_ready_publication_pending',
            default => 'public_distribution_unverified',
        };
    }
}
