<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

/**
 * Append-only JSONL ledger of admitted lesson plans. Idempotent on plan_hash: a second append
 * with the same plan_hash returns ['status' => 'already_recorded', ...] without writing a new
 * line. flock(LOCK_EX) so concurrent admit() calls don't interleave.
 *
 * ADMISSION GUARDS (throw InvalidArgumentException before hash or disk access):
 *   - source_evidence_refs must be non-empty and free of placeholder markers (todo, fake,
 *     synthetic, example, tbd) — a lesson cannot be admitted on the strength of a stub reference.
 *   - context.muscle_outcome.status must be one of success|resolved|green_commit — a lesson can
 *     only influence future origination when it is bound to a REAL muscle outcome.
 *   - plan.impact_class and plan.design_path_refs must both be present and non-empty — a lesson
 *     with no declared impact class or no reusable design path is not transferable.
 */
final class AtlasSelfConstructionLearningTransferAdmissionLedger
{
    public const SCHEMA = 'atlas.learning_transfer.admission_ledger.v1';

    private const VALID_MUSCLE_OUTCOME_STATUSES = ['success', 'resolved', 'green_commit'];

    private const PLACEHOLDER_MARKERS = ['todo', 'fake', 'synthetic', 'example', 'tbd'];

    public function __construct(private readonly string $ledgerPath) {}

    /**
     * Canonical default ledger location: config-pinned (phpunit pins it to /tmp so
     * fixture lessons never poison the real ledger), storage fallback otherwise.
     */
    public static function defaultPath(): string
    {
        $configured = function_exists('config')
            ? (string) (config('atlas.self_construction.learning_transfer_admission_ledger_path') ?? '')
            : '';
        if ($configured !== '') {
            return $configured;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/learning-transfer/admission.jsonl')
            : sys_get_temp_dir().'/atlas-learning-transfer-admission.jsonl';
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function append(array $plan, array $context = []): array
    {
        // Admission guards — throw before hash or disk access.
        $sourceRefs = array_values(array_filter(array_map('strval', (array) ($plan['source_evidence_refs'] ?? []))));
        if ($sourceRefs === []) {
            throw new \InvalidArgumentException('learning_transfer_admission_refused:missing_source_evidence_refs');
        }
        foreach ($sourceRefs as $ref) {
            $refLower = strtolower($ref);
            foreach (self::PLACEHOLDER_MARKERS as $marker) {
                if (str_contains($refLower, $marker)) {
                    throw new \InvalidArgumentException('learning_transfer_admission_refused:placeholder_evidence_ref:'.$ref);
                }
            }
        }

        $muscleOutcomeStatus = strtolower((string) ($context['muscle_outcome']['status'] ?? ''));
        if (! in_array($muscleOutcomeStatus, self::VALID_MUSCLE_OUTCOME_STATUSES, true)) {
            throw new \InvalidArgumentException('learning_transfer_admission_refused:missing_or_invalid_muscle_outcome:'.$muscleOutcomeStatus);
        }

        $impactClass = trim((string) ($plan['impact_class'] ?? ''));
        if ($impactClass === '') {
            throw new \InvalidArgumentException('learning_transfer_admission_refused:missing_impact_class');
        }

        $designPathRefs = array_values(array_filter(array_map('strval', (array) ($plan['design_path_refs'] ?? []))));
        if ($designPathRefs === []) {
            throw new \InvalidArgumentException('learning_transfer_admission_refused:missing_design_path_refs');
        }

        $classLabel = strtolower((string) ($context['classification']['label'] ?? $context['classification']['class'] ?? ''));
        if (in_array($classLabel, ['proxy', 'cosmetic'], true)) {
            throw new \InvalidArgumentException('learning_transfer_admission_refused:proxy_or_cosmetic:'.$classLabel);
        }

        $gateVerdict = strtolower((string) ($context['gate_decision']['verdict'] ?? $context['gate_decision']['decision'] ?? ''));
        if ($gateVerdict !== '' && ! in_array($gateVerdict, ['admit', 'allow'], true)) {
            throw new \InvalidArgumentException('learning_transfer_admission_refused:invalid_gate_decision:'.$gateVerdict);
        }

        $planHash = $this->planHash($plan);
        $row = [
            'schema_version' => self::SCHEMA,
            'plan_hash' => $planHash,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => (string) ($context['mode'] ?? 'observe'),
            'intended_action' => (string) ($context['intended_action'] ?? 'apply_plan'),
            'plan' => $plan,
            'classification' => (array) ($context['classification'] ?? []),
            'gate_decision' => (array) ($context['gate_decision'] ?? []),
            'muscle_outcome' => (array) ($context['muscle_outcome'] ?? []),
            'impact_class' => $impactClass,
            'design_path_refs' => $designPathRefs,
        ];
        ksort($row, SORT_STRING);

        // Idempotency check runs INSIDE the store's write lock; null return aborts the append.
        $written = (new JsonlReceiptStore($this->ledgerPath))->appendWith(
            fn (?string $lastLine): ?array => $this->findByHash($planHash) !== null ? null : $row,
        );

        return [
            'schema_version' => self::SCHEMA,
            'status' => $written === null ? 'already_recorded' : 'recorded',
            'plan_hash' => $planHash,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return (new JsonlReceiptStore($this->ledgerPath))->replay();
    }

    public function path(): string
    {
        return $this->ledgerPath;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    public function planHash(array $plan): string
    {
        $sorted = SharedAtlasSelfConstructionLearningLedgerSeam::sortLedgerPayloadRecursively($plan);

        return hash('sha256', (string) json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByHash(string $hash): ?array
    {
        return SharedAtlasSelfConstructionLearningLedgerSeam::findLedgerRowByHash($this->all(), 'plan_hash', $hash);
    }
}
