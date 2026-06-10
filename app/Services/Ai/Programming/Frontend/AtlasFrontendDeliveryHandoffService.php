<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendDeliveryHandoffService
{
    public const SCHEMA_VERSION = 'atlas.frontend.delivery_handoff.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.delivery_handoff_template.v1';

    public const PUBLICATION_ATTESTATION_SCHEMA_VERSION = 'atlas.frontend.delivery_handoff_publication_attestation.v1';

    /**
     * @return array<string,mixed>
     */
    public function compile(string $runCertificationPath, string $evidenceManifestPath, ?string $publicationReportPath = null): array
    {
        $run = $this->readJsonFile(trim($runCertificationPath), 'run_certification');
        $evidence = $this->readJsonFile(trim($evidenceManifestPath), 'evidence_manifest');
        $publication = $publicationReportPath !== null && trim($publicationReportPath) !== ''
            ? $this->readJsonFile(trim($publicationReportPath), 'publication_report')
            : ['status' => 'missing'];
        $frontendAppScope = $this->frontendAppScopeConsistency($run, $evidence, $publication);

        $blockers = [];
        $warnings = [];

        foreach ([$run, $evidence, $publication] as $payload) {
            foreach ((array) ($payload['blockers'] ?? []) as $blocker) {
                $blockers[] = (string) $blocker;
            }
            foreach ((array) ($payload['warnings'] ?? []) as $warning) {
                $warnings[] = (string) $warning;
            }
        }

        if (($run['schema_version'] ?? null) !== AtlasFrontendRunCertificationService::SCHEMA_VERSION) {
            $blockers[] = 'run_certification_schema_invalid';
        }
        if (! in_array(($run['status'] ?? null), ['certified', 'warning'], true)) {
            $blockers[] = 'run_certification_not_claim_ready';
        }
        if ((bool) data_get($run, 'claim_policy.frontend_completion_claim_allowed') !== true) {
            $blockers[] = 'frontend_completion_claim_not_allowed';
        }
        if (! is_string($run['run_certification_hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', (string) $run['run_certification_hash'])) {
            $blockers[] = 'run_certification_hash_invalid';
        }
        if (($evidence['schema_version'] ?? null) !== AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION) {
            $blockers[] = 'evidence_manifest_schema_invalid';
        }
        if (($evidence['task_spec_hash'] ?? null) !== ($run['task_spec_hash'] ?? null)) {
            $blockers[] = 'evidence_manifest_task_spec_hash_mismatch';
        }
        if (! (bool) ($frontendAppScope['consistent'] ?? false)) {
            $blockers[] = ($frontendAppScope['mismatch_source'] ?? null) === 'publication_report'
                ? 'publication_report_frontend_app_scope_mismatch'
                : 'evidence_manifest_frontend_app_scope_mismatch';
        }
        if ($this->hasForbiddenRawFields($run) || $this->hasForbiddenRawFields($evidence) || $this->hasForbiddenRawFields($publication)) {
            $blockers[] = 'forbidden_raw_prompt_source_or_customer_field_present';
        }
        if (($publication['status'] ?? null) === 'missing') {
            $warnings[] = 'publication_report_missing';
        } elseif (($publication['schema_version'] ?? null) !== AtlasFrontendPublicationVerifierService::SCHEMA_VERSION) {
            $blockers[] = 'publication_report_schema_invalid';
        }

        $publicVerified = ($publication['status'] ?? null) === 'public_verified'
            && (bool) data_get($publication, 'claim_policy.public_distribution_claim_allowed') === true;
        $publicationAttestation = app(AtlasFrontendPublicationAttestationService::class)->attest($publication, [
            'rerun_action' => 'rerun_atlas_frontend_publish_verify_with_receipt',
        ]);
        $status = $blockers === [] ? 'ready' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'handoff_type' => 'frontend_enterprise_delivery_handoff',
            'source' => self::class,
            'task_spec_hash' => is_string($run['task_spec_hash'] ?? null) ? $run['task_spec_hash'] : null,
            'run_certification_hash' => is_string($run['run_certification_hash'] ?? null) ? $run['run_certification_hash'] : null,
            'frontend_app_scope' => $frontendAppScope,
            'artifact_hashes' => [
                'run_certification_report' => is_string($run['file_hash'] ?? null) ? $run['file_hash'] : null,
                'evidence_manifest' => is_string($evidence['file_hash'] ?? null) ? $evidence['file_hash'] : null,
                'publication_report' => is_string($publication['file_hash'] ?? null) ? $publication['file_hash'] : null,
            ],
            'delivery_claims' => [
                'frontend_completion' => (bool) data_get($run, 'claim_policy.frontend_completion_claim_allowed') && $blockers === [],
                'public_distribution' => $publicVerified,
                'world_best_frontend_system' => false,
            ],
            'publication_attestation' => $publicationAttestation,
            'handoff_sections' => [
                'task_spec_hash',
                'run_certification',
                'visual_quality',
                'design_5d_review',
                'evidence_pack',
                'outcome_memory',
                'publication',
                'claim_policy',
                'known_limitations',
            ],
            'known_limitations' => array_values(array_unique(array_filter([
                $publicVerified ? null : 'public_distribution_not_verified',
                'world_best_requires_external_rival_replay',
            ]))),
            'claim_policy' => [
                'customer_handoff_allowed' => $status === 'ready',
                'raw_customer_source_returned' => false,
                'requires_run_certification_hash' => true,
                'requires_matching_task_spec_hash' => true,
                'requires_matching_frontend_app_scope' => true,
                'local_publication_report_is_not_public_distribution' => true,
                'public_distribution_requires_matching_frontend_app_scope' => true,
                'public_distribution_requires_verified_publication_report' => true,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
        $payload['handoff_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $outputDirectory): array
    {
        $outputDirectory = rtrim(trim($outputDirectory), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($outputDirectory);
        $path = $outputDirectory.'/frontend-delivery-handoff-inputs.json';

        if (! File::isFile($path)) {
            File::put($path, json_encode([
                'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
                'run_certification_report' => '<path-to-atlas.frontend.run_certification.v1-json>',
                'evidence_manifest' => '<path-to-atlas.frontend.evidence_pack.v1-json>',
                'publication_report' => '<optional-path-to-atlas.frontend.publication_verifier.v1-json>',
                'notes' => 'Use refs and hashes only. Do not include raw prompts, customer source, cookies, tokens or provider secrets.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'frontend_enterprise_delivery_handoff_inputs',
            'template_path_hash' => hash('sha256', $path),
            'claim_policy' => [
                'template_is_not_delivery_handoff' => true,
                'compile_requires_run_certification' => true,
                'compile_requires_evidence_manifest' => true,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonFile(string $path, string $label): array
    {
        if ($path === '' || ! File::isFile($path)) {
            return ['status' => 'missing', 'blockers' => [$label.'_missing']];
        }

        $raw = File::get($path);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [
                'status' => 'blocked',
                'file_hash' => hash('sha256', $raw),
                'blockers' => [$label.'_json_invalid'],
            ];
        }

        $decoded['file_hash'] = hash('sha256', $raw);

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasForbiddenRawFields(array $payload): bool
    {
        $forbidden = ['raw_prompt', 'prompt', 'raw_source', 'customer_source', 'customer_data', 'cookie', 'token', 'secret'];

        foreach ($payload as $key => $value) {
            if (in_array(Str::snake((string) $key), $forbidden, true)) {
                return true;
            }
            if (is_array($value) && $this->hasForbiddenRawFields($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $publication
     * @return array<string,mixed>
     */
    private function frontendAppScopeConsistency(array $run, array $evidence, array $publication): array
    {
        $runScope = AtlasFrontendAppScope::normalize((array) ($run['frontend_app_scope'] ?? []), includeWorkspaceFlag: false);
        $evidenceScope = AtlasFrontendAppScope::normalize((array) ($evidence['frontend_app_scope'] ?? []), includeWorkspaceFlag: false);
        $publicationScope = ($publication['status'] ?? null) !== 'missing'
            ? AtlasFrontendAppScope::normalize((array) ($publication['frontend_app_scope'] ?? []), includeWorkspaceFlag: false)
            : null;
        $scopes = array_filter([
            'run_certification' => $runScope,
            'evidence_manifest' => $evidenceScope,
            'publication_report' => $publicationScope,
        ], 'is_array');
        $runScopeKey = AtlasFrontendAppScope::key($runScope);
        $mismatchSource = collect($scopes)
            ->reject(fn (array $scope): bool => AtlasFrontendAppScope::key($scope) === $runScopeKey)
            ->keys()
            ->first();
        $invalid = collect($scopes)->contains(fn (array $scope): bool => in_array($scope['status'] ?? null, ['invalid_subscope', 'missing_subscope'], true));
        $consistent = $mismatchSource === null && ! $invalid;

        return [
            'status' => $consistent ? (string) ($runScope['status'] ?? 'repo_root') : 'mismatch',
            'consistent' => $consistent,
            'mismatch_source' => $mismatchSource,
            'relative_name' => $consistent && ($runScope['status'] ?? null) === 'subscope_selected' ? ($runScope['relative_name'] ?? null) : null,
            'relative_name_hash' => $consistent ? ($runScope['relative_name_hash'] ?? null) : null,
            'observed_scopes' => collect($scopes)->map(fn (array $scope): array => [
                'status' => $scope['status'] ?? 'repo_root',
                'relative_name_hash' => $scope['relative_name_hash'] ?? null,
            ])->all(),
            'run_certification_scope' => [
                'status' => $runScope['status'] ?? 'repo_root',
                'relative_name_hash' => $runScope['relative_name_hash'] ?? null,
            ],
            'evidence_manifest_scope' => [
                'status' => $evidenceScope['status'] ?? 'repo_root',
                'relative_name_hash' => $evidenceScope['relative_name_hash'] ?? null,
            ],
            'publication_report_scope' => $publicationScope !== null ? [
                'status' => $publicationScope['status'] ?? 'repo_root',
                'relative_name_hash' => $publicationScope['relative_name_hash'] ?? null,
            ] : null,
            'observed_scope_keys_hash' => hash('sha256', implode('|', array_map(fn (array $scope): string => AtlasFrontendAppScope::key($scope), $scopes))),
        ];
    }
}
