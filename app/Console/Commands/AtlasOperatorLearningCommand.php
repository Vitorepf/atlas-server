<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Models\OperatorLearningCandidate;
use App\Models\OperatorProfileItem;
use App\Services\Ai\OperatorIntelligence\OperatorLearningCandidateService;
use App\Services\Ai\OperatorIntelligence\OperatorProfileDigestService;
use App\Services\Ai\OperatorIntelligence\OperatorProfileProjectionService;
use App\Services\Ai\OperatorIntelligence\OperatorProfileRegistry;
use App\Services\Ai\OperatorIntelligence\OperatorSignalCaptureService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasOperatorLearningCommand extends Command
{
    use EmitsCanonicalJson;

    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:operator-learning
        {action : capture | review | approve | reject | profile | digest | project | simulate}
        {--operator= : Operator id. Defaults to config default.}
        {--claim= : Normalized learning claim.}
        {--raw-excerpt= : Raw excerpt to hash but not persist.}
        {--taxonomy= : SYS-*, OP-* or COL-* taxonomy id.}
        {--source-type=manual : Source type for capture.}
        {--source-ref-type= : Optional source ref type.}
        {--source-ref-id= : Optional source ref id.}
        {--trace-id= : Optional trace id.}
        {--session-id= : Optional session id.}
        {--privacy=normal : normal | private | sensitive | secret.}
        {--risk=low : low | medium | high | critical.}
        {--confidence=0.5 : Confidence 0..1.}
        {--scope-type=global : global | project | workspace | session | task | domain | flow.}
        {--scope-id= : Optional scope id.}
        {--profile-key= : Optional profile key for candidate/profile.}
        {--effect= : Optional policy effect.}
        {--automation-level= : Optional automation level.}
        {--candidate= : Candidate id for review/approve/reject.}
        {--decision= : Decision for review.}
        {--notes= : Optional operator notes; presence only is stored in receipts.}
        {--limit=50 : Max rows for list actions.}
        {--days=7 : Digest window in days.}
        {--json : Emit JSON.}';

    protected $description = 'Operator Intelligence Layer: capture, review, promote, inspect, digest and project operator learning.';

    public function handle(
        OperatorSignalCaptureService $capture,
        OperatorLearningCandidateService $candidates,
        OperatorProfileRegistry $registry,
        OperatorProfileDigestService $digest,
        OperatorProfileProjectionService $projection,
    ): int {
        $action = strtolower(trim((string) $this->argument('action')));

        try {
            $payload = match ($action) {
                'capture' => $capture->capture($this->captureInput(false)),
                'simulate' => $capture->capture($this->captureInput(true)),
                'review' => $this->review($candidates),
                'approve' => $candidates->review($this->requiredCandidate(), 'approve', $this->operatorId(), $this->stringOption('notes')),
                'reject' => $candidates->review($this->requiredCandidate(), 'reject', $this->operatorId(), $this->stringOption('notes')),
                'profile' => $this->profile($registry),
                'digest' => $digest->digest($this->operatorId(), max(1, (int) $this->option('days'))),
                'project' => $projection->project($this->operatorId()),
                default => ['ok' => false, 'error' => 'unsupported_action', 'supported' => ['capture', 'review', 'approve', 'reject', 'profile', 'digest', 'project', 'simulate']],
            };
        } catch (Throwable $e) {
            $payload = ['ok' => false, 'error' => 'exception', 'message' => $e->getMessage(), 'type' => $e::class];
        }

        $this->emit($payload);

        return ($payload['ok'] ?? true) === false ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function captureInput(bool $dryRun): array
    {
        return [
            'operator_id' => $this->operatorId(),
            'claim' => $this->stringOption('claim'),
            'raw_excerpt' => $this->stringOption('raw-excerpt'),
            'taxonomy_item_id' => $this->stringOption('taxonomy'),
            'source_type' => $this->stringOption('source-type') ?? 'manual',
            'source_ref_type' => $this->stringOption('source-ref-type'),
            'source_ref_id' => $this->stringOption('source-ref-id'),
            'trace_id' => $this->stringOption('trace-id'),
            'session_id' => $this->stringOption('session-id'),
            'privacy_class' => $this->stringOption('privacy') ?? 'normal',
            'risk_level' => $this->stringOption('risk') ?? 'low',
            'confidence' => (float) $this->option('confidence'),
            'scope_type' => $this->stringOption('scope-type') ?? 'global',
            'scope_id' => $this->stringOption('scope-id'),
            'value' => array_filter([
                'profile_key' => $this->stringOption('profile-key'),
                'effect' => $this->stringOption('effect'),
                'automation_level' => $this->stringOption('automation-level'),
            ], fn (mixed $value): bool => $value !== null),
            // The operator typed this claim directly — the human IS the verification, so it
            // carries trusted provenance and may auto-apply (governed by the same gate).
            'metadata' => ['auto_apply_provenance' => \App\Services\Ai\OperatorIntelligence\OperatorLearningGate::AUTO_APPLY_PROVENANCE_MANUAL],
            'dry_run' => $dryRun,
        ];
    }

    private function review(OperatorLearningCandidateService $candidates): array
    {
        $decision = $this->stringOption('decision');
        if ($decision !== null) {
            return $candidates->review($this->requiredCandidate(), $decision, $this->operatorId(), $this->stringOption('notes'));
        }

        return [
            'ok' => true,
            'operator_id' => $this->operatorId(),
            'items' => $candidates->listPending($this->operatorId(), (int) $this->option('limit'))
                ->map(fn (OperatorLearningCandidate $candidate): array => $candidates->payload($candidate))
                ->all(),
        ];
    }

    private function profile(OperatorProfileRegistry $registry): array
    {
        return [
            'ok' => true,
            'operator_id' => $this->operatorId(),
            'items' => array_map(
                fn (OperatorProfileItem $item): array => $registry->payload($item),
                $registry->activeForOperator($this->operatorId(), ['limit' => (int) $this->option('limit')]),
            ),
        ];
    }

    private function requiredCandidate(): string
    {
        $candidate = $this->stringOption('candidate');
        if ($candidate === null) {
            throw new \InvalidArgumentException('--candidate is required');
        }

        return $candidate;
    }

    private function operatorId(): string
    {
        return $this->stringOption('operator') ?? (string) config('atlas_operator_intelligence.default_operator_id', 'default');
    }


    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        $this->jsonLine($payload);
    }
}
