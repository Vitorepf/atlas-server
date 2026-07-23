<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Frontend\RivalReplay;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveRubricService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Illuminate\Support\Facades\File;

/**
 * Rival-replay template/spec builder section — extracted verbatim from
 * AtlasFrontendRivalReplayHarnessService by the GOD-DEBULK split. Owns the
 * task-spec, pending-manifest, pending-evidence-pack and runner-kit
 * skeleton builders. Schema-version constants live on the façade (FQCN);
 * the system catalog routes through RivalReplaySupport.
 */
class RivalReplayTemplateSection
{
    public function __construct(
        private readonly RivalReplaySupport $support,
    ) {}

    /**
     * @param  array<string,string>  $case
     * @return array<string,mixed>
     */
    public function replayTaskSpec(array $case): array
    {
        $caseId = (string) $case['id'];
        $spec = [
            'schema_version' => AtlasFrontendRivalReplayHarnessService::TASK_SPEC_SCHEMA_VERSION,
            'case_id' => $caseId,
            'intent' => $case['intent'],
            'surface' => 'programming.frontend',
            'systems_under_test' => array_column($this->support->systems(), 'id'),
            'required_viewports' => ['mobile_390', 'tablet_768', 'desktop_1440'],
            'required_states' => $this->caseStates($caseId),
            'acceptance_criteria' => $this->caseAcceptanceCriteria($caseId),
            'evidence_requirements' => [
                'output_artifact_hash',
                'screenshot_hashes',
                'evidence_pack_ref',
                'anti_slop_report_hash',
                'verification_hashes',
                'competitive_score_breakdown',
                'verified_score_attestation',
            ],
            'fairness_policy' => [
                'same_task_spec_hash_required_for_all_systems' => true,
                'same_rubric_required_for_all_systems' => true,
                'provider_brand_is_not_a_score_dimension' => true,
                'score_attestation_required_for_all_complete_runs' => true,
                'raw_prompts_customer_source_tokens_or_cookies_forbidden' => true,
            ],
        ];
        $spec['task_spec_hash'] = MissionCanonicalHash::sha256($spec);

        return $spec;
    }

    /**
     * @param  array<string,string>  $case
     * @return array<string,mixed>
     */
    public function taskSpecForCase(string $directory, array $case): array
    {
        $path = $directory.'/'.$case['id'].'/task-spec.json';
        if (File::isFile($path)) {
            $taskSpec = json_decode(File::get($path), true);
            if (is_array($taskSpec)) {
                return $taskSpec;
            }
        }

        return $this->replayTaskSpec($case);
    }

    /**
     * @return array<string,mixed>
     */
    public function pendingManifest(string $caseId, string $system, array $taskSpec): array
    {
        return [
            'case_id' => $caseId,
            'system' => $system,
            'status' => 'pending',
            'run_id' => null,
            'task_spec_hash' => $taskSpec['task_spec_hash'] ?? null,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => null,
            'output_artifact_hash' => null,
            'screenshot_hashes' => [],
            'anti_slop_report_hash' => null,
            'verification_hashes' => [],
            'external_execution_receipt' => $system !== 'atlas_frontend' ? [
                'schema_version' => AtlasFrontendRivalReplayHarnessService::EXTERNAL_EXECUTION_RECEIPT_SCHEMA_VERSION,
                'status' => 'pending',
                'case_id' => $caseId,
                'system' => $system,
                'execution_surface' => 'external_rival_system',
                'captured_at' => null,
                'operator_approved' => false,
                'manifest_hashes' => [
                    'output_artifact_hash' => null,
                    'screenshot_hashes' => [],
                    'anti_slop_report_hash' => null,
                    'verification_hashes' => [],
                ],
                'notes' => 'Fill with verified external execution metadata only. Do not include raw prompts, customer source, cookies, tokens or provider secrets.',
            ] : null,
            'score_breakdown' => $this->pendingScoreBreakdown(),
            'score_total' => null,
            'score_max' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['score_max'],
            'score_attestation' => [
                'schema_version' => AtlasFrontendRivalReplayHarnessService::SCORE_ATTESTATION_SCHEMA_VERSION,
                'status' => 'pending',
                'case_id' => $caseId,
                'system' => $system,
                'scoring_surface' => 'manual_competitive_review',
                'reviewer_ref_hash' => null,
                'reviewed_at' => null,
                'operator_approved' => false,
                'rubric_hash' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['rubric_hash'],
                'score_breakdown_hash' => null,
                'score_total' => null,
                'score_max' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['score_max'],
                'evidence_pack_verification_hash' => null,
                'reviewed_manifest_hashes' => [
                    'output_artifact_hash' => null,
                    'screenshot_hashes' => [],
                    'anti_slop_report_hash' => null,
                    'verification_hashes' => [],
                    'run_packet_hash' => null,
                    'evidence_pack_verification_hash' => null,
                ],
                'notes' => 'Fill after reviewing evidence against the shared rubric. Do not include raw prompts, customer source, cookies, tokens or provider secrets.',
            ],
            'completed_at' => null,
            'notes' => 'Do not store raw prompts, raw customer source, cookies, tokens or provider secrets in this manifest.',
        ];
    }

    /**
     * @param  array<string,mixed>  $taskSpec
     */
    public function writePendingEvidencePack(string $directory, string $caseId, string $system, array $taskSpec): bool
    {
        $evidenceDirectory = $directory.'/'.$caseId.'/'.$system.'/evidence';
        $manifestPath = $evidenceDirectory.'/evidence-pack.json';

        File::ensureDirectoryExists($evidenceDirectory.'/artifacts');
        if (File::isFile($manifestPath)) {
            return false;
        }

        File::put($manifestPath, json_encode([
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'replace-with-run-id',
            'case_id' => $caseId,
            'system' => $system,
            'task_spec_hash' => $taskSpec['task_spec_hash'] ?? '<sha256-64-hex>',
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => '<sha256-64-hex>',
            ], app(AtlasFrontendEvidencePackVerifierService::class)->requiredArtifactKinds()),
            'notes' => 'Fill artifact files and hashes only. Do not store raw prompts, customer source, cookies, tokens or provider secrets.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return true;
    }

    public function writeRunPacketHashToManifest(string $directory, string $caseId, string $system, string $runPacketHash): void
    {
        $manifestPath = $directory.'/'.$caseId.'/'.$system.'/manifest.json';
        if (! File::isFile($manifestPath)) {
            return;
        }

        $manifest = json_decode(File::get($manifestPath), true);
        if (! is_array($manifest)) {
            return;
        }

        $manifest['run_packet_hash'] = $runPacketHash;
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @return array<int,string>
     */
    public function runnerSteps(string $system): array
    {
        $dispatch = $system === 'atlas_frontend'
            ? 'Run Atlas Frontend using the referenced task spec through the normal governed frontend flow.'
            : 'Run the external rival manually or through its official workflow using the referenced task spec unchanged.';

        return [
            'Open task_spec_ref and keep the task_spec_hash unchanged.',
            $dispatch,
            'Capture output artifact ref/hash, screenshots, anti-slop report and verification hashes.',
            'Score the output with atlas.frontend.competitive_rubric.v1 without using provider brand as a score dimension.',
            'Fill manifest_ref with status=complete only after all required fields are backed by hashes or refs.',
        ];
    }

    /**
     * @return array<int,string>
     */
    public function runnerEvidenceChecklist(): array
    {
        return [
            'task_spec_hash_matches_case_task_spec',
            'evidence_pack_ref_verified',
            'external_rival_execution_receipt_verified',
            'output_artifact_ref_present',
            'output_artifact_hash_present',
            'screenshot_hashes_present',
            'anti_slop_report_hash_present',
            'verification_hashes_present',
            'score_breakdown_present_and_valid',
            'completed_at_present',
            'no_raw_prompt_source_customer_data_tokens_or_cookies',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function caseStates(string $caseId): array
    {
        return match ($caseId) {
            'saas_dashboard_repair' => ['loaded_dense_table', 'empty_state', 'error_state', 'filtering_active'],
            'ecommerce_product_page' => ['default_product', 'variant_selected', 'cart_feedback', 'mobile_checkout_entry'],
            'mobile_app_onboarding' => ['first_step', 'permission_prompt', 'form_error', 'completion_state'],
            'design_system_migration' => ['before_component_mapping', 'after_component_mapping', 'token_exception', 'responsive_regression_check'],
            'live_mode_repair_loop' => ['element_selected', 'variant_previewed', 'variant_accepted', 'source_recovered'],
            default => ['default', 'error', 'empty', 'responsive'],
        };
    }

    /**
     * @return array<int,string>
     */
    private function caseAcceptanceCriteria(string $caseId): array
    {
        return match ($caseId) {
            'saas_dashboard_repair' => [
                'Preserve information density while fixing hierarchy, spacing and scan paths.',
                'No text overlap, console errors, inaccessible controls or viewport-specific regression.',
            ],
            'ecommerce_product_page' => [
                'Show product, price, variant choice, trust proof and conversion action without generic hero filler.',
                'Mobile and desktop states must remain shoppable and visually coherent.',
            ],
            'mobile_app_onboarding' => [
                'Guide the user through clear steps with accessible copy, controls and error recovery.',
                'Respect mobile ergonomics and avoid decorative UI that hides task progress.',
            ],
            'design_system_migration' => [
                'Use existing tokens and components unless an exception is explicitly evidenced.',
                'Prevent visual drift while preserving the product workflow.',
            ],
            'live_mode_repair_loop' => [
                'Support visual selection, variant preview, accept/discard and source recovery with audit hashes.',
                'Do not apply source patches when the file changed after prepare.',
            ],
            default => [
                'Satisfy product intent with responsive, accessible and evidence-backed frontend output.',
            ],
        };
    }

    /**
     * @return array<string,null>
     */
    private function pendingScoreBreakdown(): array
    {
        return collect(app(AtlasFrontendCompetitiveRubricService::class)->rubric()['dimensions'])
            ->mapWithKeys(fn (array $dimension): array => [(string) $dimension['id'] => null])
            ->all();
    }
}
