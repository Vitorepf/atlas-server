<?php

declare(strict_types=1);

namespace Tests\Feature\Foundry;

use App\Services\Ai\Foundry\FoundryEvidenceVerifierService;
use PHPUnit\Framework\TestCase;

/**
 * FASE 4 / Pilar 2 · Semantic Gap-Finder CONTRACT harness.
 *
 * Spec: docs/engineering-knowledge-base/atlas-afef-semantic-gap-finder.md
 *
 * The production service FoundrySemanticGapFinderService is future-spec
 * (proposal-only, read-only). This harness PROVES the contract deterministically
 * from a fixture WITHOUT any provider call, using a local oracle that implements
 * exactly the spec rules:
 *   - a gap is emitted ONLY when documented capability claim diverges from
 *     runtime reality (closed DRIFT_KINDS set);
 *   - every emitted gap carries an anchor_id present in dossier.anchors[] whose
 *     FoundryEvidenceVerifierService::verifyAnchor returns verdict=confirmed;
 *   - the boilerplate "keep doc in sync" pseudo-gap is NEVER emitted;
 *   - a doc whose claim matches runtime produces ZERO gaps (no noise);
 *   - emission is deterministic (stable report_hash).
 *
 * When the real service lands, swap the oracle for the service: the asserts here
 * are the acceptance contract it must satisfy.
 */
final class FoundrySemanticGapFinderContractTest extends TestCase
{
    private const DRIFT_KINDS = [
        'claimed_available_runtime_blocked',
        'claimed_capability_no_runtime_evidence',
        'runtime_proven_capability_undocumented',
        'plan_incomplete_doc_claims_done',
        'required_test_unproven',
    ];

    private const BANNED_GAP_PHRASES = ['keep doc in sync', 'update documentation', 'keep documentation in sync'];

    /**
     * Fixture dossier: a REAL ledger_event blocker anchor proving the documented
     * capability "merge_provider_proof" is blocked at runtime, plus a confirmed
     * cycle anchor proving "cycle_recording" actually runs.
     *
     * @return array<string,mixed>
     */
    private function fixtureDossier(): array
    {
        return [
            'schema_version' => 'atlas.foundry.dossier.v1',
            'status' => 'ready',
            'area_id' => 'agentic_engineering_os',
            'dossier_hash' => 'sha256:fixturedossierhash',
            'anchors' => [
                [
                    // Cycle that exercised merge_provider_proof and was BLOCKED.
                    // Confirmed via the injected 'cycles' seam (no event_hash
                    // roundtrip needed — same verifier, evidence-anchored).
                    'anchor_id' => 'fanchor_blocker01',
                    'anchor_type' => 'cycle_id',
                    'anchor_source' => 'cycles',
                    'source_path' => 'cycle.cycle_id',
                    'anchor_claim' => 'cyc_merge_blocked_1',
                    'resolved' => true,
                    'integrity_status' => 'ok',
                    'anchor_hash' => 'sha256:a1',
                    'capability_tag' => 'merge_provider_proof',
                ],
                [
                    'anchor_id' => 'fanchor_cycle01',
                    'anchor_type' => 'cycle_id',
                    'anchor_source' => 'cycles',
                    'source_path' => 'cycle.cycle_id',
                    'anchor_claim' => 'cyc_real_001',
                    'resolved' => true,
                    'integrity_status' => 'ok',
                    'anchor_hash' => 'sha256:c1',
                    'capability_tag' => 'cycle_recording',
                ],
            ],
        ];
    }

    /**
     * Verifier input seam: the REAL evidence the strong verifier resolves
     * against. The blocker event and the cycle both exist => both anchors
     * verify as confirmed.
     *
     * @return array<string,mixed>
     */
    private function verifierInput(): array
    {
        return [
            'cycles' => [
                ['cycle_id' => 'cyc_merge_blocked_1', 'area_id' => 'agentic_engineering_os', 'final_status' => 'blocked'],
                ['cycle_id' => 'cyc_real_001', 'area_id' => 'agentic_engineering_os'],
            ],
        ];
    }

    /**
     * Local deterministic oracle implementing the spec gap-finder contract.
     * READ-ONLY: reuses the REAL FoundryEvidenceVerifierService for anchor
     * verdicts (single source of truth — same as gate I1).
     *
     * @param  array<string,mixed>  $dossier
     * @param  list<array<string,mixed>>  $capabilityClaims
     * @return array<string,mixed>
     */
    private function gapFinder(array $dossier, array $capabilityClaims): array
    {
        $verifier = $this->verifier();

        $anchors = [];
        foreach ($dossier['anchors'] as $a) {
            $anchors[$a['anchor_id']] = $a;
        }

        $gaps = [];
        $drops = [];

        foreach ($capabilityClaims as $claim) {
            $capability = (string) $claim['capability'];
            $documentedState = (string) $claim['documented_state'];
            $anchorId = (string) ($claim['anchor_id'] ?? '');
            $driftKind = (string) ($claim['drift_kind'] ?? '');

            // Rule: drift_kind must be in the closed set (no "keep doc in sync").
            if (! in_array($driftKind, self::DRIFT_KINDS, true)) {
                $drops[] = ['capability' => $capability, 'drift_kind' => $driftKind, 'drop_reason' => 'drift_kind_not_in_closed_set', 'detail' => $driftKind];

                continue;
            }

            // Rule: anchor must exist in dossier.anchors[].
            if ($anchorId === '' || ! isset($anchors[$anchorId])) {
                $drops[] = ['capability' => $capability, 'drift_kind' => $driftKind, 'drop_reason' => 'anchor_not_in_dossier', 'detail' => $anchorId];

                continue;
            }

            // Rule: anchor must verify as confirmed via the REAL verifier.
            $verdict = $verifier->verifyAnchor($anchors[$anchorId], $this->verifierInput());
            if (($verdict['verdict'] ?? '') !== FoundryEvidenceVerifierService::VERDICT_CONFIRMED) {
                $drops[] = ['capability' => $capability, 'drift_kind' => $driftKind, 'drop_reason' => 'anchor_refuted', 'detail' => (string) ($verdict['drop_reason'] ?? '')];

                continue;
            }

            // Rule: documented claim must actually diverge from runtime. For
            // claimed_available_runtime_blocked the proving anchor is a blocker
            // event; if the doc does NOT claim available, there is no drift.
            if ($driftKind === 'claimed_available_runtime_blocked' && $documentedState !== 'available') {
                $drops[] = ['capability' => $capability, 'drift_kind' => $driftKind, 'drop_reason' => 'no_semantic_divergence', 'detail' => $documentedState];

                continue;
            }

            $stable = ['capability' => $capability, 'drift_kind' => $driftKind, 'anchor_id' => $anchorId];
            $gapHash = 'sha256:'.hash('sha256', (string) json_encode($stable, JSON_UNESCAPED_SLASHES));
            $gaps[] = [
                'gap_id' => 'gap_'.substr(hash('sha256', (string) json_encode($stable, JSON_UNESCAPED_SLASHES)), 0, 16),
                'capability' => $capability,
                'documented_state' => $documentedState,
                'runtime_state' => (string) ($claim['runtime_state'] ?? 'blocked'),
                'drift_kind' => $driftKind,
                'anchor_id' => $anchorId,
                'anchor_verdict' => 'confirmed',
                'evidence_excerpt' => (string) ($anchors[$anchorId]['anchor_claim'] ?? ''),
                'source_doc' => (string) ($claim['source_doc'] ?? ''),
                'severity' => $driftKind === 'runtime_proven_capability_undocumented' || $driftKind === 'required_test_unproven' ? 'medium' : 'high',
                'gap_hash' => $gapHash,
            ];
        }

        usort($gaps, static fn (array $x, array $y): int => strcmp((string) $x['gap_id'], (string) $y['gap_id']));

        $status = match (true) {
            ($dossier['status'] ?? '') !== 'ready' || $dossier['anchors'] === [] => 'blocked',
            $drops !== [] && $gaps !== [] => 'partial',
            $gaps !== [] => 'ready',
            default => 'ready',
        };

        $report = [
            'schema_version' => 'atlas.foundry.capability_gap_report.v1',
            'status' => $status,
            'area_id' => (string) $dossier['area_id'],
            'gaps' => $gaps,
            'drops' => $drops,
            'gap_count' => count($gaps),
            'claim_policy' => [
                'read_only' => true,
                'generates_code' => false,
                'provider_invoked' => false,
                'canonical_doc_write_allowed' => false,
            ],
        ];
        $report['report_hash'] = 'sha256:'.hash('sha256', (string) json_encode([
            'gaps' => $gaps, 'drops' => $drops, 'area_id' => $report['area_id'], 'status' => $status,
        ], JSON_UNESCAPED_SLASHES));

        return $report;
    }

    private function verifier(): FoundryEvidenceVerifierService
    {
        // No-arg construction: the fixture anchors carry self-contained identity
        // (ledger_events / cycles injected via input), so verifyAnchor resolves
        // without any owner I/O. Construct via reflection without constructor to
        // avoid binding optional collaborators not needed for fixture verify.
        $ref = new \ReflectionClass(FoundryEvidenceVerifierService::class);

        return $ref->newInstanceWithoutConstructor();
    }

    public function test_emits_a_real_evidence_anchored_gap_not_boilerplate(): void
    {
        $report = $this->gapFinder($this->fixtureDossier(), [
            [
                'capability' => 'merge_provider_proof',
                'documented_state' => 'available',
                'runtime_state' => 'blocked',
                'drift_kind' => 'claimed_available_runtime_blocked',
                'anchor_id' => 'fanchor_blocker01',
                'source_doc' => 'atlas-frontier-evolution-foundry',
            ],
        ]);

        self::assertSame(1, $report['gap_count']);
        $gap = $report['gaps'][0];
        self::assertSame('merge_provider_proof', $gap['capability']);
        self::assertSame('claimed_available_runtime_blocked', $gap['drift_kind']);
        self::assertSame('fanchor_blocker01', $gap['anchor_id']);
        self::assertSame('confirmed', $gap['anchor_verdict']);
        self::assertSame('high', $gap['severity']);
        // The proving anchor really exists in the dossier (evidence-anchored).
        $anchorIds = array_column($this->fixtureDossier()['anchors'], 'anchor_id');
        self::assertContains($gap['anchor_id'], $anchorIds);
    }

    public function test_never_emits_keep_doc_in_sync_boilerplate(): void
    {
        // Caller tries to smuggle the old doc-miner pseudo-gap.
        $report = $this->gapFinder($this->fixtureDossier(), [
            [
                'capability' => 'documentation_freshness',
                'documented_state' => 'available',
                'runtime_state' => 'available',
                'drift_kind' => 'keep doc in sync',
                'anchor_id' => 'fanchor_cycle01',
                'source_doc' => 'atlas-frontier-evolution-foundry',
            ],
        ]);

        self::assertSame(0, $report['gap_count']);
        self::assertNotEmpty($report['drops']);
        self::assertSame('drift_kind_not_in_closed_set', $report['drops'][0]['drop_reason']);

        $blob = strtolower((string) json_encode($report['gaps']));
        foreach (self::BANNED_GAP_PHRASES as $phrase) {
            self::assertStringNotContainsString($phrase, $blob);
        }
    }

    public function test_doc_matching_runtime_produces_zero_gaps(): void
    {
        // cycle_recording is documented available AND proven by a confirmed
        // cycle anchor — runtime matches the doc, so there is no real drift.
        $report = $this->gapFinder($this->fixtureDossier(), [
            [
                'capability' => 'cycle_recording',
                'documented_state' => 'building', // not 'available' => no claimed_available drift
                'runtime_state' => 'running',
                'drift_kind' => 'claimed_available_runtime_blocked',
                'anchor_id' => 'fanchor_cycle01',
                'source_doc' => 'atlas-software-company-stewardship-stack',
            ],
        ]);

        self::assertSame(0, $report['gap_count']);
        self::assertSame('no_semantic_divergence', $report['drops'][0]['drop_reason']);
    }

    public function test_gap_without_verifiable_anchor_is_dropped_not_emitted(): void
    {
        $report = $this->gapFinder($this->fixtureDossier(), [
            [
                'capability' => 'phantom_capability',
                'documented_state' => 'available',
                'runtime_state' => 'blocked',
                'drift_kind' => 'claimed_available_runtime_blocked',
                'anchor_id' => 'fanchor_does_not_exist', // not in dossier.anchors[]
                'source_doc' => 'atlas-frontier-evolution-foundry',
            ],
        ]);

        self::assertSame(0, $report['gap_count']);
        self::assertSame('anchor_not_in_dossier', $report['drops'][0]['drop_reason']);
    }

    public function test_emission_is_deterministic(): void
    {
        $claims = [[
            'capability' => 'merge_provider_proof',
            'documented_state' => 'available',
            'runtime_state' => 'blocked',
            'drift_kind' => 'claimed_available_runtime_blocked',
            'anchor_id' => 'fanchor_blocker01',
            'source_doc' => 'atlas-frontier-evolution-foundry',
        ]];

        $a = $this->gapFinder($this->fixtureDossier(), $claims);
        $b = $this->gapFinder($this->fixtureDossier(), $claims);

        self::assertSame($a['report_hash'], $b['report_hash']);
        self::assertSame($a['gaps'], $b['gaps']);
    }

    public function test_real_verifier_actually_confirms_the_proving_anchor(): void
    {
        // Prove the harness is anchored to REAL verification, not a stub: the
        // strong verifier must confirm the fixture blocker anchor.
        $verdict = $this->verifier()->verifyAnchor(
            $this->fixtureDossier()['anchors'][0],
            $this->verifierInput(),
        );
        self::assertSame(FoundryEvidenceVerifierService::VERDICT_CONFIRMED, $verdict['verdict']);
    }
}
