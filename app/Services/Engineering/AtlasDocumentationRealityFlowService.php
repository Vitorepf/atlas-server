<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use Throwable;

/**
 * The documented "Fluxo alvo para IA" of the ADRS generative leap
 * (atlas-documentation-reality-generative-leap.md:186-199), made runtime as ONE
 * read-only COMPOSITION — NOT a new capability and NOT the enforcement.
 *
 * The generative-leap doc describes the six already-built capabilities as ONE
 * end-to-end FLOW:
 *
 *   tarefa
 *   -> P1 predicts ("if I write X: duplicates Y, drift Z, owner W, breaks Q")
 *   -> AI gets foresight BEFORE writing
 *   -> the write happens via write-bound enforcement (L0)
 *   -> P2 reconciles (proposes a repair for divergence)
 *   -> P3 synthesises an antibody if something escaped
 *
 * Each rung ALREADY EXISTS as a separate, proven read-only service with its own
 * command, doc and test. This orchestrator does NOT re-implement any of them: it
 * INJECTS each one and CALLS it, surfacing its REAL output (summarised), so the
 * code finally reflects the single documented flow. It never re-derives, softens
 * or alters a verdict — a would_duplicate prediction is surfaced exactly as P1
 * produced it.
 *
 * WHAT THIS IS NOT (load-bearing — restated in the contract doc's Riscos):
 *   - It is NOT the enforcement. The active L0 enforcement is the pre-commit hook
 *     (scripts/hooks/pre-commit -> atlas:documentation-reality-write-gate). When no
 *     touched paths are supplied this stage says so explicitly; it never claims to
 *     BE the gate.
 *   - It changes NO behavior. It composes six read-only reports; it writes
 *     nothing, mutates nothing, authorizes nothing, and executes no mutating
 *     command. Every METHOD it calls is itself read-only (a collaborator class may
 *     expose write methods elsewhere; this flow invokes only their read-only ones).
 *   - It does NOT auto-act. P2/P3 surface PROPOSALS; the operator + the existing
 *     gates + the Evidence Ledger do the real work.
 *
 * DEGRADE-SAFE: a throwing collaborator yields {available:false, reason} for that
 * one stage, never a fabricated result — composition must never invent a verdict a
 * collaborator could not produce.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-flow.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
 */
class AtlasDocumentationRealityFlowService
{
    public const SCHEMA = 'atlas.documentation_reality.flow.v1';

    /**
     * The ACTIVE L0 enforcement is the pre-commit hook, never this read-only
     * composition. Surfaced verbatim so a reader can never mistake this flow for the
     * gate that actually blocks a write.
     */
    public const ENFORCEMENT_IS_THE_HOOK = 'scripts/hooks/pre-commit (atlas:documentation-reality-write-gate)';

    public function __construct(
        private readonly AtlasSoftwareTwinRuntimeService $twin,
        private readonly AtlasDocumentationRealityIntentAdvisoryService $intentAdvisory,
        private readonly AtlasDocumentationRealityWriteGateService $writeGate,
        private readonly AtlasDocumentationRealityRepairProposerService $repairProposer,
        private readonly AtlasDocumentationRealityAntibodyProposerService $antibodyProposer,
        private readonly AtlasDocumentationRealityReflectiveStatusService $reflectiveStatus,
    ) {}

    /**
     * Compose the documented flow for ONE proposed change, end to end, read-only.
     * Each section is the REAL, summarised output of the corresponding already-built
     * capability — surfaced, never re-derived.
     *
     * @param  array<string,mixed>  $proposal  the proposed artifact, same shape P1 simulate() / O2 adviseProposal() take
     *                                         ({kind, slug, graph_id, owner, capabilities, governs, implementation_state, symbol, objective?})
     * @param  array<int,string>  $touchedPaths  repo-relative paths the change would touch (drives the L0 verdict + P2 owner-doc focus)
     * @param  array<string,mixed>|null  $failure  an OPTIONAL escaped-failure record; only then does P3 synthesise an antibody
     * @return array<string,mixed>
     */
    public function forProposedChange(array $proposal, array $touchedPaths = [], ?array $failure = null): array
    {
        $touchedPaths = $this->normalizePaths($touchedPaths);
        $objective = $this->str($proposal['objective'] ?? null) ?? '';

        $envelope = [
            'schema_version' => self::SCHEMA,
            'mode' => 'read_only_composed_documented_flow',
            'documents' => 'atlas-documentation-reality-generative-leap.md :: Fluxo alvo para IA',
            'composes_capabilities' => $this->composedCapabilities(),
            'proposal' => $this->echoProposal($proposal, $objective, $touchedPaths),
            'pre_write' => $this->preWrite($proposal, $objective),
            'write_boundary' => $this->writeBoundary($touchedPaths),
            'post_write' => $this->postWrite($touchedPaths, $proposal, $failure),
            'reflective_note' => $this->reflectiveNote(),
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ];

        return $this->finalize($envelope);
    }

    /**
     * PRE-WRITE — foresight BEFORE the write.
     *   - predictive: P1 simulate() summary (verdict, would_duplicate, would_drift, owner).
     *   - intent_advisory: O2 adviseProposal() summary (recommendation + sovereignty block).
     * Both are surfaced from their REAL output; the predictive verdict is shown
     * exactly as P1 returned it (never softened).
     *
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>
     */
    private function preWrite(array $proposal, string $objective): array
    {
        return [
            'stage' => 'P1_predict_then_O2_advise',
            'predictive' => $this->stage(
                'AtlasSoftwareTwinRuntimeService::simulate',
                fn (): array => $this->summarizePredictive($this->twin->simulate($proposal)),
            ),
            'intent_advisory' => $this->stage(
                'AtlasDocumentationRealityIntentAdvisoryService::adviseProposal',
                fn (): array => $this->summarizeIntentAdvisory($this->intentAdvisory->adviseProposal($proposal, $objective)),
            ),
        ];
    }

    /**
     * WRITE BOUNDARY — the L0 enforcement step.
     *   - enforcement: when touchedPaths are given, the REAL decide() verdict for
     *     those paths (mutating posture, since the flow demonstrates a proposed write);
     *     otherwise a note that the L0 PRE-COMMIT HOOK is the active enforcement.
     *   - is_active_via: names the hook verbatim — this composition is never the gate.
     *
     * @param  array<int,string>  $touchedPaths
     * @return array<string,mixed>
     */
    private function writeBoundary(array $touchedPaths): array
    {
        $enforcement = $touchedPaths === []
            ? [
                'note' => 'No touched paths supplied; this composition does not adjudicate. The L0 pre-commit hook is the active write-bound enforcement.',
                'evaluated' => false,
            ]
            : $this->stage(
                'AtlasDocumentationRealityWriteGateService::decide',
                fn (): array => $this->summarizeWriteGate(
                    // is_mutating:true — the flow demonstrates a PROPOSED write. NOTE:
                    // this composition passes ONLY the touched paths, so the verdict here
                    // is an INDICATIVE check of the docs-health dimension. The
                    // AUTHORITATIVE verdict — which also evaluates frontmatter over-claim
                    // drift and the partial-stage/TOCTOU dimension from the real staged
                    // git state — is rendered at the commit boundary by the hook, not here.
                    $this->writeGate->decide([
                        'touched_paths' => $touchedPaths,
                        'is_mutating' => true,
                    ]),
                ),
            );

        return [
            'stage' => 'L0_write_bound_enforcement',
            'is_active_via' => self::ENFORCEMENT_IS_THE_HOOK,
            'enforcement_is_the_hook_not_this' => true,
            // The composed verdict is INDICATIVE (touched-paths docs-health dimension
            // only). The authoritative verdict — also frontmatter-drift + partial-stage —
            // runs at the commit boundary; this composition must never be read as it.
            'verdict_is_indicative_not_authoritative' => $touchedPaths !== [],
            'authoritative_verdict_at' => 'commit boundary via atlas:documentation-reality-write-gate --staged (the pre-commit hook)',
            'enforcement' => $enforcement,
        ];
    }

    /**
     * POST-WRITE — reconcile, then immunise.
     *   - reconciliation: P2 summary. proposeForDoc() focused on the touched/owner doc
     *     when one is resolvable from the touched paths; else proposeAll().
     *   - immunization: P3 proposeFromCapsule() summary ONLY when a failure is
     *     supplied; otherwise the on-demand note (never a fabricated antibody).
     *
     * @param  array<int,string>  $touchedPaths
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $failure
     * @return array<string,mixed>
     */
    private function postWrite(array $touchedPaths, array $proposal, ?array $failure): array
    {
        $ownerDoc = $this->reconciliationFocus($touchedPaths, $proposal);

        $reconciliation = $this->stage(
            $ownerDoc !== null
                ? 'AtlasDocumentationRealityRepairProposerService::proposeForDoc'
                : 'AtlasDocumentationRealityRepairProposerService::proposeAll',
            fn (): array => $this->summarizeReconciliation(
                $ownerDoc !== null
                    ? $this->repairProposer->proposeForDoc($ownerDoc)
                    : $this->repairProposer->proposeAll(),
                $ownerDoc,
            ),
        );

        $immunization = $failure === null
            ? [
                'note' => 'no escaped failure supplied; antibody synthesis is on-demand',
                'evaluated' => false,
            ]
            : $this->stage(
                'AtlasDocumentationRealityAntibodyProposerService::proposeFromCapsule',
                fn (): array => $this->summarizeImmunization($this->antibodyProposer->proposeFromCapsule($failure)),
            );

        return [
            'stage' => 'P2_reconcile_then_P3_immunize',
            'reconciliation' => $reconciliation,
            'immunization' => $immunization,
        ];
    }

    /**
     * A SHORT L-inf reflective note: the headline confidence from selfAssessment()
     * (calibrated, never a bare verdict) plus the standing reminder that this flow is
     * a read-only composition, not the asymptote and not the enforcement. Degrade-safe.
     *
     * @return array<string,mixed>
     */
    private function reflectiveNote(): array
    {
        return $this->stage(
            'AtlasDocumentationRealityReflectiveStatusService::selfAssessment',
            function (): array {
                $assessment = $this->reflectiveStatus->selfAssessment();

                return [
                    'level' => $this->str($assessment['level'] ?? null) ?? 'L-inf (one promoted fragment: R2 epistemic humility)',
                    'headline_confidence' => $this->str(data_get($assessment, 'headline.confidence')) ?? 'unknown',
                    'headline_is_bare_verdict' => (bool) data_get($assessment, 'headline.is_bare_verdict', false),
                    'linf_complete' => (bool) ($assessment['linf_complete'] ?? false),
                    'note' => 'This flow is a READ-ONLY composition of the six already-built ADRS capabilities into the documented "Fluxo alvo para IA". It changes no behavior, it is NOT the enforcement (the L0 pre-commit hook is), and it does not auto-act.',
                ];
            },
        );
    }

    /**
     * Run ONE composed stage degrade-safely. On any throw the stage degrades to
     * {available:false, reason} — it NEVER fabricates a result a collaborator could
     * not produce. On success the collaborator's REAL summary is returned with
     * available:true and the source method named for audit.
     *
     * @param  callable():array<string,mixed>  $call
     * @return array<string,mixed>
     */
    private function stage(string $source, callable $call): array
    {
        try {
            return ['available' => true, 'source' => $source] + $call();
        } catch (Throwable $e) {
            return [
                'available' => false,
                'source' => $source,
                'reason' => $this->errorSlug($e),
            ];
        }
    }

    /**
     * Summarise P1 simulate() — surfaced VERBATIM from prediction.*, never re-derived.
     * The verdict and would_duplicate flag are exactly what P1 produced.
     *
     * @param  array<string,mixed>  $prediction
     * @return array<string,mixed>
     */
    private function summarizePredictive(array $prediction): array
    {
        $duplicate = (array) data_get($prediction, 'prediction.would_duplicate', []);
        $owner = data_get($prediction, 'prediction.owner');

        return [
            'schema_version' => $this->str($prediction['schema_version'] ?? null),
            'verdict' => $this->str(data_get($prediction, 'prediction.verdict')) ?? 'unknown',
            'would_duplicate' => ($duplicate['duplicate'] ?? false) === true,
            'duplicate_reason' => $this->str($duplicate['reason'] ?? null),
            'would_drift' => (bool) data_get($prediction, 'prediction.would_drift', false),
            'owner' => is_array($owner)
                ? [
                    'resolved' => ($owner['resolved'] ?? false) === true,
                    'owner_doc_id' => $this->str($owner['owner_doc_id'] ?? null),
                    'owner_doc_path' => $this->str($owner['owner_doc_path'] ?? null),
                ]
                : null,
            'degraded' => (bool) data_get($prediction, 'prediction.degraded', false),
        ];
    }

    /**
     * Summarise O2 adviseProposal() — the recommendation + the mandatory sovereignty
     * block, surfaced as the advisory produced them.
     *
     * @param  array<string,mixed>  $advisory
     * @return array<string,mixed>
     */
    private function summarizeIntentAdvisory(array $advisory): array
    {
        return [
            'schema_version' => $this->str($advisory['schema_version'] ?? null),
            'recommendation' => $this->str(data_get($advisory, 'advisory_recommendation.value')) ?? 'unknown',
            'is_a_decision' => (bool) data_get($advisory, 'advisory_recommendation.is_a_decision', false),
            'sovereignty' => [
                'advisory_only' => (bool) data_get($advisory, 'sovereignty.advisory_only', false),
                'human_gated' => (bool) data_get($advisory, 'sovereignty.human_gated', false),
                'never_overrides_operator' => (bool) data_get($advisory, 'sovereignty.never_overrides_operator', false),
                'operator_decides' => (bool) data_get($advisory, 'sovereignty.operator_decides', false),
                'is_a_decision' => (bool) data_get($advisory, 'sovereignty.is_a_decision', false),
                'auto_acts' => (bool) data_get($advisory, 'sovereignty.auto_acts', false),
            ],
        ];
    }

    /**
     * Summarise the L0 decide() verdict — surfaced exactly as the gate rendered it
     * (decision + the new docs-health/drift blocker counts), never re-adjudicated.
     *
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    private function summarizeWriteGate(array $verdict): array
    {
        return [
            'schema_version' => $this->str($verdict['schema_version'] ?? null),
            'decision' => $this->str($verdict['decision'] ?? null) ?? 'unknown',
            'is_mutating' => (bool) ($verdict['is_mutating'] ?? false),
            'touched_doc_count' => (int) ($verdict['touched_doc_count'] ?? 0),
            'new_docs_health_blocker_count' => count((array) ($verdict['new_docs_health_blockers'] ?? [])),
            'drift_blocker_count' => count((array) ($verdict['drift_blockers'] ?? [])),
            'blocker' => $this->str($verdict['blocker'] ?? null),
            'reason' => $this->str($verdict['reason'] ?? null),
        ];
    }

    /**
     * Summarise P2 — drift_count + proposal_count straight from the proposer's
     * summary, plus its degraded flag (so a withheld corpus-wide downgrade is honest).
     *
     * @param  array<string,mixed>  $proposals
     * @return array<string,mixed>
     */
    private function summarizeReconciliation(array $proposals, ?string $ownerDoc): array
    {
        return [
            'schema_version' => $this->str($proposals['schema_version'] ?? null),
            'capability_filter' => $ownerDoc,
            'drift_count' => (int) data_get($proposals, 'summary.drift_count', 0),
            'proposal_count' => (int) data_get($proposals, 'summary.proposal_count', 0),
            'degraded' => (bool) ($proposals['degraded'] ?? false),
        ];
    }

    /**
     * Summarise P3 proposeFromCapsule() — the antibody count + whether the (required)
     * reproducing-test outline is present, surfaced as the proposer produced it.
     *
     * @param  array<string,mixed>  $antibody
     * @return array<string,mixed>
     */
    private function summarizeImmunization(array $antibody): array
    {
        return [
            'schema_version' => $this->str($antibody['schema_version'] ?? null),
            'antibody_count' => (int) data_get($antibody, 'summary.antibody_count', 0),
            'failure_count' => (int) data_get($antibody, 'summary.failure_count', 0),
            'has_reproducing_test_outline' => data_get($antibody, 'antibodies.0.reproducing_test_outline.steps') !== null,
            'status' => $this->str(data_get($antibody, 'antibodies.0.status')),
            'degraded' => (bool) ($antibody['degraded'] ?? false),
        ];
    }

    /**
     * Resolve the doc P2 should focus its reconciliation on: the first canonical
     * .md path among the touched paths, else the proposal's graph_id/slug, else null
     * (P2 then scans the whole ledger). This only NARROWS the proposer's existing
     * filter; it never changes its verdict.
     *
     * @param  array<int,string>  $touchedPaths
     * @param  array<string,mixed>  $proposal
     */
    private function reconciliationFocus(array $touchedPaths, array $proposal): ?string
    {
        foreach ($touchedPaths as $path) {
            if (str_ends_with(strtolower($path), '.md')) {
                return $path;
            }
        }

        return $this->str($proposal['graph_id'] ?? null) ?? $this->str($proposal['slug'] ?? null);
    }

    /**
     * Echo back what the flow was asked to compose (read-only projection), so the
     * envelope is self-describing.
     *
     * @param  array<string,mixed>  $proposal
     * @param  array<int,string>  $touchedPaths
     * @return array<string,mixed>
     */
    private function echoProposal(array $proposal, string $objective, array $touchedPaths): array
    {
        return [
            'kind' => $this->str($proposal['kind'] ?? null) ?? 'doc',
            'slug' => $this->str($proposal['slug'] ?? null),
            'graph_id' => $this->str($proposal['graph_id'] ?? null),
            'owner' => $this->str($proposal['owner'] ?? null),
            'symbol' => $this->str($proposal['symbol'] ?? ($proposal['symbol_name'] ?? null)),
            'capabilities' => array_values(array_map('strval', (array) ($proposal['capabilities'] ?? []))),
            'governs' => array_values(array_map('strval', (array) ($proposal['governs'] ?? []))),
            'implementation_state' => $this->str($proposal['implementation_state'] ?? null),
            'objective' => $objective !== '' ? $objective : null,
            'touched_paths' => $touchedPaths,
        ];
    }

    /**
     * The six already-built capabilities this flow composes — named by their
     * source method so the composition is auditable and provably read-only.
     *
     * @return array<int,array<string,string>>
     */
    private function composedCapabilities(): array
    {
        return [
            ['rung' => 'P1', 'role' => 'predict', 'source' => 'AtlasSoftwareTwinRuntimeService::simulate'],
            ['rung' => 'O2', 'role' => 'advise', 'source' => 'AtlasDocumentationRealityIntentAdvisoryService::adviseProposal'],
            ['rung' => 'L0', 'role' => 'enforce_verdict', 'source' => 'AtlasDocumentationRealityWriteGateService::decide'],
            ['rung' => 'P2', 'role' => 'reconcile', 'source' => 'AtlasDocumentationRealityRepairProposerService::proposeAll|proposeForDoc'],
            ['rung' => 'P3', 'role' => 'immunize', 'source' => 'AtlasDocumentationRealityAntibodyProposerService::proposeFromCapsule'],
            ['rung' => 'L-inf', 'role' => 'reflective_note', 'source' => 'AtlasDocumentationRealityReflectiveStatusService::selfAssessment'],
        ];
    }

    /**
     * The read-only claim policy. composes_only and enforcement_is_the_hook_not_this
     * are the load-bearing flags: they assert, in the envelope itself, that this is a
     * composition of existing capabilities and not the gate that enforces.
     *
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes' => false,
            'composes_only' => true,
            'changes_no_behavior' => true,
            'enforcement_is_the_hook_not_this' => true,
            'executes' => false,
            'mutates' => false,
            'authorizes_mutation' => false,
            'auto_acts' => false,
            're_derives_verdicts' => false,
        ];
    }

    /**
     * @param  array<int,string>  $paths
     * @return array<int,string>
     */
    private function normalizePaths(array $paths): array
    {
        $out = [];
        foreach ($paths as $raw) {
            $path = trim((string) $raw);
            if ($path !== '') {
                $out[] = $path;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Hash the read-only envelope for tamper-evidence, excluding the volatile
     * generated_at the command adds and the hash field itself.
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function finalize(array $envelope): array
    {
        $hashPayload = $envelope;
        unset($hashPayload['generated_at'], $hashPayload['flow_hash']);
        $envelope['flow_hash'] = hash(
            'sha256',
            json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $envelope;
    }

    private function errorSlug(Throwable $e): string
    {
        $short = strtolower((new \ReflectionClass($e))->getShortName());
        $slug = preg_replace('/[^a-z0-9]+/', '_', $short) ?? 'error';

        return trim($slug, '_') ?: 'error';
    }

    private function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
