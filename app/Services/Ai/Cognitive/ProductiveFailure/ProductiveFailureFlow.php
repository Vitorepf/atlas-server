<?php

namespace App\Services\Ai\Cognitive\ProductiveFailure;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Gates\ProductiveFailurePhaseCompleteGate;
use App\Services\Ai\Kernel\Gates\ProductiveFailureProblemCalibratedGate;
use Illuminate\Support\Str;

class ProductiveFailureFlow
{
    public const SCHEMA_VERSION = 'atlas.cognitive.productive_failure_flow.v1';

    public function __construct(
        private readonly ProductiveFailureSessionRepository $sessions,
        private readonly ProductiveFailureProblemSelector $problems,
        private readonly ProductiveFailureAttemptCapture $attempts,
        private readonly ProductiveFailureComparisonEngine $comparison,
        private readonly ProductiveFailureArticulationCapture $articulation,
        private readonly ProductiveFailureTransferTestScheduler $transferTests,
        private readonly ProductiveFailurePhaseController $phases,
        private readonly ProductiveFailurePhaseCompleteGate $phaseGate,
        private readonly ProductiveFailureProblemCalibratedGate $calibrationGate,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function start(string $topic, string $domain = 'learning', int $dreyfusStage = 2): array
    {
        $problem = $this->problems->select($topic, $domain, $dreyfusStage);
        $gate = $this->calibrationGate->evaluate($problem, $dreyfusStage);

        if (($gate['status'] ?? null) !== 'passed') {
            return $this->payload('blocked', ['gate' => $gate, 'problem' => $problem]);
        }

        $session = $this->sessions->create([
            'envelope_id' => (string) Str::uuid(),
            'knowledge_node_id' => (string) $problem['knowledge_node_id'],
            'domain' => $domain,
            'dreyfus_stage_target' => max(1, min(5, $dreyfusStage)),
            'phase_1_problem' => $problem,
        ]);

        $this->record(LedgerEventType::ProductiveFailurePhase1Started, $session, [
            'dreyfus_stage_target' => $dreyfusStage,
            'topic' => $topic,
            'problem' => $problem,
            'gate' => $gate,
        ]);

        return $this->payload('started', [
            'session' => $session,
            'gate' => $gate,
            'next_action' => 'attempt',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function recordAttempt(int $sessionId, array $input): array
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            return $this->payload('missing', ['reason' => 'productive_failure_session_missing']);
        }

        $gate = $this->phaseGate->evaluate($session, 'phase_2');
        if (($gate['status'] ?? null) !== 'passed') {
            return $this->payload('blocked', ['gate' => $gate, 'session' => $session]);
        }

        $attempt = $this->attempts->capture($input);
        if (! (bool) ($attempt['attempt_complete'] ?? false)) {
            return $this->payload('invalid_input', ['reason' => 'productive_failure_attempt_requires_prediction_and_attempt', 'attempt' => $attempt]);
        }

        $updated = $this->sessions->recordAttempt($sessionId, $attempt);
        $this->record(LedgerEventType::ProductiveFailurePhase1AttemptRecorded, $updated, ['attempt' => $attempt]);
        $this->record(LedgerEventType::ProductiveFailurePhase2Started, $updated, ['reason' => 'phase_1_attempt_recorded']);

        return $this->payload('attempt_recorded', [
            'session' => $updated,
            'next_action' => 'compare',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function recordComparison(int $sessionId, ?string $validatedReality = null, ?string $operatorDelta = null): array
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            return $this->payload('missing', ['reason' => 'productive_failure_session_missing']);
        }

        $gate = $this->phaseGate->evaluate($session, 'phase_3');
        if (($gate['status'] ?? null) !== 'passed') {
            return $this->payload('blocked', ['gate' => $gate, 'session' => $session]);
        }

        $comparison = $this->comparison->compare($session, $validatedReality, $operatorDelta);
        if (($comparison['status'] ?? null) !== 'comparison_ready') {
            return $this->payload('blocked', ['reason' => 'productive_failure_prediction_error_delta_missing', 'comparison' => $comparison]);
        }

        $updated = $this->sessions->recordComparison($sessionId, $comparison['worked_example_id'] ?? null, $comparison);
        $this->record(LedgerEventType::ProductiveFailurePhase2ComparisonRecorded, $updated, ['comparison' => $comparison]);
        $this->record(LedgerEventType::ProductiveFailurePhase3Started, $updated, ['reason' => 'phase_2_comparison_recorded']);

        return $this->payload('comparison_recorded', [
            'session' => $updated,
            'next_action' => 'articulate',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function recordArticulation(int $sessionId, array $input): array
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            return $this->payload('missing', ['reason' => 'productive_failure_session_missing']);
        }

        $gate = $this->phaseGate->evaluate($session, 'complete');
        if (($gate['status'] ?? null) !== 'passed') {
            return $this->payload('blocked', ['gate' => $gate, 'session' => $session]);
        }

        $articulation = $this->articulation->capture($input);
        if (trim((string) ($articulation['model_update'] ?? '')) === '' || trim((string) ($articulation['principle_extracted'] ?? '')) === '') {
            return $this->payload('invalid_input', ['reason' => 'productive_failure_articulation_requires_model_update_and_principle', 'articulation' => $articulation]);
        }

        $transferTest = $this->transferTests->propose($session, $articulation);
        $updated = $this->sessions->recordArticulation($sessionId, $articulation, $transferTest);
        $this->record(LedgerEventType::ProductiveFailureArticulationRecorded, $updated, [
            'articulation' => $articulation,
            'transfer_test' => $transferTest,
        ]);

        return $this->payload('articulation_recorded', [
            'session' => $updated,
            'transfer_test' => $transferTest,
            'next_action' => 'complete',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function complete(int $sessionId): array
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            return $this->payload('missing', ['reason' => 'productive_failure_session_missing']);
        }

        $gate = $this->phaseGate->evaluate($session, 'complete');
        if (($gate['status'] ?? null) !== 'passed') {
            return $this->payload('blocked', ['gate' => $gate, 'session' => $session]);
        }

        $updated = $this->sessions->complete($sessionId);
        $this->record(LedgerEventType::ProductiveFailureCompleted, $updated, [
            'completion_status' => 'complete',
            'transfer_test' => $updated['phase_3_transfer_test'] ?? [],
        ]);

        return $this->payload('completed', ['session' => $updated]);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(int $sessionId): array
    {
        $session = $this->sessions->find($sessionId);

        return $this->payload($session ? 'ok' : 'missing', [
            'session' => $session,
            'current_phase' => $session ? $this->phases->currentPhase($session) : null,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function history(?string $domain = null, int $days = 30): array
    {
        return $this->payload('ok', [
            'sessions' => $this->sessions->history($domain, $days),
            'window_days' => max(1, $days),
        ]);
    }

    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $extra
     */
    private function record(LedgerEventType $type, array $session, array $extra = []): void
    {
        $this->ledger->record($type, array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'session_id' => $session['id'] ?? null,
            'knowledge_node_id' => $session['knowledge_node_id'] ?? null,
            'domain' => $session['domain'] ?? null,
            'completion_status' => $session['completion_status'] ?? null,
        ], $extra), [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_productive_failure_cli',
            'envelope_id' => (string) ($session['envelope_id'] ?? 'productive_failure:unknown'),
            'correlation_id' => 'productive_failure:'.($session['id'] ?? 'unknown'),
            'emitter_stage' => 'atlas.cognitive.productive_failure',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function payload(string $status, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'flow' => 'learning.productive_failure',
        ], $extra);
    }
}
