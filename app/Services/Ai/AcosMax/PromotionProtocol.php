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

    public const FIELD_FAMILY = 'family';

    public const FIELD_STATE = 'state';

    public const FIELD_JUDGE_ENGINE_ID = 'judge_engine_id';

    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';

    public const FIELD_SHADOW_MINIMUM_WINDOW = 'shadow_minimum_window';

    public const FIELD_FLIP_CRITERION = 'flip_criterion';

    public const FIELD_ROLLBACK_TRIGGER = 'rollback_trigger';

    public const FIELD_RECEIPT = 'receipt';

    public const FIELD_TO_STATE = 'to_state';

    public const FIELD_FLAG_ID = 'flag_id';


    public const FIELD_SLICE = 'slice';

    public const FIELD_CONFIG_KEY = 'config_key';

    public const FIELD_ENV_KEY = 'env_key';

    public const FIELD_STATUS = 'status';

    public const FIELD_OBSERVATION_WINDOW_ID = 'observation_window_id';

    public const FIELD_SOURCE = 'source';

    public const FIELD_OPERATOR_ONLY = 'operator_only';
    public const FIELD_ID = 'id';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_REASON = 'reason';
    public const FIELD_ACTION = 'action';
    public const FIELD_ACTOR = 'actor';
    public const FIELD_ALLOWED_STATES = 'allowed_states';
    public const FIELD_CHALLENGER_ADVISORY = 'challenger_advisory';
    public const FIELD_CHALLENGER_ENGINE_ID = 'challenger_engine_id';
    public const FIELD_DECISION_KIND = 'decision_kind';
    public const FIELD_OPERATOR_ALIGNMENT = 'operator_alignment';
    public const FIELD_EVENT = 'event';
    public const FIELD_EVENT_ID = 'event_id';
    public const FIELD_FROM_STATE = 'from_state';

    public const DEFAULT_LEDGER_RELATIVE_PATH = 'app/atlas/evidence/acos-max-promotion-flips.jsonl';
    public const FIELD_RECORDED_AT = 'recorded_at';
    public const FIELD_PROTOCOL_RECEIPT = 'protocol_receipt';
    public const FIELD_STATES = 'states';
    public const FIELD_REQUIRED_FIELDS = 'required_fields';
    public const FIELD_FLAGS = 'flags';
    public const FIELD_LAST_FLIP = 'last_flip';
    public const FIELD_LEDGER_PATH = 'ledger_path';
    public const FIELD_LEGACY_UNMANAGED_FLAGS_COUNT = 'legacy_unmanaged_flags_count';
    public const FIELD_MANAGED_FLAGS_COUNT = 'managed_flags_count';
    public const FIELD_MIGRATION_POLICY = 'migration_policy';
    public const FIELD_PROTOCOL_SCHEMA_VERSION = 'protocol_schema_version';
    public const FIELD_FLIP = 'flip';
    public const FIELD_ATLAS = 'atlas';
    public const FIELD_MIGRATE_ON_NEXT_TOUCH = 'migrate_on_next_touch';
    public const FIELD_MISSING_PREDECLARED_ROLLBACK_TRIGGER = 'missing_predeclared_rollback_trigger';
    public const FIELD_OPERATOR_PREFLIGHT_WINDOW = 'operator_preflight_window';
    public const FIELD_ORDINARY_ROUTE = 'ordinary_route';
    public const FIELD_ROLLBACK = 'rollback';
    public const FIELD_SUSPEND = 'suspend';
    public const FIELD_FAMILY_WINDOW_FLIP_ALREADY_RECORDED = 'family_window_flip_already_recorded';
    public const FIELD_INVALID_STATE = 'invalid_state';
    public const FIELD_MISSING_FLIP_RECEIPT = 'missing_flip_receipt';
    public const FIELD_MISSING_OBSERVATION_WINDOW_ID = 'missing_observation_window_id';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_UNKNOWN_FLAG = 'unknown_flag';
    public const FIELD_MISSING_REQUIRED_FIELDS = 'missing_required_fields';
    public const FIELD_ASI = 'ASI';
    public const FIELD_AOBG = 'AOBG';
    public const FIELD_ATLAS_AOBG_FUSION_ENABLED = 'ATLAS_AOBG_FUSION_ENABLED';
    public const FIELD_ATLAS_AUTONOMOS_MASTER_ENABLED = 'ATLAS_AUTONOMOS_MASTER_ENABLED';
    public const FIELD_ATLAS_AUTONOMOUS_AUTO_APPLY = 'ATLAS_AUTONOMOUS_AUTO_APPLY';
    public const FIELD_ATLAS_BRAIN_REFLECTION_ENABLED = 'ATLAS_BRAIN_REFLECTION_ENABLED';
    public const FIELD_LEGACY = 'LEGACY';
    public const FIELD_ATLAS_MEMORY_FEEDBACK_RANKING_ENABLED = 'ATLAS_MEMORY_FEEDBACK_RANKING_ENABLED';
    public const FIELD_FEE = 'FEE';
    public const FIELD_MAXB = 'MAXB';
    public const FIELD_MULTV = 'MULTV';
    public const FIELD_RAGX = 'RAGX';
    public const FIELD_ASI_06 = 'ASI-06';
    public const FIELD_ASI_07 = 'ASI-07';
    public const FIELD_ASI_08 = 'ASI-08';
    public const FIELD_ASI_10 = 'ASI-10';
    public const FIELD_MAXB_03 = 'MAXB-03';
    public const FIELD_MULTV_03 = 'MULTV-03';
    public const FIELD_RAGX_02 = 'RAGX-02';
    public const FIELD_CODEX_ELEV26S_JUDGE = 'codex-elev26s-judge';
    public const FIELD_ATLAS_AOBG_SEMANTIC_RETRIEVAL = 'atlas.aobg.semantic_retrieval';
    public const FIELD_ATLAS_MEMORY_CONTEXTUAL_BLURB_ENABLED = 'atlas.memory.contextual_blurb_enabled';
    public const FIELD_ATLAS_MEMORY_FEEDBACK_RANKING_ENABLED_2 = 'atlas.memory.feedback_ranking_enabled';
    public const FIELD_ATLAS_MEMORY_FUSION_V2_ENABLED = 'atlas.memory.fusion_v2_enabled';
    public const FIELD_ACOS_LAND_AUTONOMOUS_VERIFICATION_REQUIRED = 'acos.land.autonomous_verification_required';
    public const FIELD_ACOS_MUTATION_SCORE_ENFORCE_BY_EXECUTOR = 'acos.mutation_score.enforce_by_executor';
    public const FIELD_ATLAS_AI_AUTONOMOUS_LEARNING_ENABLED = 'atlas.ai.autonomous_learning.enabled';
    public const FIELD_ATLAS_BRAIN_REFLECTION_ENABLED_2 = 'atlas.brain.reflection_enabled';
    public const FLOAT_0_05 = 0.05;

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
        self::FIELD_SHADOW_MINIMUM_WINDOW,
        self::FIELD_FLIP_CRITERION,
        self::FIELD_ROLLBACK_TRIGGER,
        self::FIELD_JUDGE_ENGINE_ID,
        self::FIELD_RECEIPT,
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
            return $this->blocked(self::FIELD_UNKNOWN_FLAG, $flagId, $toState);
        }
        if (! in_array($toState, self::STATES, true)) {
            return $this->blocked(self::FIELD_INVALID_STATE, $flagId, $toState, [self::FIELD_ALLOWED_STATES => self::STATES]);
        }

        $required = $this->requiredFields($entry);
        if ($required[self::FIELD_OK] !== true) {
            $missing = AiValueNormalizer::arrayOrEmpty($required[self::FIELD_MISSING] ?? null);

            return $this->blocked(
                in_array('rollback_trigger', $missing, true)
                    ? self::FIELD_MISSING_PREDECLARED_ROLLBACK_TRIGGER
                    : self::FIELD_MISSING_REQUIRED_FIELDS,
                $flagId,
                $toState,
                [self::FIELD_MISSING => $missing],
            );
        }

        $windowId = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_OBSERVATION_WINDOW_ID] ?? null) ?? '';
        if ($windowId === '') {
            return $this->blocked(self::FIELD_MISSING_OBSERVATION_WINDOW_ID, $flagId, $toState);
        }

        $receipt = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_RECEIPT] ?? null) ?? '';
        if ($receipt === '') {
            return $this->blocked(self::FIELD_MISSING_FLIP_RECEIPT, $flagId, $toState);
        }

        $family = AiValueNormalizer::trimmedScalarStringOrNull($entry[self::FIELD_FAMILY] ?? null) ?? '';
        $action = $this->actionForState($toState);
        if ($action === self::FIELD_FLIP && $this->familyAlreadyFlippedInWindow($family, $windowId)) {
            return $this->blocked(self::FIELD_FAMILY_WINDOW_FLIP_ALREADY_RECORDED, $flagId, $toState, [
                self::FIELD_FAMILY => $family,
                self::FIELD_OBSERVATION_WINDOW_ID => $windowId,
            ]);
        }

        $fromState = $this->stateForFlag($flagId);
        $event = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_EVENT_ID => hash(self::FIELD_SHA256, implode('|', [
                $flagId,
                $fromState,
                $toState,
                $windowId,
                (string) microtime(true),
            ])),
            self::FIELD_RECORDED_AT => date('c'),
            self::FIELD_ACTION => $action,
            self::FIELD_FLAG_ID => $flagId,
            self::FIELD_FAMILY => $family,
            self::FIELD_SLICE => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SLICE] ?? null) ?? ''),
            self::FIELD_FROM_STATE => $fromState,
            self::FIELD_TO_STATE => $toState,
            self::FIELD_OBSERVATION_WINDOW_ID => $windowId,
            self::FIELD_ACTOR => AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_ACTOR] ?? null) ?? self::FIELD_ATLAS,
            self::FIELD_REASON => AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_REASON] ?? null) ?? '',
            self::FIELD_RECEIPT => $receipt,
            self::FIELD_ROLLBACK_TRIGGER => AiValueNormalizer::trimmedScalarStringOrNull($entry[self::FIELD_ROLLBACK_TRIGGER] ?? null) ?? '',
            self::FIELD_JUDGE_ENGINE_ID => AiValueNormalizer::trimmedScalarStringOrNull($entry[self::FIELD_JUDGE_ENGINE_ID] ?? null) ?? '',
            self::FIELD_PROTOCOL_RECEIPT => AiValueNormalizer::trimmedScalarStringOrNull($entry[self::FIELD_RECEIPT] ?? null) ?? '',
        ];

        $challenger = $this->observeChallenger($context);
        if ($challenger !== null) {
            // Observe-only ESP-09 advisory — never vetoes the flip decision.
            $event[self::FIELD_CHALLENGER_ADVISORY] = $challenger;
        }

        $this->ledger->append($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            self::FIELD_OK => true,
            self::FIELD_STATUS => self::STATUS_RECORDED,
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_EVENT => $event,
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
            fn (array $entry): string => AiValueNormalizer::trimmedScalarStringOrNull($entry[self::FIELD_ID] ?? null) ?? '',
            $managed,
        ), true);
        $legacy = array_values(array_filter(
            array_map(fn (array $entry): array => $this->legacyReportEntry($entry), $this->legacyFlags),
            fn (array $entry): bool => ! isset($managedIds[AiValueNormalizer::trimmedScalarStringOrNull($entry[self::FIELD_ID] ?? null) ?? '']),
        ));

        return [
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_STATUS => self::STATUS_OK,
            self::FIELD_PROTOCOL_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_STATES => self::STATES,
            self::FIELD_LEDGER_PATH => $this->ledger->path(),
            self::FIELD_MANAGED_FLAGS_COUNT => count($managed),
            self::FIELD_LEGACY_UNMANAGED_FLAGS_COUNT => count($legacy),
            self::FIELD_FLAGS => array_values(array_merge($managed, $legacy)),
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
                self::FIELD_ID => self::FIELD_ATLAS_AUTONOMOS_MASTER_ENABLED,
                self::FIELD_FAMILY => self::FIELD_ASI,
                self::FIELD_SLICE => self::FIELD_ASI_06,
                self::FIELD_STATE => self::STATE_OFF,
                self::FIELD_ENV_KEY => self::FIELD_ATLAS_AUTONOMOS_MASTER_ENABLED,
                self::FIELD_SHADOW_MINIMUM_WINDOW => self::FIELD_OPERATOR_PREFLIGHT_WINDOW,
                self::FIELD_FLIP_CRITERION => 'atlas:autonomos:preflight --json returns 8/8 green with ASI-01/02/05 evidence',
                self::FIELD_ROLLBACK_TRIGGER => 'operator disables autonomos master on failed preflight regression or scoped-committer violation',
                self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_ELEV26S_JUDGE,
                self::FIELD_RECEIPT => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:1412',
                self::FIELD_OPERATOR_ONLY => true,
            ],
            [
                self::FIELD_ID => self::FIELD_ATLAS_AUTONOMOUS_AUTO_APPLY,
                self::FIELD_FAMILY => self::FIELD_ASI,
                self::FIELD_SLICE => self::FIELD_ASI_07,
                self::FIELD_STATE => self::STATE_OFF,
                self::FIELD_CONFIG_KEY => self::FIELD_ATLAS_AI_AUTONOMOUS_LEARNING_ENABLED,
                self::FIELD_ENV_KEY => self::FIELD_ATLAS_AUTONOMOUS_AUTO_APPLY,
                self::FIELD_SHADOW_MINIMUM_WINDOW => '7d',
                self::FIELD_FLIP_CRITERION => 'privacy fail-closed, reversal proven, and digest FEE-12 operational',
                self::FIELD_ROLLBACK_TRIGGER => 'disable auto-apply when reversal_rate or negative_feedback guard breaches soak bounds',
                self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_ELEV26S_JUDGE,
                self::FIELD_RECEIPT => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:1413',
                self::FIELD_OPERATOR_ONLY => true,
            ],
            [
                self::FIELD_ID => self::FIELD_ATLAS_BRAIN_REFLECTION_ENABLED,
                self::FIELD_FAMILY => self::FIELD_ASI,
                self::FIELD_SLICE => self::FIELD_ASI_08,
                self::FIELD_STATE => self::STATE_OFF,
                self::FIELD_CONFIG_KEY => self::FIELD_ATLAS_BRAIN_REFLECTION_ENABLED_2,
                self::FIELD_ENV_KEY => self::FIELD_ATLAS_BRAIN_REFLECTION_ENABLED,
                self::FIELD_SHADOW_MINIMUM_WINDOW => '24h',
                self::FIELD_FLIP_CRITERION => 'reflection stream writes real post-landing entries and consumer reads PathYieldEwma samples',
                self::FIELD_ROLLBACK_TRIGGER => 'disable reflection writer if pattern-ledger writes fail or no consumer traffic is observed',
                self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_ELEV26S_JUDGE,
                self::FIELD_RECEIPT => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:1311',
            ],
            [
                self::FIELD_ID => self::FIELD_ATLAS_MEMORY_FUSION_V2_ENABLED,
                self::FIELD_FAMILY => self::FIELD_MAXB,
                self::FIELD_SLICE => self::FIELD_MAXB_03,
                self::FIELD_STATE => self::STATE_OFF,
                self::FIELD_CONFIG_KEY => self::FIELD_ATLAS_MEMORY_FUSION_V2_ENABLED,
                self::FIELD_SHADOW_MINIMUM_WINDOW => '7d',
                self::FIELD_FLIP_CRITERION => 'golden v2 shows RRF cross-source precision improvement with OFF byte-identical',
                self::FIELD_ROLLBACK_TRIGGER => 'return to legacy ranking formula on golden v2 regression or improper floor discard',
                self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_ELEV26S_JUDGE,
                self::FIELD_RECEIPT => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:330',
            ],
            [
                self::FIELD_ID => self::FIELD_ACOS_LAND_AUTONOMOUS_VERIFICATION_REQUIRED,
                self::FIELD_FAMILY => self::FIELD_ASI,
                self::FIELD_SLICE => self::FIELD_ASI_10,
                self::FIELD_STATE => self::STATE_OFF,
                self::FIELD_SHADOW_MINIMUM_WINDOW => '7d',
                self::FIELD_FLIP_CRITERION => 'MULTV-01/02 receipts cover derived tier and verified_share floor is green',
                self::FIELD_ROLLBACK_TRIGGER => 'disable autonomous land enforcement on false block or receipt-seal regression',
                self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_ELEV26S_JUDGE,
                self::FIELD_RECEIPT => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:2681',
            ],
            [
                self::FIELD_ID => self::FIELD_ACOS_MUTATION_SCORE_ENFORCE_BY_EXECUTOR,
                self::FIELD_FAMILY => self::FIELD_MULTV,
                self::FIELD_SLICE => self::FIELD_MULTV_03,
                self::FIELD_STATE => self::STATE_OFF,
                self::FIELD_SHADOW_MINIMUM_WINDOW => '8 samples per executor',
                self::FIELD_FLIP_CRITERION => 'mutation MSI low advisory correlates with later real failure for the executor',
                self::FIELD_ROLLBACK_TRIGGER => 'suspend executor family on negative root A/B or cost breach',
                self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_ELEV26S_JUDGE,
                self::FIELD_RECEIPT => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:2639',
            ],
            [
                self::FIELD_ID => self::FIELD_ATLAS_MEMORY_CONTEXTUAL_BLURB_ENABLED,
                self::FIELD_FAMILY => self::FIELD_RAGX,
                self::FIELD_SLICE => self::FIELD_RAGX_02,
                self::FIELD_STATE => self::STATE_OFF,
                self::FIELD_CONFIG_KEY => self::FIELD_ATLAS_MEMORY_CONTEXTUAL_BLURB_ENABLED,
                self::FIELD_SHADOW_MINIMUM_WINDOW => '20 judged queries',
                self::FIELD_FLIP_CRITERION => 'code/KB R8 precision@5 improves by >=self::FLOAT_0_05 with latency reported',
                self::FIELD_ROLLBACK_TRIGGER => 'disable contextual blurbs on precision regression or hallucinated-blurb sample failure',
                self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_ELEV26S_JUDGE,
                self::FIELD_RECEIPT => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:2022',
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
                self::FIELD_ID => self::FIELD_ATLAS_MEMORY_FEEDBACK_RANKING_ENABLED_2,
                self::FIELD_FAMILY => self::FIELD_FEE,
                self::FIELD_CONFIG_KEY => self::FIELD_ATLAS_MEMORY_FEEDBACK_RANKING_ENABLED_2,
                self::FIELD_ENV_KEY => self::FIELD_ATLAS_MEMORY_FEEDBACK_RANKING_ENABLED,
                self::FIELD_SOURCE => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:225',
            ],
            [
                self::FIELD_ID => self::FIELD_ATLAS_AOBG_SEMANTIC_RETRIEVAL,
                self::FIELD_FAMILY => self::FIELD_AOBG,
                self::FIELD_CONFIG_KEY => self::FIELD_ATLAS_AOBG_SEMANTIC_RETRIEVAL,
                self::FIELD_SOURCE => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:252',
            ],
            [
                self::FIELD_ID => self::FIELD_ATLAS_AOBG_FUSION_ENABLED,
                self::FIELD_FAMILY => self::FIELD_AOBG,
                self::FIELD_ENV_KEY => self::FIELD_ATLAS_AOBG_FUSION_ENABLED,
                self::FIELD_SOURCE => 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:743',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function entryById(string $flagId): ?array
    {
        foreach ($this->entries as $entry) {
            if ((AiValueNormalizer::trimmedScalarStringOrNull($entry[self::FIELD_ID] ?? null) ?? '') === $flagId) {
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
        $state = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_STATE] ?? null) ?? self::STATE_OFF;
        if (! in_array($state, self::STATES, true)) {
            $state = self::STATE_OFF;
        }

        return array_merge($entry, [
            self::FIELD_ID => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_ID] ?? null) ?? '',
            self::FIELD_FAMILY => strtoupper(AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_FAMILY] ?? null) ?? ''),
            self::FIELD_SLICE => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SLICE] ?? null) ?? '',
            self::FIELD_STATE => $state,
            self::FIELD_STATUS => self::STATUS_MANAGED,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeLegacyEntry(array|string $entry): array
    {
        $payload = is_array($entry) ? $entry : [self::FIELD_ID => $entry];

        return array_merge($payload, [
            self::FIELD_ID => AiValueNormalizer::trimmedStringOrNull($payload[self::FIELD_ID] ?? null) ?? '',
            self::FIELD_FAMILY => strtoupper(AiValueNormalizer::trimmedStringOrNull($payload[self::FIELD_FAMILY] ?? null) ?? self::FIELD_LEGACY),
            self::FIELD_STATUS => self::STATUS_LEGACY_UNMANAGED,
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
        $author = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_AUTHOR_ENGINE_ID] ?? null) ?? '';
        $challenger = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_CHALLENGER_ENGINE_ID] ?? null) ?? '';
        if ($author === '' || $challenger === '') {
            return null;
        }

        return Esp09IndependentChallengerService::evaluate([
            self::FIELD_AUTHOR_ENGINE_ID => $author,
            self::FIELD_CHALLENGER_ENGINE_ID => $challenger,
            self::FIELD_OPERATOR_ALIGNMENT => $context[self::FIELD_OPERATOR_ALIGNMENT] ?? null,
            self::FIELD_DECISION_KIND => $context[self::FIELD_DECISION_KIND] ?? self::FIELD_ORDINARY_ROUTE,
        ]);
    }

    private function stateForFlag(string $flagId): string
    {
        $state = null;
        foreach ($this->ledgerEvents() as $event) {
            if ((AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_FLAG_ID] ?? null) ?? '') !== $flagId) {
                continue;
            }
            $candidate = (AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_TO_STATE] ?? null) ?? '');
            if (in_array($candidate, self::STATES, true)) {
                $state = $candidate;
            }
        }
        if ($state !== null) {
            return $state;
        }

        return AiValueNormalizer::trimmedStringOrNull($this->entryById($flagId)[self::FIELD_STATE] ?? null) ?? self::STATE_OFF;
    }

    private function familyAlreadyFlippedInWindow(string $family, string $windowId): bool
    {
        foreach ($this->ledgerEvents() as $event) {
            if (($event[self::FIELD_ACTION] ?? null) !== self::FIELD_FLIP) {
                continue;
            }
            if ((AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_FAMILY] ?? null) ?? '') === $family
                && (AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_OBSERVATION_WINDOW_ID] ?? null) ?? '') === $windowId) {
                return true;
            }
        }

        return false;
    }

    private function actionForState(string $state): string
    {
        return match ($state) {
            self::STATE_ROLLED_BACK => self::FIELD_ROLLBACK,
            self::STATE_SUSPENDED_PENDING_EVIDENCE => self::FIELD_SUSPEND,
            default => self::FIELD_FLIP,
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
            self::FIELD_STATUS => self::STATUS_BLOCKED,
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_REASON => $reason,
            self::FIELD_FLAG_ID => $flagId,
            self::FIELD_TO_STATE => $toState,
        ], $extra);
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function managedReportEntry(array $entry, array $events): array
    {
        $id = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_ID] ?? null) ?? '';
        $last = null;
        foreach ($events as $event) {
            if ((AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_FLAG_ID] ?? null) ?? '') === $id) {
                $last = $event;
            }
        }

        return [
            self::FIELD_ID => $id,
            self::FIELD_STATUS => self::STATUS_MANAGED,
            self::FIELD_FAMILY => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_FAMILY] ?? null) ?? '',
            self::FIELD_SLICE => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SLICE] ?? null) ?? '',
            self::FIELD_STATE => $last !== null
                ? (AiValueNormalizer::trimmedStringOrNull($last[self::FIELD_TO_STATE] ?? null) ?? (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_STATE] ?? null) ?? ''))
                : (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_STATE] ?? null) ?? ''),
            self::FIELD_CONFIG_KEY => $entry[self::FIELD_CONFIG_KEY] ?? null,
            self::FIELD_ENV_KEY => $entry[self::FIELD_ENV_KEY] ?? null,
            self::FIELD_OPERATOR_ONLY => (AiValueNormalizer::boolOrNull($entry[self::FIELD_OPERATOR_ONLY] ?? null) ?? false),
            self::FIELD_REQUIRED_FIELDS => $this->requiredFields($entry),
            self::FIELD_SHADOW_MINIMUM_WINDOW => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SHADOW_MINIMUM_WINDOW] ?? null) ?? '',
            self::FIELD_FLIP_CRITERION => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_FLIP_CRITERION] ?? null) ?? '',
            self::FIELD_ROLLBACK_TRIGGER => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_ROLLBACK_TRIGGER] ?? null) ?? '',
            self::FIELD_JUDGE_ENGINE_ID => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_JUDGE_ENGINE_ID] ?? null) ?? '',
            self::FIELD_RECEIPT => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_RECEIPT] ?? null) ?? '',
            self::FIELD_LAST_FLIP => $last,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function legacyReportEntry(array $entry): array
    {
        return [
            self::FIELD_ID => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_ID] ?? null) ?? '',
            self::FIELD_STATUS => self::STATUS_LEGACY_UNMANAGED,
            self::FIELD_FAMILY => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_FAMILY] ?? null) ?? self::FIELD_LEGACY,
            self::FIELD_STATE => self::STATE_LEGACY_UNMANAGED,
            self::FIELD_CONFIG_KEY => $entry[self::FIELD_CONFIG_KEY] ?? null,
            self::FIELD_ENV_KEY => $entry[self::FIELD_ENV_KEY] ?? null,
            self::FIELD_SOURCE => $entry[self::FIELD_SOURCE] ?? null,
            self::FIELD_MIGRATION_POLICY => self::FIELD_MIGRATE_ON_NEXT_TOUCH,
        ];
    }
}
