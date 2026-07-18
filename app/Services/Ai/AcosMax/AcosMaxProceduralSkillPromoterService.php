<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Models\AiLearningCandidate;
use App\Services\Ai\Compounding\AtlasLearningDistiller;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookLedger;
use App\Services\Ai\Kernel\Procedural\ProceduralPlaybook;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;

final class AcosMaxProceduralSkillPromoterService
{
    public const SCHEMA_VERSION = 'atlas.ai.procedural_skill_promoter.v1';

    public const SKILL_SCHEMA_VERSION = 'skill.v1';

    public const DEFAULT_CASE_COUNT_FLOOR = 8;

    public const ENQUEUE_ENABLED_CONFIG_KEY = 'atlas.ai.procedural_skill_promoter.enqueue_enabled';

    public const DEFAULT_ENQUEUE_ENABLED = false;

    public const SLICE_MULTJ04 = 'MULTJ-04';

    public const STATUS_OK = 'ok';

    public const STATUS_PENDING_WINDOW = 'pending_window';

    public const FIELD_PENDING_WINDOW = 'pending_window';

    public const FIELD_PROMOTION_ALLOWED = 'promotion_allowed';
    public const FIELD_CASE_COUNT = 'case_count';
    public const FIELD_TASK_CATEGORY = 'task_category';
    public const FIELD_STATUS = 'status';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_CASE_COUNT_FLOOR = 'case_count_floor';
    public const FIELD_CANDIDATE_HASH = 'candidate_hash';
    public const FIELD_ADMISSION_DOOR = 'admission_door';
    public const FIELD_REASON = 'reason';
    public const FIELD_SLICE = 'slice';
    public const FIELD_SKILL_SCHEMA_VERSION = 'skill_schema_version';
    public const FIELD_ENQUEUED = 'enqueued';
    public const FIELD_GATE = 'gate';
    public const FIELD_SKILL_NAME = 'skill_name';
    public const FIELD_SKILL_V1 = 'skill_v1';
    public const FIELD_SOURCE = 'source';

    public const STATUS_HOLD = 'hold';

    public const STATUS_HOLD_FOR_ASI02 = 'hold_for_asi02';

    public const REASON_PROCEDURAL_CASE_COUNT_SOAK = 'procedural_case_count_soak';

    public const REASON_AWAITING_ASI02_ADMISSION = 'awaiting_asi02_admission';

    public const ADMISSION_DOOR_ASI02 = 'ASI-02';

    public const QUEUE_AI_LEARNING_CANDIDATES = 'ai_learning_candidates';

    public const SCOREBOARD_LANDED_MECHANISM = 'mechanism';

    public const KIND_PROCEDURAL_PLAYBOOK = 'procedural_playbook';

    public const STATUS_HELD_FOR_EVIDENCE = 'held_for_evidence';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_FREEZE = 'freeze';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_SCOREBOARD = 'scoreboard';
    public const FIELD_LANDED = 'landed';
    public const FIELD_TOTALS = 'totals';
    public const FIELD_PROCEDURAL_PLAYBOOKS = 'procedural_playbooks';
    public const FIELD_FLOOR_MET = 'floor_met';
    public const FIELD_ATTEMPTS = 'attempts';
    public const FIELD_AUTHOR_ENGINE = 'author_engine';
    public const FIELD_AUTO_PROMOTION_ALLOWED = 'auto_promotion_allowed';
    public const FIELD_BODY = 'body';
    public const FIELD_CANDIDATE_ID = 'candidate_id';
    public const FIELD_CANDIDATES = 'candidates';
    public const FIELD_CASE_COUNT_FLOOR_MET = 'case_count_floor_met';
    public const FIELD_CLAIM = 'claim';
    public const FIELD_CLAIM_POLICY = 'claim_policy';
    public const FIELD_CONFIDENCE = 'confidence';
    public const FIELD_CORRECTIONS = 'corrections';
    public const FIELD_CREATED = 'created';


    public function __construct(
        private readonly ?AtlasProceduralPlaybookLedger $ledger = null,
    ) {}

    /** @return array<string,mixed> */
    public function report(?int $floor = null, bool $enqueue = false): array
    {
        $freeze = AcosMaxLote2MeasureService::freezePayload(self::SLICE_MULTJ04);
        $effectiveFloor = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($floor) ?? data_get($freeze, 'thresholds.procedural_case_count_floor', self::DEFAULT_CASE_COUNT_FLOOR)));
        $ledger = $this->ledger ?? new AtlasProceduralPlaybookLedger;
        $cadence = $ledger->cadence();
        $enqueueRequested = $enqueue && $this->enqueueEnabled();

        $candidates = [];
        foreach ($cadence as $row) {
            $playbook = $ledger->retrieve(AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_TASK_CATEGORY] ?? null) ?? '');
            if ($playbook === null) {
                continue;
            }

            $candidates[] = $this->candidatePayload($playbook, $row, $effectiveFloor);
        }

        $eligible = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => (AiValueNormalizer::boolOrNull($candidate[self::FIELD_CASE_COUNT_FLOOR_MET] ?? null) ?? false),
        ));

        $enqueued = [];
        if ($enqueueRequested && DatabaseTableAvailability::has(self::QUEUE_AI_LEARNING_CANDIDATES)) {
            foreach ($eligible as $candidate) {
                $enqueued[] = $this->enqueueCandidate($candidate);
            }
        }

        $status = $eligible === [] ? self::STATUS_PENDING_WINDOW : self::STATUS_OK;

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_SLICE => self::SLICE_MULTJ04,
            self::FIELD_STATUS => $status,
            self::FIELD_REASON => $status === self::STATUS_OK ? null : self::REASON_PROCEDURAL_CASE_COUNT_SOAK,
            self::FIELD_GENERATED_AT => Carbon::now()->toIso8601String(),
            self::FIELD_FREEZE => $freeze,
            self::FIELD_MEASURE_ID => self::measureId(),
            self::FIELD_SKILL_SCHEMA_VERSION => self::SKILL_SCHEMA_VERSION,
            self::FIELD_CASE_COUNT_FLOOR => $effectiveFloor,
            self::FIELD_PROMOTION_ALLOWED => false,
            self::FIELD_SCOREBOARD => [
                self::FIELD_LANDED => [self::SCOREBOARD_LANDED_MECHANISM],
                self::FIELD_PENDING_WINDOW => $status === self::STATUS_PENDING_WINDOW ? [self::REASON_PROCEDURAL_CASE_COUNT_SOAK] : [],
            ],
            self::FIELD_TOTALS => [
                self::FIELD_PROCEDURAL_PLAYBOOKS => count($candidates),
                self::FIELD_FLOOR_MET => count($eligible),
                'floor_pending' => count($candidates) - count($eligible),
                self::FIELD_ENQUEUED => count($enqueued),
            ],
            self::FIELD_CANDIDATES => $candidates,
            self::FIELD_ENQUEUED => $enqueued,
            self::FIELD_GATE => [
                self::FIELD_ADMISSION_DOOR => self::ADMISSION_DOOR_ASI02,
                self::FIELD_PROMOTION_ALLOWED => false,
                self::FIELD_STATUS => self::STATUS_HOLD,
                self::FIELD_REASON => $status === self::STATUS_OK ? self::REASON_AWAITING_ASI02_ADMISSION : self::REASON_PROCEDURAL_CASE_COUNT_SOAK,
            ],
            self::FIELD_CLAIM_POLICY => [
                'default_off' => true,
                'read_only' => ! $enqueueRequested,
                'enqueue_enabled' => $this->enqueueEnabled(),
                'enqueue_requested' => $enqueue,
                'enqueue_effective' => $enqueueRequested,
                'queue' => self::QUEUE_AI_LEARNING_CANDIDATES,
                self::FIELD_ADMISSION_DOOR => self::ADMISSION_DOOR_ASI02,
                self::FIELD_PROMOTION_ALLOWED => false,
                self::FIELD_AUTO_PROMOTION_ALLOWED => false,
                'skill_files_written' => false,
                'provider_calls_made' => false,
            ],
        ];
    }

    public static function measureId(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function candidatePayload(ProceduralPlaybook $playbook, array $row, int $floor): array
    {
        $caseCount = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_ATTEMPTS] ?? null) ?? 0));
        $skillName = $this->skillName($playbook->taskCategory);
        $candidateHash = hash('sha256', self::SCHEMA_VERSION.'|'.$playbook->key().'|'.self::SKILL_SCHEMA_VERSION);

        return [
            self::FIELD_CANDIDATE_HASH => $candidateHash,
            self::FIELD_TASK_CATEGORY => $playbook->taskCategory,
            self::FIELD_SKILL_NAME => $skillName,
            self::FIELD_SKILL_SCHEMA_VERSION => self::SKILL_SCHEMA_VERSION,
            self::FIELD_CASE_COUNT => $caseCount,
            self::FIELD_CASE_COUNT_FLOOR => $floor,
            self::FIELD_CASE_COUNT_FLOOR_MET => $caseCount >= $floor,
            'successes' => (int) (AiValueNormalizer::finiteFloatOrNull($row['successes'] ?? null) ?? 0),
            'success_rate' => AiValueNormalizer::finiteFloatOrNull($row['success_rate'] ?? null) ?? 0.0,
            'fake_green_suppressed' => (int) (AiValueNormalizer::finiteFloatOrNull($row['fake_green_suppressed'] ?? null) ?? 0),
            self::FIELD_CORRECTIONS => (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_CORRECTIONS] ?? null) ?? 0),
            self::FIELD_PROMOTION_ALLOWED => false,
            self::FIELD_GATE => [
                self::FIELD_ADMISSION_DOOR => self::ADMISSION_DOOR_ASI02,
                self::FIELD_PROMOTION_ALLOWED => false,
                self::FIELD_STATUS => $caseCount >= $floor ? self::STATUS_HOLD_FOR_ASI02 : self::STATUS_PENDING_WINDOW,
                self::FIELD_REASON => $caseCount >= $floor ? self::REASON_AWAITING_ASI02_ADMISSION : self::REASON_PROCEDURAL_CASE_COUNT_SOAK,
            ],
            self::FIELD_SKILL_V1 => [
                self::FIELD_SCHEMA_VERSION => self::SKILL_SCHEMA_VERSION,
                'name' => $skillName,
                'description' => $playbook->objective,
                self::FIELD_SOURCE => [
                    'kind' => self::KIND_PROCEDURAL_PLAYBOOK,
                    self::FIELD_TASK_CATEGORY => $playbook->taskCategory,
                    self::FIELD_CASE_COUNT => $caseCount,
                ],
                self::FIELD_BODY => [
                    'objective' => $playbook->objective,
                    'steps' => $playbook->steps,
                    'postconditions' => $playbook->postconditions,
                    'forbidden_actions' => $playbook->forbiddenActions,
                    'prior_corrections' => $playbook->priorCorrections,
                ],
            ],
        ];
    }

    /** @param  array<string,mixed>  $candidate */
    private function enqueueCandidate(array $candidate): array
    {
        $candidateHash = AiValueNormalizer::trimmedStringOrNull($candidate[self::FIELD_CANDIDATE_HASH] ?? null) ?? '';
        $taskCategory = AiValueNormalizer::trimmedStringOrNull($candidate[self::FIELD_TASK_CATEGORY] ?? null) ?? '';
        $evidenceRefs = [
            'procedural_playbook:'.hash('sha256', $taskCategory),
            'multj04:case_count:'.(AiValueNormalizer::trimmedStringOrNull($candidate[self::FIELD_CASE_COUNT] ?? null) ?? (AiValueNormalizer::trimmedStringOrNull($candidate[self::FIELD_CASE_COUNT] ?? null) ?? 0)),
        ];

        $row = AiLearningCandidate::query()->firstOrCreate(
            [self::FIELD_CANDIDATE_HASH => $candidateHash],
            [
                self::FIELD_SCHEMA_VERSION => AtlasLearningDistiller::SCHEMA_VERSION,
                'run_outcome_id' => null,
                self::FIELD_STATUS => self::STATUS_HELD_FOR_EVIDENCE,
                'decision' => self::STATUS_HOLD,
                'memory_type' => self::SKILL_SCHEMA_VERSION,
                'scope' => 'global',
                self::FIELD_CLAIM => sprintf(
                    'MULTJ-04 procedural-to-skill.v1 proposal for %s held under ASI-02 (case_count=%d).',
                    $taskCategory,
                    (int) (AiValueNormalizer::finiteFloatOrNull($candidate[self::FIELD_CASE_COUNT] ?? null) ?? 0),
                ),
                self::FIELD_CONFIDENCE => 40,
                self::FIELD_PROMOTION_ALLOWED => false,
                'evidence_refs' => $evidenceRefs,
                'payload' => [
                    self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                    self::FIELD_SOURCE => [
                        self::FIELD_SLICE => self::SLICE_MULTJ04,
                        self::FIELD_AUTHOR_ENGINE => 'procedural_skill_promoter',
                        'frontier_promotes' => false,
                        self::FIELD_ADMISSION_DOOR => self::ADMISSION_DOOR_ASI02,
                    ],
                    self::FIELD_CASE_COUNT_FLOOR => $candidate[self::FIELD_CASE_COUNT_FLOOR],
                    self::FIELD_CASE_COUNT => $candidate[self::FIELD_CASE_COUNT],
                    self::FIELD_PROMOTION_ALLOWED => false,
                    self::FIELD_SKILL_V1 => $candidate[self::FIELD_SKILL_V1],
                ],
                'receipt_hash' => hash('sha256', 'multj04-'.$candidateHash),
                'decided_at' => Carbon::now(),
            ],
        );

        return [
            self::FIELD_CANDIDATE_ID => AiValueNormalizer::trimmedStringOrNull($row->id) ?? '',
            self::FIELD_CANDIDATE_HASH => $candidateHash,
            self::FIELD_SKILL_NAME => AiValueNormalizer::trimmedStringOrNull($candidate[self::FIELD_SKILL_NAME] ?? null) ?? '',
            self::FIELD_PROMOTION_ALLOWED => false,
            self::FIELD_CREATED => $row->wasRecentlyCreated,
        ];
    }

    private function enqueueEnabled(): bool
    {
        return (AiValueNormalizer::boolOrNull(config(self::ENQUEUE_ENABLED_CONFIG_KEY, self::DEFAULT_ENQUEUE_ENABLED)) ?? self::DEFAULT_ENQUEUE_ENABLED);
    }

    private function skillName(string $taskCategory): string
    {
        $slug = trim(AiValueNormalizer::lowerTrimmedString(preg_replace('/[^a-zA-Z0-9]+/', '-', AiValueNormalizer::trimmedStringOrNull($taskCategory) ?? '') ?? ''), '-');
        if ($slug === '') {
            $slug = substr(hash('sha256', $taskCategory), 0, 12);
        }

        return substr('procedural-'.$slug, 0, 64);
    }
}
