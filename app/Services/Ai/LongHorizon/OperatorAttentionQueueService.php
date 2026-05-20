<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Models\AiForgeIntake;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * TEOS-I3 · Operator Attention Queue.
 *
 * Read-only queue projection over long-horizon signals. It does not persist
 * inbox rows or mutate Obra/memory state; it returns the minimum set of
 * operator decisions that should be reviewed before long work continues.
 */
class OperatorAttentionQueueService
{
    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly StrategicForgettingService $strategicForgetting,
        private readonly ObraReviewService $obraReview,
        private readonly LongHorizonContinuityCertificationService $continuityCertification,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input = []): array
    {
        $scopeType = $this->stringOrNull($input['scope_type'] ?? null);
        $scopeId = $this->stringOrNull($input['scope_id'] ?? null);
        $intake = $this->stringOrNull($input['intake'] ?? $input['intake_id'] ?? null);
        $limit = max(1, min(200, (int) ($input['limit'] ?? 50)));
        $now = ($input['now'] ?? null) instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();

        if ($scopeType !== null && ! in_array($scopeType, AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES, true)) {
            throw new InvalidArgumentException("scope_type [{$scopeType}] not in long-horizon canon");
        }

        $sources = [];
        $items = [];

        [$forgettingSource, $forgettingItems] = $this->fromStrategicForgetting($scopeType, $scopeId, $limit, $now);
        $sources[] = $forgettingSource;
        $items = [...$items, ...$forgettingItems];

        [$obraSource, $obraItems] = $this->fromObraReview($intake, $now);
        $sources[] = $obraSource;
        $items = [...$items, ...$obraItems];

        if ($scopeType !== null) {
            [$continuitySource, $continuityItems] = $this->fromContinuityCertification($scopeType, $scopeId, $now);
            $sources[] = $continuitySource;
            $items = [...$items, ...$continuityItems];
        }

        $items = $this->dedupe($items);
        usort($items, fn (array $a, array $b): int => $this->priority($b) <=> $this->priority($a));
        $items = array_slice($items, 0, $limit);

        $payload = [
            'schema_version' => AtlasLongHorizonCanon::OPERATOR_ATTENTION_QUEUE_SCHEMA_VERSION,
            'status' => $this->status($items, $sources),
            'generated_at' => $now->toJSON(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'intake_ref' => $intake,
            'summary' => $this->summary($items),
            'items' => $items,
            'digest' => $this->digest($items),
            'sources' => $sources,
            'claim_policy' => [
                'read_only' => true,
                'does_not_mutate_inbox' => true,
                'does_not_mutate_obra' => true,
                'does_not_delete_memory' => true,
                'benchmark_not_run' => true,
                'rivals_compared' => false,
            ],
        ];
        $payload['queue_hash'] = $this->hashQueue($payload);

        return $payload;
    }

    /**
     * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>}
     */
    private function fromStrategicForgetting(?string $scopeType, ?string $scopeId, int $limit, CarbonImmutable $now): array
    {
        try {
            $memoryScopeType = $scopeType;
            if ($memoryScopeType !== null && ! in_array($memoryScopeType, AtlasMemoryEntry::SCOPES, true)) {
                $memoryScopeType = null;
            }

            $receipt = $this->strategicForgetting->plan([
                'scope_type' => $memoryScopeType,
                'scope_id' => $scopeId,
                'limit' => $limit,
                'now' => $now,
            ]);
        } catch (Throwable $exception) {
            return [$this->source('strategic_forgetting', self::STATUS_BLOCKED, [$exception->getMessage()]), []];
        }

        $items = [];
        foreach ((array) ($receipt['decisions'] ?? []) as $decision) {
            if (! (bool) ($decision['requires_human_review'] ?? false)) {
                continue;
            }

            $policy = (string) ($decision['policy'] ?? 'unknown');
            $memoryId = (string) ($decision['memory_entry_id'] ?? 'unknown');
            $severity = $policy === AtlasLongHorizonCanon::FORGETTING_POLICY_FORGET
                ? AtlasLongHorizonCanon::ATTENTION_SEVERITY_CRITICAL
                : AtlasLongHorizonCanon::ATTENTION_SEVERITY_HIGH;

            $items[] = $this->item(
                dedupeKey: "teos:strategic_forgetting:{$memoryId}:{$policy}",
                source: 'strategic_forgetting',
                severity: $severity,
                title: 'Memory requires operator review',
                reason: (string) ($decision['reason'] ?? 'human_review_required'),
                action: 'review_memory_forgetting_policy',
                evidenceRefs: (array) ($decision['evidence_refs'] ?? []),
                payload: [
                    'memory_entry_id' => $memoryId,
                    'memory_hash' => $decision['memory_hash'] ?? null,
                    'policy' => $policy,
                    'read_only_effect' => $decision['read_only_effect'] ?? null,
                ],
            );
        }

        return [
            $this->source('strategic_forgetting', (string) ($receipt['status'] ?? 'unknown'), [], $receipt['receipt_hash'] ?? null),
            $items,
        ];
    }

    /**
     * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>}
     */
    private function fromObraReview(?string $intake, CarbonImmutable $now): array
    {
        if ($intake === null && (! Schema::hasTable('ai_forge_intakes') || ! AiForgeIntake::query()->exists())) {
            return [$this->source('obra_review', 'skipped'), []];
        }

        try {
            $review = $this->obraReview->review(['intake' => $intake, 'now' => $now]);
        } catch (Throwable $exception) {
            return [$this->source('obra_review', self::STATUS_BLOCKED, [$exception->getMessage()]), []];
        }

        $items = [];
        foreach ((array) data_get($review, 'weekly_synthesis.blockers', []) as $index => $blocker) {
            $kind = (string) ($blocker['kind'] ?? 'blocker');
            $ref = (string) ($blocker['ref'] ?? data_get($review, 'intake.uuid', $index));
            $items[] = $this->item(
                dedupeKey: "teos:obra_review:blocker:{$kind}:{$ref}",
                source: 'obra_review',
                severity: AtlasLongHorizonCanon::ATTENTION_SEVERITY_HIGH,
                title: 'Forge Obra blocker needs resolution',
                reason: (string) ($blocker['reason'] ?? $kind),
                action: 'resolve_obra_blocker',
                evidenceRefs: (array) ($review['evidence_refs'] ?? []),
                payload: ['kind' => $kind, 'ref' => $ref, 'intake' => data_get($review, 'intake.uuid')],
            );
        }

        foreach ((array) data_get($review, 'monthly_architecture_review.stale_risks', []) as $index => $risk) {
            $kind = (string) ($risk['kind'] ?? 'stale_risk');
            $ref = (string) ($risk['ref'] ?? data_get($review, 'intake.uuid', $index));
            $items[] = $this->item(
                dedupeKey: "teos:obra_review:stale_risk:{$kind}:{$ref}",
                source: 'obra_review',
                severity: AtlasLongHorizonCanon::ATTENTION_SEVERITY_MEDIUM,
                title: 'Forge Obra context should be refreshed',
                reason: $kind,
                action: 'refresh_obra_context',
                evidenceRefs: (array) ($review['evidence_refs'] ?? []),
                payload: ['kind' => $kind, 'ref' => $ref, 'intake' => data_get($review, 'intake.uuid')],
            );
        }

        if ((bool) data_get($review, 'monthly_architecture_review.operator_decision_required', false)) {
            $items[] = $this->item(
                dedupeKey: 'teos:obra_review:monthly_decision:'.((string) data_get($review, 'intake.uuid', 'latest')),
                source: 'obra_review',
                severity: AtlasLongHorizonCanon::ATTENTION_SEVERITY_HIGH,
                title: 'Monthly Obra architecture decision required',
                reason: 'operator_decision_required',
                action: 'choose_monthly_obra_decision',
                evidenceRefs: (array) ($review['evidence_refs'] ?? []),
                payload: [
                    'intake' => data_get($review, 'intake.uuid'),
                    'recommended_decision' => data_get($review, 'monthly_architecture_review.recommended_decision'),
                    'decision_options' => data_get($review, 'monthly_architecture_review.decision_options', []),
                ],
            );
        }

        if (($review['status'] ?? null) === ObraReviewService::STATUS_BLOCKED) {
            foreach ((array) ($review['blockers'] ?? []) as $reason) {
                $items[] = $this->item(
                    dedupeKey: 'teos:obra_review:blocked:'.sha1((string) $reason),
                    source: 'obra_review',
                    severity: AtlasLongHorizonCanon::ATTENTION_SEVERITY_CRITICAL,
                    title: 'Obra review cannot run',
                    reason: (string) $reason,
                    action: 'restore_obra_review_inputs',
                    evidenceRefs: [],
                    payload: ['intake_ref' => $intake],
                );
            }
        }

        return [
            $this->source('obra_review', (string) ($review['status'] ?? 'unknown'), (array) ($review['blockers'] ?? []), $review['review_hash'] ?? null),
            $items,
        ];
    }

    /**
     * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>}
     */
    private function fromContinuityCertification(string $scopeType, ?string $scopeId, CarbonImmutable $now): array
    {
        try {
            $certification = $this->continuityCertification->certify([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'now' => $now,
                'intended_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            ]);
        } catch (Throwable $exception) {
            return [$this->source('continuity_certification', self::STATUS_BLOCKED, [$exception->getMessage()]), []];
        }

        $items = [];
        foreach ((array) ($certification['blockers'] ?? []) as $blocker) {
            $reason = is_array($blocker) ? (string) ($blocker['reason'] ?? $blocker['check_id'] ?? 'continuity_blocker') : (string) $blocker;
            $items[] = $this->item(
                dedupeKey: 'teos:continuity:blocker:'.sha1($scopeType.'|'.($scopeId ?? '').'|'.$reason),
                source: 'continuity_certification',
                severity: AtlasLongHorizonCanon::ATTENTION_SEVERITY_CRITICAL,
                title: 'Continuity certification is blocked',
                reason: $reason,
                action: 'repair_continuity_before_resume',
                evidenceRefs: (array) ($certification['evidence_refs'] ?? []),
                payload: ['scope_type' => $scopeType, 'scope_id' => $scopeId],
            );
        }
        foreach ((array) ($certification['warnings'] ?? []) as $warning) {
            $reason = is_array($warning) ? (string) ($warning['reason'] ?? $warning['check_id'] ?? 'continuity_warning') : (string) $warning;
            $items[] = $this->item(
                dedupeKey: 'teos:continuity:warning:'.sha1($scopeType.'|'.($scopeId ?? '').'|'.$reason),
                source: 'continuity_certification',
                severity: AtlasLongHorizonCanon::ATTENTION_SEVERITY_MEDIUM,
                title: 'Continuity certification needs review',
                reason: $reason,
                action: 'review_continuity_warning',
                evidenceRefs: (array) ($certification['evidence_refs'] ?? []),
                payload: ['scope_type' => $scopeType, 'scope_id' => $scopeId],
            );
        }

        return [
            $this->source('continuity_certification', (string) ($certification['status'] ?? 'unknown'), (array) ($certification['blockers'] ?? []), $certification['certification_hash'] ?? null),
            $items,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function dedupe(array $items): array
    {
        $deduped = [];
        foreach ($items as $item) {
            $key = (string) ($item['dedupe_key'] ?? sha1(json_encode($item) ?: ''));
            if (! isset($deduped[$key]) || $this->priority($item) > $this->priority($deduped[$key])) {
                $deduped[$key] = $item;
            }
        }

        return array_values($deduped);
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,array<string,mixed>>  $sources
     */
    private function status(array $items, array $sources): string
    {
        foreach ($items as $item) {
            if (($item['severity'] ?? null) === AtlasLongHorizonCanon::ATTENTION_SEVERITY_CRITICAL) {
                return self::STATUS_BLOCKED;
            }
        }
        foreach ($sources as $source) {
            if (($source['status'] ?? null) === self::STATUS_BLOCKED) {
                return self::STATUS_BLOCKED;
            }
        }

        return $items === [] ? self::STATUS_READY : self::STATUS_WATCH;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function summary(array $items): array
    {
        $bySeverity = array_fill_keys(AtlasLongHorizonCanon::OPERATOR_ATTENTION_SEVERITIES, 0);
        $bySource = [];
        foreach ($items as $item) {
            $severity = (string) ($item['severity'] ?? AtlasLongHorizonCanon::ATTENTION_SEVERITY_LOW);
            if (array_key_exists($severity, $bySeverity)) {
                $bySeverity[$severity]++;
            }
            $source = (string) ($item['source'] ?? 'unknown');
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;
        }

        return [
            'total' => count($items),
            'by_severity' => $bySeverity,
            'by_source' => $bySource,
            'requires_operator_action' => $items !== [],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function digest(array $items): array
    {
        $low = array_values(array_filter($items, fn (array $item): bool => ($item['severity'] ?? null) === AtlasLongHorizonCanon::ATTENTION_SEVERITY_LOW));

        return [
            'low_priority_grouped_count' => count($low),
            'anti_fatigue_policy' => 'surface_critical_high_first_group_low_priority',
        ];
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function item(string $dedupeKey, string $source, string $severity, string $title, string $reason, string $action, array $evidenceRefs, array $payload = []): array
    {
        return [
            'schema_version' => 'atlas.teos.operator_attention_item.v1',
            'dedupe_key' => $dedupeKey,
            'source' => $source,
            'severity' => $severity,
            'priority_score' => $this->scoreForSeverity($severity),
            'title' => $title,
            'reason' => $reason,
            'recommended_action' => $action,
            'evidence_refs' => array_values(array_unique(array_map('strval', $evidenceRefs))),
            'payload' => $this->sanitizePayload($payload),
        ];
    }

    /**
     * @param  array<int,string>  $blockers
     * @return array<string,mixed>
     */
    private function source(string $name, string $status, array $blockers = [], mixed $hash = null): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'blockers_count' => count($blockers),
            'hash' => is_string($hash) ? $hash : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        unset($payload['body'], $payload['raw_text'], $payload['operator_input'], $payload['response_text']);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function priority(array $item): int
    {
        return (int) ($item['priority_score'] ?? $this->scoreForSeverity((string) ($item['severity'] ?? 'low')));
    }

    private function scoreForSeverity(string $severity): int
    {
        return match ($severity) {
            AtlasLongHorizonCanon::ATTENTION_SEVERITY_CRITICAL => 100,
            AtlasLongHorizonCanon::ATTENTION_SEVERITY_HIGH => 80,
            AtlasLongHorizonCanon::ATTENTION_SEVERITY_MEDIUM => 50,
            default => 20,
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashQueue(array $payload): string
    {
        unset($payload['generated_at']);

        return MissionCanonicalHash::sha256($payload);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
