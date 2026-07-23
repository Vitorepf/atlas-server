<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierDecomposerGate;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierDedupPriorArtGate;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierDriftMapperGate;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierEvidenceBoundGate;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierJudgePanelGate;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierMetricRollbackGate;
use App\Services\Ai\Foundry\Frontier\Ports\DeterministicFixtureFrontierGeneratorService;
use App\Services\Ai\Foundry\Frontier\Ports\FrontierGeneratorPort;
use App\Services\Ai\Foundry\FoundryExhaustionRarityGateService;
use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionCurationInboxService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Foundry AP-C · Frontier Generation Orchestrator.
 *
 * THE DANGEROUS SLICE — generation turns on, and THIS is the armor. The
 * orchestrator is PROPOSAL-ONLY: the only writes it ever performs are (a) the
 * in-memory curation-inbox projection it returns, (b) an append-only drop-reason
 * JSONL, and (c) an append-only prior-proposal JSONL for a future cycle's I6.
 * It NEVER writes canon/docs/code, NEVER merges, NEVER executes, NEVER
 * auto-approves, NEVER calls buildOperatorCurationReceipt. Nothing the generator
 * emits is ever written to canon or code by AP-C.
 *
 * GENERATION IS DOUBLE-GATED, both guards backed by PERSISTENT config (no
 * transient arg can ever flip them):
 *   1. gate_guard — FoundryExhaustionRarityGateService::decide() must return
 *      status=eligible. Before the call, INPUT IS SANITIZED: the
 *      'exhaustion_rarity_gate_enabled' key (and any caller-supplied eligibility
 *      override) is STRIPPED from the input, so the gate evaluates the flag
 *      SOLELY from config('atlas.software_company_stewardship.frontier_mode').
 *      THERE IS NO ELIGIBILITY OVERRIDE PATH OF ANY KIND. A test may inject a
 *      fake gate service via the constructor; no input/CLI key reaches
 *      eligibility.
 *   2. flag_guard — frontier_mode read ONLY from the same persistent config
 *      (single source of truth shared with the gate). Off => honest skip.
 *
 * Only when BOTH config-backed guards pass does the generator run (real-or-blocked
 * via FrontierGeneratorPort). Each proposal then runs the ordered, first-match-wins
 * armor chain I1 → I6 → I3 → I7 → I9 → I2, returning on the first failure with a
 * machine-readable {proposal_id, drop_stage, reason, detail}. Survivors are
 * adapted to gap_candidates and admitted to the inbox as pending_operator_review.
 */
final class FrontierGenerationOrchestratorService
{
    public const RESULT_SCHEMA = 'atlas.foundry.frontier_generator_result.v1';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BLOCKED = 'blocked';

    /** Drop stages owned by the orchestrator (pre/post the armor chain). */
    public const DROP_STAGE_SHAPE = 'shape';

    public const DROP_STAGE_ADMISSION = 'admission';

    public const DROP_FIXTURE_NOT_AUTHORIZED = 'fixture_generator_not_authorized';

    public const DROP_SURVIVOR_MISSING_CANDIDATE_HASH = 'survivor_missing_candidate_hash';

    public const REASON_FRONTIER_MODE_OFF = 'frontier_mode_off';

    private const CONFIG_FLAG = 'atlas.software_company_stewardship.frontier_mode';

    /**
     * The 13 canonical evolution_proposal.v1 keys (mirrors FoundrySchemas
     * REQUIRED_KEYS for that schema). Provenance keys are stripped to this set
     * before shape validation and before every armor stage.
     *
     * @var list<string>
     */
    private const PROPOSAL_PROJECTION_KEYS = [
        'proposal_id', 'horizon', 'title', 'thesis', 'evidence_refs',
        'why_it_multiplies', 'success_metric', 'rollback', 'risk_level',
        'dependencies', 'proposed_packets', 'provider_tier_required',
        'anti_pattern_self_check',
    ];

    private ?string $dropLedgerPathOverride = null;

    private ?string $priorProposalLedgerPathOverride = null;

    public function __construct(
        private readonly FoundryExhaustionRarityGateService $gateService,
        private readonly FrontierGeneratorPort $generator,
        private readonly FrontierEvidenceBoundGate $evidenceBoundGate,
        private readonly FrontierDedupPriorArtGate $dedupGate,
        private readonly FrontierJudgePanelGate $judgePanelGate,
        private readonly FrontierDecomposerGate $decomposerGate,
        private readonly FrontierDriftMapperGate $driftMapperGate,
        private readonly FrontierMetricRollbackGate $metricRollbackGate,
        private readonly FrontierProposalToGapCandidateAdapter $adapter,
        private readonly SelfDirectedEvolutionCurationInboxService $curationInbox,
    ) {}

    public function setDropLedgerPathForTesting(?string $path): void
    {
        $this->dropLedgerPathOverride = $path;
    }

    public function setPriorProposalLedgerPathForTesting(?string $path): void
    {
        $this->priorProposalLedgerPathOverride = $path;
    }

    /**
     * Run the gated, proposal-only frontier pipeline.
     *
     * @param  array<string,mixed>  $input  {area_id?, count?, dossier?, fixture_authorized?, gate_input?, inbox_context?}
     * @return array<string,mixed>  atlas.foundry.frontier_generator_result.v1
     */
    public function run(array $input = []): array
    {
        $areaId = (string) ($input['area_id'] ?? 'agentic_engineering_os');
        $count = max(1, (int) ($input['count'] ?? 3));

        // ---- GUARD 1: gate_guard. Sanitize FIRST (no eligibility override). ----
        $gateInput = $this->sanitizeGateInput(is_array($input['gate_input'] ?? null) ? $input['gate_input'] : []);
        $gate = $this->gateService->decide($gateInput);
        $gateStatus = (string) ($gate['status'] ?? FoundryExhaustionRarityGateService::STATUS_NOT_ELIGIBLE);

        if ($gateStatus !== FoundryExhaustionRarityGateService::STATUS_ELIGIBLE) {
            // Mirror the gate status (blocked stays blocked; everything else skips).
            $resultStatus = $gateStatus === FoundryExhaustionRarityGateService::STATUS_BLOCKED
                ? self::STATUS_BLOCKED
                : self::STATUS_SKIPPED;

            return $this->emit(
                status: $resultStatus,
                areaId: $areaId,
                reason: 'gate_'.$gateStatus,
                gateStatus: $gateStatus,
                frontierMode: $this->frontierModeEnabled(),
                generatorLabel: null,
                generatorStatus: null,
                proposalsGenerated: 0,
                survivors: [],
                drops: [],
                curationInbox: null,
            );
        }

        // ---- GUARD 2: flag_guard. PERSISTENT config only. ----
        if (! $this->frontierModeEnabled()) {
            return $this->emit(
                status: self::STATUS_SKIPPED,
                areaId: $areaId,
                reason: self::REASON_FRONTIER_MODE_OFF,
                gateStatus: $gateStatus,
                frontierMode: false,
                generatorLabel: null,
                generatorStatus: null,
                proposalsGenerated: 0,
                survivors: [],
                drops: [],
                curationInbox: null,
            );
        }

        // ---- generator: dossier-only input, real-or-blocked. ----
        $dossier = is_array($input['dossier'] ?? null) ? $input['dossier'] : [];
        $generation = $this->generator->generate($dossier, $count);
        $generatorLabel = (string) ($generation['generator_label'] ?? 'unknown');
        $generatorStatus = (string) ($generation['status'] ?? self::STATUS_BLOCKED);
        $provenance = is_array($generation['provenance'] ?? null) ? $generation['provenance'] : [];

        // Fixture is TEST-ONLY: refuse as a real proposal source unless explicitly authorized.
        $isFixture = str_starts_with($generatorLabel, 'fixture:');
        if ($isFixture && ($input['fixture_authorized'] ?? false) !== true) {
            return $this->emit(
                status: self::STATUS_BLOCKED,
                areaId: $areaId,
                reason: self::DROP_FIXTURE_NOT_AUTHORIZED,
                gateStatus: $gateStatus,
                frontierMode: true,
                generatorLabel: $generatorLabel,
                generatorStatus: $generatorStatus,
                proposalsGenerated: 0,
                survivors: [],
                drops: [],
                curationInbox: null,
            );
        }

        if ($generatorStatus !== 'generated') {
            // Real-or-blocked: no provider capacity => honest block, no proposals.
            return $this->emit(
                status: self::STATUS_BLOCKED,
                areaId: $areaId,
                reason: 'generator_'.$generatorStatus,
                gateStatus: $gateStatus,
                frontierMode: true,
                generatorLabel: $generatorLabel,
                generatorStatus: $generatorStatus,
                proposalsGenerated: 0,
                survivors: [],
                drops: [],
                curationInbox: null,
            );
        }

        $proposals = is_array($generation['proposals'] ?? null) ? $generation['proposals'] : [];
        $generatorContext = [
            'generator_provider_resolved' => (string) ($generation['generator_provider_resolved'] ?? ''),
            'generator_model_resolved' => (string) ($generation['generator_model_resolved'] ?? ''),
        ];

        $drops = [];
        $survivors = [];
        $survivorReceipts = [];

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $proposalId = (string) ($proposal['proposal_id'] ?? '');
            $prov = is_array($provenance[$proposalId] ?? null) ? $provenance[$proposalId] : [];

            // ---- shape_validate: 13-key stripped projection; malformed dropped pre-pipeline. ----
            $projection = $this->strippedProjection($proposal);
            $shape = FoundrySchemas::validateShape(FoundrySchemas::EVOLUTION_PROPOSAL, $projection);
            if (! $shape['valid']) {
                $drops[] = $this->orchestratorDrop(
                    $proposalId,
                    self::DROP_STAGE_SHAPE,
                    'malformed_proposal_shape',
                    'missing='.implode(',', $shape['missing']),
                );

                continue;
            }

            // ---- ordered armor chain I1 -> I6 -> I3 -> I7 -> I9 -> I2, first-match-wins. ----
            $receipt = [];

            // I1 Evidence-Bound.
            $i1 = $this->evidenceBoundGate->evaluate($projection, $dossier, $prov, (array) ($input['ledger_events'] ?? []));
            $receipt['i1'] = $i1;
            if (($i1['status'] ?? FrontierEvidenceBoundGate::STATUS_DROP) !== FrontierEvidenceBoundGate::STATUS_PASS) {
                $drops[] = $this->armorDrop($proposalId, FrontierEvidenceBoundGate::STAGE, (string) $i1['reason'], (string) $i1['detail']);

                continue;
            }

            // I6 Dedup / Prior-Art (single-proposal cycle plus prior ledger + inbox).
            $i6 = $this->dedupGate->evaluate([$projection], [
                'curation_inbox' => is_array($input['inbox_context']['curation_inbox'] ?? null)
                    ? $input['inbox_context']['curation_inbox']
                    : null,
                'inbox_input' => $input['inbox_context']['inbox_input'] ?? [],
                'prior_proposal_hashes' => $this->priorProposalHashes(),
            ]);
            $receipt['i6'] = $i6;
            if (((int) ($i6['survivors_count'] ?? 0)) < 1) {
                $i6drop = $i6['drops'][0] ?? ['reason' => 'duplicate', 'detail' => ''];
                $drops[] = $this->armorDrop($proposalId, FrontierDedupPriorArtGate::STAGE, (string) ($i6drop['reason'] ?? 'duplicate'), (string) ($i6drop['detail'] ?? ''));

                continue;
            }

            // I3 Judge Panel (judge != generator; default-refute; majority).
            $i3 = $this->judgePanelGate->adjudicate($projection, $generatorContext);
            $receipt['i3'] = $i3['verdict'] ?? [];
            if (($i3['survived'] ?? false) !== true) {
                $drops[] = $this->armorDrop($proposalId, FrontierJudgePanelGate::STAGE, (string) ($i3['drop_reason'] ?? 'refuted'), 'judge panel refuted');

                continue;
            }

            // I7 Decomposer (3-12 bounded packets, no scaffold-only).
            $i7 = $this->decomposerGate->adjudicate($projection);
            $receipt['i7'] = $i7;
            if (($i7['status'] ?? FrontierDecomposerGate::STATUS_BLOCKED) !== FrontierDecomposerGate::STATUS_COMPLETE) {
                $i7drop = $i7['drops'][0] ?? ['reason' => 'decomposition_failed', 'detail' => ''];
                $drops[] = $this->armorDrop($proposalId, FrontierDecomposerGate::STAGE, (string) ($i7drop['reason'] ?? 'decomposition_failed'), (string) ($i7drop['detail'] ?? ''));

                continue;
            }

            // I9 Drift Mapper (map to a MEASURED canonical property).
            $i9 = $this->driftMapperGate->adjudicate($projection, $dossier);
            $receipt['i9'] = $i9;
            if (($i9['passed'] ?? false) !== true) {
                $drops[] = $this->armorDrop($proposalId, FrontierDriftMapperGate::STAGE_ID, (string) ($i9['reason'] ?? 'unmapped'), (string) ($i9['detail'] ?? ''));

                continue;
            }

            // I2 Metric + Rollback (falsifiable metric + rollback at inbox entry).
            $i2 = $this->metricRollbackGate->evaluate($projection);
            $receipt['i2'] = $i2;
            if (($i2['admit'] ?? false) !== true) {
                $drops[] = $this->armorDrop($proposalId, FrontierMetricRollbackGate::STAGE_ID, (string) ($i2['drop_reason'] ?? 'metric_or_rollback'), (string) ($i2['detail'] ?? ''));

                continue;
            }

            $survivors[] = $projection;
            $survivorReceipts[] = $receipt;
        }

        // ---- adapt_survivors + candidate_hash_guard (HARD) + admit. ----
        $candidates = [];
        foreach ($survivors as $i => $survivor) {
            $candidate = $this->adapter->adapt($survivor, $survivorReceipts[$i] ?? []);
            $candidateHash = trim((string) ($candidate['candidate_hash'] ?? ''));

            if ($candidateHash === '' || $candidateHash === 'sha256:') {
                $drops[] = $this->orchestratorDrop(
                    (string) ($survivor['proposal_id'] ?? ''),
                    self::DROP_STAGE_ADMISSION,
                    self::DROP_SURVIVOR_MISSING_CANDIDATE_HASH,
                    'survivor produced empty candidate_hash; never admitted',
                );

                continue;
            }
            $candidates[] = $candidate;
        }

        $curationInbox = null;
        if ($candidates !== []) {
            $synthetic = [
                'schema_version' => SelfDirectedEvolutionGapReadModelService::REPORT_SCHEMA,
                'status' => SelfDirectedEvolutionGapReadModelService::STATUS_READY,
                'candidates' => $candidates,
                'blockers' => [],
            ];
            $curationInbox = $this->curationInbox->project(['gap_read_model' => $synthetic]);
        }

        // ---- record_drops + prior-proposal ledger (append-only JSONL). ----
        $this->recordDrops($areaId, $drops);
        $this->recordPriorProposals($areaId, $candidates);

        return $this->emit(
            status: self::STATUS_GENERATED,
            areaId: $areaId,
            reason: 'frontier_pipeline_complete',
            gateStatus: $gateStatus,
            frontierMode: true,
            generatorLabel: $generatorLabel,
            generatorStatus: $generatorStatus,
            proposalsGenerated: count($proposals),
            survivors: $candidates,
            drops: $drops,
            curationInbox: $curationInbox,
        );
    }

    /**
     * STRIP any eligibility override key so transient args can never flip the
     * default-off gate. The gate evaluates the flag SOLELY from persistent config.
     *
     * @param  array<string,mixed>  $gateInput
     * @return array<string,mixed>
     */
    private function sanitizeGateInput(array $gateInput): array
    {
        unset($gateInput['exhaustion_rarity_gate_enabled']);

        return $gateInput;
    }

    /** Single source of truth for the flag, shared with the AP-B gate. */
    private function frontierModeEnabled(): bool
    {
        return (bool) config(self::CONFIG_FLAG, false);
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>
     */
    private function strippedProjection(array $proposal): array
    {
        $projection = [];
        foreach (self::PROPOSAL_PROJECTION_KEYS as $key) {
            if (array_key_exists($key, $proposal)) {
                $projection[$key] = $proposal[$key];
            }
        }

        return $projection;
    }

    /**
     * @return array{proposal_id:string,drop_stage:string,reason:string,detail:string}
     */
    private function orchestratorDrop(string $proposalId, string $stage, string $reason, string $detail): array
    {
        return [
            'proposal_id' => $proposalId,
            'drop_stage' => $stage,
            'reason' => $reason,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{proposal_id:string,drop_stage:string,reason:string,detail:string}
     */
    private function armorDrop(string $proposalId, string $stage, string $reason, string $detail): array
    {
        return [
            'proposal_id' => $proposalId,
            'drop_stage' => $stage,
            'reason' => $reason,
            'detail' => $detail,
        ];
    }

    /** @return list<string> */
    private function priorProposalHashes(): array
    {
        $path = $this->priorProposalLedgerPathOverride;
        if ($path === null || ! is_file($path)) {
            return [];
        }
        $hashes = [];
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['candidate_hash']) && is_string($row['candidate_hash'])) {
                $hashes[] = $row['candidate_hash'];
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * Append-only drop-reason JSONL. Proposal-only side effect.
     *
     * @param  list<array<string,mixed>>  $drops
     */
    private function recordDrops(string $areaId, array $drops): void
    {
        if ($drops === [] || $this->dropLedgerPathOverride === null) {
            return;
        }
        AppendOnlyJsonlStore::appendRowsUsingFilePutContents(
            $this->dropLedgerPathOverride,
            array_map(static fn (array $drop): array => $drop + ['area_id' => $areaId], $drops),
            static fn (array $row): string => MissionCanonicalHash::canonicalJson($row),
        );
    }

    /**
     * Append-only prior-proposal JSONL for a FUTURE cycle's I6. Proposal-only.
     *
     * @param  list<array<string,mixed>>  $candidates
     */
    private function recordPriorProposals(string $areaId, array $candidates): void
    {
        if ($candidates === [] || $this->priorProposalLedgerPathOverride === null) {
            return;
        }
        AppendOnlyJsonlStore::appendRowsUsingFilePutContents(
            $this->priorProposalLedgerPathOverride,
            array_map(
                static fn (array $c): array => [
                    'area_id' => $areaId,
                    'candidate_hash' => (string) ($c['candidate_hash'] ?? ''),
                    'candidate_id' => (string) ($c['candidate_id'] ?? ''),
                ],
                $candidates,
            ),
            static fn (array $row): string => MissionCanonicalHash::canonicalJson($row),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $survivors
     * @param  list<array<string,mixed>>  $drops
     * @param  array<string,mixed>|null  $curationInbox
     * @return array<string,mixed>
     */
    private function emit(
        string $status,
        string $areaId,
        string $reason,
        string $gateStatus,
        bool $frontierMode,
        ?string $generatorLabel,
        ?string $generatorStatus,
        int $proposalsGenerated,
        array $survivors,
        array $drops,
        ?array $curationInbox,
    ): array {
        $payload = [
            'schema_version' => self::RESULT_SCHEMA,
            'status' => $status,
            'area_id' => $areaId,
            'reason' => $reason,
            'gate_status' => $gateStatus,
            'frontier_mode' => $frontierMode,
            'generator_label' => $generatorLabel,
            'generator_status' => $generatorStatus,
            'proposals_generated' => $proposalsGenerated,
            'survivors_count' => count($survivors),
            'drops' => array_values($drops),
            'curation_inbox' => $curationInbox,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['result_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        // Exclude the volatile generated_at the inbox stamps so the result hash
        // stays deterministic across runs of the same input.
        if (is_array($payload['curation_inbox'] ?? null)) {
            unset($payload['curation_inbox']['generated_at']);
        }

        return $payload;
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => false,
            'provider_invoked' => false,
            'mutates_repo' => false,
            'canonical_doc_write_allowed' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'merge_allowed' => false,
            'executed' => false,
            'proposal_only' => true,
            'deterministic' => true,
        ];
    }

    /**
     * Convenience factory mirroring the production wiring: real gate + REAL
     * generator. Used by the CLI for the production path.
     */
    public static function withRealGenerator(
        FoundryExhaustionRarityGateService $gateService,
        FrontierGeneratorPort $generator,
        FrontierEvidenceBoundGate $evidenceBoundGate,
        FrontierDedupPriorArtGate $dedupGate,
        FrontierJudgePanelGate $judgePanelGate,
        FrontierDecomposerGate $decomposerGate,
        FrontierDriftMapperGate $driftMapperGate,
        FrontierMetricRollbackGate $metricRollbackGate,
        FrontierProposalToGapCandidateAdapter $adapter,
        SelfDirectedEvolutionCurationInboxService $curationInbox,
    ): self {
        return new self(
            $gateService,
            $generator,
            $evidenceBoundGate,
            $dedupGate,
            $judgePanelGate,
            $decomposerGate,
            $driftMapperGate,
            $metricRollbackGate,
            $adapter,
            $curationInbox,
        );
    }

    /**
     * Fixture-generator marker so the CLI never silently treats fixture as real.
     */
    public static function isFixtureGenerator(FrontierGeneratorPort $generator): bool
    {
        return $generator instanceof DeterministicFixtureFrontierGeneratorService;
    }
}
