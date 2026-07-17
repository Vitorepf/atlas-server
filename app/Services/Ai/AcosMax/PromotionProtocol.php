<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\Support\AiValueNormalizer;

final class PromotionProtocol
{
    public const SCHEMA = 'atlas.acos.promotion_protocol.v1';

    public const REPORT_SCHEMA = 'atlas.acos.promotion_protocol.report.v1';

    public const STATE_OFF = 'off';

    public const STATE_SHADOW = 'shadow';

    public const STATE_LIVE = 'live';

    public const STATE_ROLLED_BACK = 'rolled_back';

    public const STATE_SUSPENDED_PENDING_EVIDENCE = 'suspended_pending_evidence';

    public const STATE_LEGACY_UNMANAGED = 'legacy_unmanaged';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_OK = 'ok';

    public const STATUS_MANAGED = 'managed';

    public const STATUS_LEGACY_UNMANAGED = 'legacy_unmanaged';

    public const STATUS_BLOCKED = 'blocked';

    public const FIELD_OK = 'ok';

    public const FIELD_MISSING = 'missing';

    public const DEFAULT_LEDGER_RELATIVE_PATH = 'app/atlas/evidence/acos-max-promotion-flips.jsonl';

    /** @var list<string> */
    public const STATES = [
        self::STATE_OFF,
        self::STATE_SHADOW,
        self::STATE_LIVE,
        self::STATE_ROLLED_BACK,
        self::STATE_SUSPENDED_PENDING_EVIDENCE,
    ];

    /** @var list<string> */
    public const REQUIRED_FIELDS = [
        'shadow_minimum_window',
        'flip_criterion',
        'rollback_trigger',
        'judge_engine_id',
        'receipt',
    ];

    /** @var list<array<string,mixed>> */
    private array $entries;

    /** @var list<array<string,mixed>> */
    private array $legacyFlags;

    private JsonlReceiptStore $ledger;

    /**
     * @param  list<array<string,mixed>>|null  $entries
     * @param  list<array<string,mixed>|string>|null  $legacyFlags
     */
    public function __construct(
        ?string $ledgerPath = null,
        ?array $entries = null,
        ?array $legacyFlags = null,
    ) {
        $this->ledger = new JsonlReceiptStore($ledgerPath ?? storage_path(self::DEFAULT_LEDGER_RELATIVE_PATH));
        $this->entries = array_values(array_map(
            fn (array $entry): array => $this->normalizeManagedEntry($entry),
            $entries ?? self::defaultEntries(),
        ));
        $this->legacyFlags = array_values(array_map(
            fn (array|string $entry): array => $this->normalizeLegacyEntry($entry),
            $legacyFlags ?? self::defaultLegacyFlags(),
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function ledgerEvents(): array
    {
        return $this->ledger->read();
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function flip(string $flagId, string $toState, array $context = []): array
    {
        $flagId = AiValueNormalizer::trimmedStringOrNull($flagId) ?? '';
        $toState = AiValueNormalizer::trimmedStringOrNull($toState) ?? '';
        $entry = $this->entryById($flagId);
        if ($entry === null) {
            return $this->blocked('unknown_flag', $flagId, $toState);
        }
        if (! in_array($toState, self::STATES, true)) {
            return $this->blocked('invalid_state', $flagId, $toState, ['allowed_states' => self::STATES]);
        }

        $required = $this->requiredFields($entry);
        if ($required[self::FIELD_OK] !== true) {
            $missing = AiValueNormalizer::arrayOrEmpty($required[self::FIELD_MISSING] ?? null);

            return $this->blocked(
                in_array('rollback_trigger', $missing, true)
                    ? 'missing_predeclared_rollback_trigger'
                    : 'missing_required_fields',
                $flagId,
                $toState,
                [self::FIELD_MISSING => $missing],
            );
        }

        $windowId = AiValueNormalizer::trimmedStringOrNull($context['observation_window_id'] ?? null) ?? '';
        if ($windowId === '') {
            return $this->blocked('missing_observation_window_id', $flagId, $toState);
        }

        $receipt = AiValueNormalizer::trimmedStringOrNull($context['receipt'] ?? null) ?? '';
        if ($receipt === '') {
            return $this->blocked('missing_flip_receipt', $flagId, $toState);
        }

        $family = AiValueNormalizer::trimmedScalarStringOrNull($entry['family'] ?? null) ?? '';
        $action = $this->actionForState($toState);
        if ($action === 'flip' && $this->familyAlreadyFlippedInWindow($family, $windowId)) {
            return $this->blocked('family_window_flip_already_recorded', $flagId, $toState, [
                'family' => $family,
                'observation_window_id' => $windowId,
            ]);
        }

        $fromState = $this->stateForFlag($flagId);
        $event = [
            'schema_version' => self::SCHEMA,
            'event_id' => hash('sha256', implode('|', [
                $flagId,
                $fromState,
                $toState,
                $windowId,
                (string) microtime(true),
            ])),
            'recorded_at' => date('c'),
            'action' => $action,
            'flag_id' => $flagId,
            'family' => $family,
            'slice' => (AiValueNormalizer::trimmedStringOrNull($entry['slice'] ?? null) ?? ''),
            'from_state' => $fromState,
            'to_state' => $toState,
            'observation_window_id' => $windowId,
            'actor' => AiValueNormalizer::trimmedStringOrNull($context['actor'] ?? null) ?? 'atlas',
            'reason' => AiValueNormalizer::trimmedStringOrNull($context['reason'] ?? null) ?? '',
            'receipt' => $receipt,
            'rollback_trigger' => AiValueNormalizer::trimmedScalarStringOrNull($entry['rollback_trigger'] ?? null) ?? '',
            'judge_engine_id' => AiValueNormalizer::trimmedScalarStringOrNull($entry['judge_engine_id'] ?? null) ?? '',
            'protocol_receipt' => AiValueNormalizer::trimmedScalarStringOrNull($entry['receipt'] ?? null) ?? '',
        ];

        $challenger = $this->observeChallenger($context);
        if ($challenger !== null) {
            // Observe-only ESP-09 advisory — never vetoes the flip decision.
            $event['challenger_advisory'] = $challenger;
        }

        $this->ledger->append($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            self::FIELD_OK => true,
            'status' => self::STATUS_RECORDED,
            'schema_version' => self::SCHEMA,
            'event' => $event,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $events = $this->ledgerEvents();
        $managed = array_map(
            fn (array $entry): array => $this->managedReportEntry($entry, $events),
            $this->entries,
        );
        $managedIds = array_fill_keys(array_map(
            fn (array $entry): string => AiValueNormalizer::trimmedScalarStringOrNull($entry['id'] ?? null) ?? '',
            $managed,
        ), true);
        $legacy = array_values(array_filter(
            array_map(fn (array $entry): array => $this->legacyReportEntry($entry), $this->legacyFlags),
            fn (array $entry): bool => ! isset($managedIds[AiValueNormalizer::trimmedScalarStringOrNull($entry['id'] ?? null) ?? '']),
        ));

        return [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => self::STATUS_OK,
            'protocol_schema_version' => self::SCHEMA,
            'states' => self::STATES,
            'ledger_path' => $this->ledger->path(),
            'managed_flags_count' => count($managed),
            'legacy_unmanaged_flags_count' => count($legacy),
            'flags' => array_values(array_merge($managed, $legacy)),
        ];
    }

    /**
     * Known Max gates and operator-only flips from the ACOS Max plan. Callers that touch
     * a flag next should replace/extend this row instead of inventing a local protocol.
     *
     * @return list<array<string,mixed>>
     */
    public static function defaultEntries(): array
    {
        return [
            [
                'id' => 'ATLAS_AUTONOMOS_MASTER_ENABLED',
                'family' => 'ASI',
                'slice' => 'ASI-06',
                'state' => self::STATE_OFF,
                'env_key' => 'ATLAS_AUTONOMOS_MASTER_ENABLED',
                'shadow_minimum_window' => 'operator_preflight_window',
                'flip_criterion' => 'atlas:autonomos:preflight --json returns 8/8 green with ASI-01/02/05 evidence',
                'rollback_trigger' => 'operator disables autonomos master on failed preflight regression or scoped-committer violation',
                'judge_engine_id' => 'codex-elev26s-judge',
                'receipt' => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:1412',
                'operator_only' => true,
            ],
            [
                'id' => 'ATLAS_AUTONOMOUS_AUTO_APPLY',
                'family' => 'ASI',
                'slice' => 'ASI-07',
                'state' => self::STATE_OFF,
                'config_key' => 'atlas.ai.autonomous_learning.enabled',
                'env_key' => 'ATLAS_AUTONOMOUS_AUTO_APPLY',
                'shadow_minimum_window' => '7d',
                'flip_criterion' => 'privacy fail-closed, reversal proven, and digest FEE-12 operational',
                'rollback_trigger' => 'disable auto-apply when reversal_rate or negative_feedback guard breaches soak bounds',
                'judge_engine_id' => 'codex-elev26s-judge',
                'receipt' => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:1413',
                'operator_only' => true,
            ],
            [
                'id' => 'ATLAS_BRAIN_REFLECTION_ENABLED',
                'family' => 'ASI',
                'slice' => 'ASI-08',
                'state' => self::STATE_OFF,
                'config_key' => 'atlas.brain.reflection_enabled',
                'env_key' => 'ATLAS_BRAIN_REFLECTION_ENABLED',
                'shadow_minimum_window' => '24h',
                'flip_criterion' => 'reflection stream writes real post-landing entries and consumer reads PathYieldEwma samples',
                'rollback_trigger' => 'disable reflection writer if pattern-ledger writes fail or no consumer traffic is observed',
                'judge_engine_id' => 'codex-elev26s-judge',
                'receipt' => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:1311',
            ],
            [
                'id' => 'atlas.memory.fusion_v2_enabled',
                'family' => 'MAXB',
                'slice' => 'MAXB-03',
                'state' => self::STATE_OFF,
                'config_key' => 'atlas.memory.fusion_v2_enabled',
                'shadow_minimum_window' => '7d',
                'flip_criterion' => 'golden v2 shows RRF cross-source precision improvement with OFF byte-identical',
                'rollback_trigger' => 'return to legacy ranking formula on golden v2 regression or improper floor discard',
                'judge_engine_id' => 'codex-elev26s-judge',
                'receipt' => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:330',
            ],
            [
                'id' => 'acos.land.autonomous_verification_required',
                'family' => 'ASI',
                'slice' => 'ASI-10',
                'state' => self::STATE_OFF,
                'shadow_minimum_window' => '7d',
                'flip_criterion' => 'MULTV-01/02 receipts cover derived tier and verified_share floor is green',
                'rollback_trigger' => 'disable autonomous land enforcement on false block or receipt-seal regression',
                'judge_engine_id' => 'codex-elev26s-judge',
                'receipt' => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:2681',
            ],
            [
                'id' => 'acos.mutation_score.enforce_by_executor',
                'family' => 'MULTV',
                'slice' => 'MULTV-03',
                'state' => self::STATE_OFF,
                'shadow_minimum_window' => '8 samples per executor',
                'flip_criterion' => 'mutation MSI low advisory correlates with later real failure for the executor',
                'rollback_trigger' => 'suspend executor family on negative root A/B or cost breach',
                'judge_engine_id' => 'codex-elev26s-judge',
                'receipt' => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:2639',
            ],
            [
                'id' => 'atlas.memory.contextual_blurb_enabled',
                'family' => 'RAGX',
                'slice' => 'RAGX-02',
                'state' => self::STATE_OFF,
                'config_key' => 'atlas.memory.contextual_blurb_enabled',
                'shadow_minimum_window' => '20 judged queries',
                'flip_criterion' => 'code/KB R8 precision@5 improves by >=0.05 with latency reported',
                'rollback_trigger' => 'disable contextual blurbs on precision regression or hallucinated-blurb sample failure',
                'judge_engine_id' => 'codex-elev26s-judge',
                'receipt' => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:2022',
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function defaultLegacyFlags(): array
    {
        return [
            [
                'id' => 'atlas.memory.feedback_ranking_enabled',
                'family' => 'FEE',
                'config_key' => 'atlas.memory.feedback_ranking_enabled',
                'env_key' => 'ATLAS_MEMORY_FEEDBACK_RANKING_ENABLED',
                'source' => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:225',
            ],
            [
                'id' => 'atlas.aobg.semantic_retrieval',
                'family' => 'AOBG',
                'config_key' => 'atlas.aobg.semantic_retrieval',
                'source' => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:252',
            ],
            [
                'id' => 'ATLAS_AOBG_FUSION_ENABLED',
                'family' => 'AOBG',
                'env_key' => 'ATLAS_AOBG_FUSION_ENABLED',
                'source' => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:743',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function entryById(string $flagId): ?array
    {
        foreach ($this->entries as $entry) {
            if ((AiValueNormalizer::trimmedScalarStringOrNull($entry['id'] ?? null) ?? '') === $flagId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeManagedEntry(array $entry): array
    {
        $state = AiValueNormalizer::trimmedStringOrNull($entry['state'] ?? null) ?? self::STATE_OFF;
        if (! in_array($state, self::STATES, true)) {
            $state = self::STATE_OFF;
        }

        return array_merge($entry, [
            'id' => AiValueNormalizer::trimmedStringOrNull($entry['id'] ?? null) ?? '',
            'family' => strtoupper(AiValueNormalizer::trimmedStringOrNull($entry['family'] ?? null) ?? ''),
            'slice' => AiValueNormalizer::trimmedStringOrNull($entry['slice'] ?? null) ?? '',
            'state' => $state,
            'status' => self::STATUS_MANAGED,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeLegacyEntry(array|string $entry): array
    {
        $payload = is_array($entry) ? $entry : ['id' => $entry];

        return array_merge($payload, [
            'id' => AiValueNormalizer::trimmedStringOrNull($payload['id'] ?? null) ?? '',
            'family' => strtoupper(AiValueNormalizer::trimmedStringOrNull($payload['family'] ?? null) ?? 'LEGACY'),
            'status' => self::STATUS_LEGACY_UNMANAGED,
        ]);
    }

    /**
     * @return array{ok:bool,missing:list<string>}
     */
    private function requiredFields(array $entry): array
    {
        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if ((AiValueNormalizer::trimmedStringOrNull($entry[$field] ?? null) ?? '') === '') {
                $missing[] = $field;
            }
        }

        return [self::FIELD_OK => $missing === [], self::FIELD_MISSING => $missing];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>|null
     */
    private function observeChallenger(array $context): ?array
    {
        $author = AiValueNormalizer::trimmedStringOrNull($context['author_engine_id'] ?? null) ?? '';
        $challenger = AiValueNormalizer::trimmedStringOrNull($context['challenger_engine_id'] ?? null) ?? '';
        if ($author === '' || $challenger === '') {
            return null;
        }

        return Esp09IndependentChallengerService::evaluate([
            'author_engine_id' => $author,
            'challenger_engine_id' => $challenger,
            'operator_alignment' => $context['operator_alignment'] ?? null,
            'decision_kind' => $context['decision_kind'] ?? 'ordinary_route',
        ]);
    }

    private function stateForFlag(string $flagId): string
    {
        $state = null;
        foreach ($this->ledgerEvents() as $event) {
            if ((AiValueNormalizer::trimmedStringOrNull($event['flag_id'] ?? null) ?? '') !== $flagId) {
                continue;
            }
            $candidate = (AiValueNormalizer::trimmedStringOrNull($event['to_state'] ?? null) ?? '');
            if (in_array($candidate, self::STATES, true)) {
                $state = $candidate;
            }
        }
        if ($state !== null) {
            return $state;
        }

        return AiValueNormalizer::trimmedStringOrNull($this->entryById($flagId)['state'] ?? null) ?? self::STATE_OFF;
    }

    private function familyAlreadyFlippedInWindow(string $family, string $windowId): bool
    {
        foreach ($this->ledgerEvents() as $event) {
            if (($event['action'] ?? null) !== 'flip') {
                continue;
            }
            if ((AiValueNormalizer::trimmedStringOrNull($event['family'] ?? null) ?? '') === $family
                && (AiValueNormalizer::trimmedStringOrNull($event['observation_window_id'] ?? null) ?? '') === $windowId) {
                return true;
            }
        }

        return false;
    }

    private function actionForState(string $state): string
    {
        return match ($state) {
            self::STATE_ROLLED_BACK => 'rollback',
            self::STATE_SUSPENDED_PENDING_EVIDENCE => 'suspend',
            default => 'flip',
        };
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $reason, string $flagId, string $toState, array $extra = []): array
    {
        return array_merge([
            self::FIELD_OK => false,
            'status' => self::STATUS_BLOCKED,
            'schema_version' => self::SCHEMA,
            'reason' => $reason,
            'flag_id' => $flagId,
            'to_state' => $toState,
        ], $extra);
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function managedReportEntry(array $entry, array $events): array
    {
        $id = AiValueNormalizer::trimmedStringOrNull($entry['id'] ?? null) ?? '';
        $last = null;
        foreach ($events as $event) {
            if ((AiValueNormalizer::trimmedStringOrNull($event['flag_id'] ?? null) ?? '') === $id) {
                $last = $event;
            }
        }

        return [
            'id' => $id,
            'status' => self::STATUS_MANAGED,
            'family' => AiValueNormalizer::trimmedStringOrNull($entry['family'] ?? null) ?? '',
            'slice' => AiValueNormalizer::trimmedStringOrNull($entry['slice'] ?? null) ?? '',
            'state' => $last !== null
                ? (AiValueNormalizer::trimmedStringOrNull($last['to_state'] ?? null) ?? (AiValueNormalizer::trimmedStringOrNull($entry['state'] ?? null) ?? ''))
                : (AiValueNormalizer::trimmedStringOrNull($entry['state'] ?? null) ?? ''),
            'config_key' => $entry['config_key'] ?? null,
            'env_key' => $entry['env_key'] ?? null,
            'operator_only' => (AiValueNormalizer::boolOrNull($entry['operator_only'] ?? null) ?? false),
            'required_fields' => $this->requiredFields($entry),
            'shadow_minimum_window' => AiValueNormalizer::trimmedStringOrNull($entry['shadow_minimum_window'] ?? null) ?? '',
            'flip_criterion' => AiValueNormalizer::trimmedStringOrNull($entry['flip_criterion'] ?? null) ?? '',
            'rollback_trigger' => AiValueNormalizer::trimmedStringOrNull($entry['rollback_trigger'] ?? null) ?? '',
            'judge_engine_id' => AiValueNormalizer::trimmedStringOrNull($entry['judge_engine_id'] ?? null) ?? '',
            'receipt' => AiValueNormalizer::trimmedStringOrNull($entry['receipt'] ?? null) ?? '',
            'last_flip' => $last,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function legacyReportEntry(array $entry): array
    {
        return [
            'id' => AiValueNormalizer::trimmedStringOrNull($entry['id'] ?? null) ?? '',
            'status' => self::STATUS_LEGACY_UNMANAGED,
            'family' => AiValueNormalizer::trimmedStringOrNull($entry['family'] ?? null) ?? 'LEGACY',
            'state' => self::STATE_LEGACY_UNMANAGED,
            'config_key' => $entry['config_key'] ?? null,
            'env_key' => $entry['env_key'] ?? null,
            'source' => $entry['source'] ?? null,
            'migration_policy' => 'migrate_on_next_touch',
        ];
    }
}
