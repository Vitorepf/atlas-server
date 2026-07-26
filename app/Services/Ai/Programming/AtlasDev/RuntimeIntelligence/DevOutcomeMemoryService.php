<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevFailureCapsule;
use App\Models\AtlasDevOutcomeMemory;
use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Aemor\Envelope\OutcomeEnvelopeBridge;
use App\Services\Ai\EngineeringKernel\OutcomeProofGate;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookDevBridge;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;

class DevOutcomeMemoryService
{
    public const SCHEMA_VERSION = 'atlas.dev.outcome_memory.v1';

    /** Learning-candidate marker stamped when a claimed success is refused as fake-green. */
    public const FAKE_GREEN_MARKER = 'fake_green_suppressed';

    public function __construct(
        private readonly OutcomeProofGate $proofGate = new OutcomeProofGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input, array $packet, ?array $failureCapsule = null): array
    {
        $status = $this->normalizeStatus((string) ($input['outcome_status'] ?? $input['status'] ?? ($failureCapsule ? 'failed' : 'success')));
        $evidence = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['evidence_kinds'] ?? $input['evidence'] ?? []);
        $selectedTests = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['selected_tests'] ?? $packet['suggested_tests'] ?? []);
        $changedFiles = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['changed_files'] ?? $failureCapsule['changed_files'] ?? []);

        // Proof gate — the precondition for the Learning Loop. A claimed success whose
        // supplied execution evidence is a lie (0 tests, lint-as-suite, fixed-smoke) is a
        // fake-green: it must NOT earn an AEMOR learning promotion. Same rule as the
        // SovereignHonestyFloor (FalseClaimInvariant), so the two paths cannot drift.
        $proof = $this->proofGate->assess($status, $this->executionEvidence($input, $selectedTests));

        $baselinePromote = (bool) ($input['should_promote_to_aemor'] ?? $status !== 'success' || $evidence !== []);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => (string) ($packet['run_id'] ?? $input['run_id'] ?? 'unknown'),
            'task_id' => (string) ($packet['task_id'] ?? $input['task_id'] ?? 'unknown'),
            'outcome_status' => $status,
            'proven_real' => $proof['proven_real'],
            'fake_green' => $proof['fake_green'],
            'proof_reason' => $proof['reason'],
            'evidence_kinds' => $evidence,
            'selected_tests' => $selectedTests,
            'changed_files' => $changedFiles,
            'learning_candidates' => $this->learningCandidates($status, $packet, $failureCapsule, $evidence, $proof['fake_green']),
            // Never promote a fake-green into learning — that is the garbage-in the Learning
            // Loop must never see. (An honest but thin "unproven" success is left to baseline
            // and flagged via proven_real for the consumer to filter.)
            'should_promote_to_aemor' => $baselinePromote && ! $proof['fake_green'],
            'human_review_required' => (bool) ($input['human_review_required'] ?? in_array($status, ['failed', 'blocked', 'needs_review'], true)) || $proof['fake_green'],
        ];
        $payload['outcome_memory_hash'] = MissionCanonicalHash::sha256($payload);

        $envelope = app(OutcomeEnvelopeBridge::class)
            ->project('dev_procedural', $payload, [
                'provider' => $input['provider'] ?? null,
                'task_category' => 'dev',
                'certified_receipt_id' => $input['certified_receipt_id'] ?? $input['receipt_id'] ?? null,
            ]);
        if ($envelope !== null) {
            $payload['outcome_envelope'] = $envelope;
        }

        return $payload;
    }

    /**
     * Execution-evidence block the proof gate inspects. Only present when the caller
     * supplied one — absence means "unproven", not "fake-green" (no positive lie).
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $selectedTests
     * @return array<string,mixed>
     */
    private function executionEvidence(array $input, array $selectedTests): array
    {
        $execution = is_array($input['execution'] ?? null) ? $input['execution'] : [];
        if ($execution === []) {
            return [];
        }

        // Fold in the recorder's selected_tests so "claimed a suite, ran no test runner" is caught.
        $execution['selected_tests'] = $execution['selected_tests'] ?? $selectedTests;

        return $execution;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function persist(array $input, AtlasDevTaskPacket $taskPacket, ?AtlasDevFailureCapsule $failureCapsule = null): AtlasDevOutcomeMemory
    {
        $payload = $this->build($input, $taskPacket->toArray(), $failureCapsule?->toArray());

        $memory = AtlasDevOutcomeMemory::query()->updateOrCreate(
            ['outcome_memory_hash' => $payload['outcome_memory_hash']],
            [
                'schema_version' => $payload['schema_version'],
                'uuid' => $payload['outcome_memory_hash'],
                'run_id' => $payload['run_id'],
                'task_id' => $payload['task_id'],
                'task_packet_id' => $taskPacket->id,
                'failure_capsule_id' => $failureCapsule?->id,
                'outcome_status' => $payload['outcome_status'],
                'evidence_kinds' => $payload['evidence_kinds'],
                'selected_tests' => $payload['selected_tests'],
                'changed_files' => $payload['changed_files'],
                'learning_candidates' => $payload['learning_candidates'],
                'should_promote_to_aemor' => $payload['should_promote_to_aemor'],
                'human_review_required' => $payload['human_review_required'],
            ],
        );

        // BUILD #3 producer (task-END): feed the real Dev outcome to the general
        // procedural playbook so its measured follow rate moves with real use
        // (SAME proven_real gate) and a proven failure seeds a prior-correction.
        // Keyed by run id — the application id the task-START injection opened.
        // Skipped under phpunit so the broad Dev suite never pollutes the live
        // ledger; fail-open — learning never breaks the outcome write.
        try {
            if (! app()->runningUnitTests()) {
                app(AtlasProceduralPlaybookDevBridge::class)
                    ->recordOutcomeForTask(
                        (string) $payload['run_id'],
                        (string) $payload['outcome_status'],
                        is_array($input['execution'] ?? null) ? $input['execution'] : [],
                        $payload['changed_files'],
                    );
            }
        } catch (\Throwable) {
            // fail-open
        }

        return $memory;
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'success', 'succeeded', 'passed' => 'success',
            'failed', 'failure' => 'failed',
            'blocked' => 'blocked',
            'needs_review', 'review' => 'needs_review',
            default => 'needs_review',
        };
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  null|array<string,mixed>  $failureCapsule
     * @param  list<string>  $evidence
     * @return list<string>
     */
    private function learningCandidates(string $status, array $packet, ?array $failureCapsule, array $evidence, bool $fakeGreen): array
    {
        $items = [];
        if ($status !== 'success') {
            $items[] = 'dev_outcome:'.$status;
        }
        if (($packet['risk_band'] ?? null) === 'high') {
            $items[] = 'high_risk_dev_requires_context_gate';
        }
        if ($failureCapsule !== null && ($failureCapsule['failure_class'] ?? null)) {
            $items[] = 'failure_class:'.$failureCapsule['failure_class'];
        }
        if ($evidence === []) {
            $items[] = 'missing_evidence_on_outcome';
        }
        // Persisted, queryable marker for the fake-green counter (atlas:proof:status).
        if ($fakeGreen) {
            $items[] = self::FAKE_GREEN_MARKER;
        }

        return AtlasDevStringListNormalizer::uniqueMergedStrings($items);
    }

    /**
     * Real fake-green counter: the number of Dev outcomes whose claimed success was
     * refused as fake-green (persisted marker). Measured, never fabricated.
     */
    public static function fakeGreenSuppressedCount(): int
    {
        return AtlasDevOutcomeMemory::query()
            ->whereJsonContains('learning_candidates', self::FAKE_GREEN_MARKER)
            ->count();
    }
}
