<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAreaImpactLedger;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityDebtLedger;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityEvidenceProvenanceLedger;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityIntegrationMap;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainComprehensionDeepeningMap;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolSafetyRunner;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only combined capability proof map. Merges {@see AtlasExternalBrainCapabilityIntegrationMap}
 * (what is implemented/wired), {@see AtlasExternalBrainCapabilityEvidenceProvenanceLedger} (what is
 * proven vs stale vs unproven for leverage) and {@see AtlasExternalBrainCapabilityDebtLedger} (what
 * capability debt remains unresolved) into one provider-safe view, so origination never mistakes
 * queue counts or docs for real capability proof.
 *
 * Never enqueues, mutates evidence, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { capabilities:list, capability_evidence:list<{capability_id, evidence, downstream_uses}>,
 *     debt_records:list }
 * Missing/absent sections default to empty and simply produce no findings for that side.
 */
final class AtlasExternalBrainCapabilityProofMapCommand extends Command
{
    use EmitsCanonicalJson;

    private const SCHEMA = 'atlas.external_brain.capability_proof_map.v1';

    public const FINAL_STATUS_NOT_IMPLEMENTED = 'not_implemented';

    public const FINAL_STATUS_BLOCKED_BY_MISSING_EVIDENCE = 'blocked_by_missing_evidence';

    public const FINAL_STATUS_STALE_EVIDENCE = 'stale_evidence';

    public const FINAL_STATUS_UNPROVEN_FOR_LEVERAGE = 'unproven_for_leverage';

    public const FINAL_STATUS_INTEGRATION_DEBT = 'integration_debt';

    public const FINAL_STATUS_IMPLEMENTED_AND_WIRED = 'implemented_and_wired';

    /** @var string */
    protected $signature = 'atlas:external-brain:capability-proof-map
        {--input= : Path to a JSON file with capabilities, capability_evidence, debt_records}';

    /** @var string */
    protected $description = 'Read-only: combined capability integration + evidence-provenance + debt-ledger proof map.';

    public function handle(
        AtlasExternalBrainCapabilityIntegrationMap $integrationMap,
        AtlasExternalBrainCapabilityEvidenceProvenanceLedger $evidenceLedger,
        AtlasExternalBrainCapabilityDebtLedger $debtLedger,
        AtlasExternalBrainAreaImpactLedger $areaImpactLedger,
        AtlasExternalBrainComprehensionDeepeningMap $comprehensionDeepeningMap,
        AtlasExternalBrainProviderPoolSafetyRunner $poolSafetyRunner,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        // Provider pool safety check: refuse unsafe or over-quota pools before admission.
        $providerPoolInput = is_array($decoded['provider_pool'] ?? null) ? $decoded['provider_pool'] : null;
        if ($providerPoolInput !== null) {
            $poolSafety = $poolSafetyRunner->run($providerPoolInput);
            if (! ($poolSafety['safe'] ?? false)) {
                $poolId = (string) ($providerPoolInput['pool_id'] ?? 'unknown');
                $reasons = (array) ($poolSafety['reasons'] ?? []);
                $this->warn("Provider pool [{$poolId}] refused — unsafe or over-quota.");
                foreach ($reasons as $reason) {
                    $this->line("  - {$reason}");
                }

                return self::FAILURE;
            }
        }

        $capabilities = is_array($decoded['capabilities'] ?? null) ? $decoded['capabilities'] : [];
        $capabilityEvidence = is_array($decoded['capability_evidence'] ?? null) ? $decoded['capability_evidence'] : [];
        $debtRecords = is_array($decoded['debt_records'] ?? null) ? $decoded['debt_records'] : [];
        $areaImpactSamples = is_array($decoded['area_impact_samples'] ?? null) ? $decoded['area_impact_samples'] : [];
        $comprehensionDomains = is_array($decoded['comprehension_domains'] ?? null) ? $decoded['comprehension_domains'] : [];
        $comprehensionArchitectureTargets = is_array($decoded['comprehension_architecture_targets'] ?? null) ? $decoded['comprehension_architecture_targets'] : [];
        $comprehensionGaps = is_array($decoded['comprehension_gaps'] ?? null) ? $decoded['comprehension_gaps'] : [];

        $integrationReport = $integrationMap->map(['capabilities' => $capabilities]);
        $integrationByCapability = array_column($integrationReport['capability_map'], null, 'capability_id');

        $evidenceAssessments = [];
        foreach ($capabilityEvidence as $capability) {
            if (! is_array($capability)) {
                continue;
            }
            $evidenceAssessments[] = $evidenceLedger->assess($capability);
        }
        $evidenceByCapability = array_column($evidenceAssessments, null, 'capability_id');

        $debtReport = $debtLedger->assess(['debt_records' => $debtRecords]);
        $areaImpactReport = $areaImpactLedger->aggregate(['samples' => $areaImpactSamples]);
        $comprehensionDeepeningReport = $comprehensionDeepeningMap->map([
            'domains' => $comprehensionDomains,
            'architecture_targets' => $comprehensionArchitectureTargets,
        ]);
        $comprehensionGapRankings = $comprehensionDeepeningMap->rankGaps(['gaps' => $comprehensionGaps]);

        $capabilityIds = array_values(array_unique(array_merge(
            array_keys($integrationByCapability),
            array_keys($evidenceByCapability),
        )));
        sort($capabilityIds, SORT_STRING);

        $proofMap = [];
        $summary = [];
        foreach ($capabilityIds as $id) {
            $integration = $integrationByCapability[$id] ?? null;
            $evidence = $evidenceByCapability[$id] ?? null;

            $integrationStatus = (string) ($integration['integration_status'] ?? self::FINAL_STATUS_NOT_IMPLEMENTED);
            $evidenceTier = (string) ($evidence['evidence_tier'] ?? AtlasExternalBrainCapabilityEvidenceProvenanceLedger::TIER_NO_EVIDENCE);
            $freshnessStatus = (string) ($evidence['freshness_status'] ?? AtlasExternalBrainCapabilityEvidenceProvenanceLedger::FRESHNESS_UNKNOWN);
            $leverageProven = (string) ($evidence['leverage_proven'] ?? AtlasExternalBrainCapabilityEvidenceProvenanceLedger::LEVERAGE_UNPROVEN);

            $finalStatus = $this->finalStatus($integrationStatus, $evidenceTier, $freshnessStatus, $leverageProven);
            $summary[$finalStatus] = ($summary[$finalStatus] ?? 0) + 1;

            $proofMap[] = [
                'capability_id' => $id,
                'integration_status' => $integrationStatus,
                'evidence_tier' => $evidenceTier,
                'freshness_status' => $freshnessStatus,
                'leverage_proven' => $leverageProven,
                'final_status' => $finalStatus,
            ];
        }

        $payload = [
            'schema' => self::SCHEMA,
            'capability_proof_map' => $proofMap,
            'summary' => $summary,
            'integration_map' => $integrationReport,
            'evidence_assessments' => $evidenceAssessments,
            'capability_debt_ledger' => $debtReport,
            'area_impact_ledger' => $areaImpactReport,
            'comprehension_deepening_map' => $comprehensionDeepeningReport,
            'comprehension_gap_rankings' => $comprehensionGapRankings,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }

    private function finalStatus(string $integrationStatus, string $evidenceTier, string $freshnessStatus, string $leverageProven): string
    {
        if ($integrationStatus === 'not_implemented') {
            return self::FINAL_STATUS_NOT_IMPLEMENTED;
        }
        if ($evidenceTier === AtlasExternalBrainCapabilityEvidenceProvenanceLedger::TIER_NO_EVIDENCE) {
            return self::FINAL_STATUS_BLOCKED_BY_MISSING_EVIDENCE;
        }
        if ($freshnessStatus === AtlasExternalBrainCapabilityEvidenceProvenanceLedger::FRESHNESS_STALE) {
            return self::FINAL_STATUS_STALE_EVIDENCE;
        }
        if ($leverageProven === AtlasExternalBrainCapabilityEvidenceProvenanceLedger::LEVERAGE_UNPROVEN) {
            return self::FINAL_STATUS_UNPROVEN_FOR_LEVERAGE;
        }
        if (in_array($integrationStatus, ['integration_debt', 'dormant_implemented', 'contract_missing'], true)) {
            return self::FINAL_STATUS_INTEGRATION_DEBT;
        }

        return self::FINAL_STATUS_IMPLEMENTED_AND_WIRED;
    }
}
