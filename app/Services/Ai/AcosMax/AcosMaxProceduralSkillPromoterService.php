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

    public const STATUS_HOLD = 'hold';

    public const STATUS_HOLD_FOR_ASI02 = 'hold_for_asi02';

    public const REASON_PROCEDURAL_CASE_COUNT_SOAK = 'procedural_case_count_soak';

    public const REASON_AWAITING_ASI02_ADMISSION = 'awaiting_asi02_admission';

    public const ADMISSION_DOOR_ASI02 = 'ASI-02';

    public const QUEUE_AI_LEARNING_CANDIDATES = 'ai_learning_candidates';

    public const SCOREBOARD_LANDED_MECHANISM = 'mechanism';

    public const KIND_PROCEDURAL_PLAYBOOK = 'procedural_playbook';

    public const STATUS_HELD_FOR_EVIDENCE = 'held_for_evidence';


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
            $playbook = $ledger->retrieve(AiValueNormalizer::trimmedStringOrNull($row['task_category'] ?? null) ?? '');
            if ($playbook === null) {
                continue;
            }

            $candidates[] = $this->candidatePayload($playbook, $row, $effectiveFloor);
        }

        $eligible = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => (AiValueNormalizer::boolOrNull($candidate['case_count_floor_met'] ?? null) ?? false),
        ));

        $enqueued = [];
        if ($enqueueRequested && DatabaseTableAvailability::has(self::QUEUE_AI_LEARNING_CANDIDATES)) {
            foreach ($eligible as $candidate) {
                $enqueued[] = $this->enqueueCandidate($candidate);
            }
        }

        $status = $eligible === [] ? self::STATUS_PENDING_WINDOW : self::STATUS_OK;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'slice' => self::SLICE_MULTJ04,
            'status' => $status,
            'reason' => $status === self::STATUS_OK ? null : self::REASON_PROCEDURAL_CASE_COUNT_SOAK,
            'generated_at' => Carbon::now()->toIso8601String(),
            'freeze' => $freeze,
            'measure_id' => self::measureId(),
            'skill_schema_version' => self::SKILL_SCHEMA_VERSION,
            'case_count_floor' => $effectiveFloor,
            'promotion_allowed' => false,
            'scoreboard' => [
                'landed' => [self::SCOREBOARD_LANDED_MECHANISM],
                self::FIELD_PENDING_WINDOW => $status === self::STATUS_PENDING_WINDOW ? [self::REASON_PROCEDURAL_CASE_COUNT_SOAK] : [],
            ],
            'totals' => [
                'procedural_playbooks' => count($candidates),
                'floor_met' => count($eligible),
                'floor_pending' => count($candidates) - count($eligible),
                'enqueued' => count($enqueued),
            ],
            'candidates' => $candidates,
            'enqueued' => $enqueued,
            'gate' => [
                'admission_door' => self::ADMISSION_DOOR_ASI02,
                'promotion_allowed' => false,
                'status' => self::STATUS_HOLD,
                'reason' => $status === self::STATUS_OK ? self::REASON_AWAITING_ASI02_ADMISSION : self::REASON_PROCEDURAL_CASE_COUNT_SOAK,
            ],
            'claim_policy' => [
                'default_off' => true,
                'read_only' => ! $enqueueRequested,
                'enqueue_enabled' => $this->enqueueEnabled(),
                'enqueue_requested' => $enqueue,
                'enqueue_effective' => $enqueueRequested,
                'queue' => self::QUEUE_AI_LEARNING_CANDIDATES,
                'admission_door' => self::ADMISSION_DOOR_ASI02,
                'promotion_allowed' => false,
                'auto_promotion_allowed' => false,
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
        $caseCount = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($row['attempts'] ?? null) ?? 0));
        $skillName = $this->skillName($playbook->taskCategory);
        $candidateHash = hash('sha256', self::SCHEMA_VERSION.'|'.$playbook->key().'|'.self::SKILL_SCHEMA_VERSION);

        return [
            'candidate_hash' => $candidateHash,
            'task_category' => $playbook->taskCategory,
            'skill_name' => $skillName,
            'skill_schema_version' => self::SKILL_SCHEMA_VERSION,
            'case_count' => $caseCount,
            'case_count_floor' => $floor,
            'case_count_floor_met' => $caseCount >= $floor,
            'successes' => (int) (AiValueNormalizer::finiteFloatOrNull($row['successes'] ?? null) ?? 0),
            'success_rate' => AiValueNormalizer::finiteFloatOrNull($row['success_rate'] ?? null) ?? 0.0,
            'fake_green_suppressed' => (int) (AiValueNormalizer::finiteFloatOrNull($row['fake_green_suppressed'] ?? null) ?? 0),
            'corrections' => (int) (AiValueNormalizer::finiteFloatOrNull($row['corrections'] ?? null) ?? 0),
            'promotion_allowed' => false,
            'gate' => [
                'admission_door' => self::ADMISSION_DOOR_ASI02,
                'promotion_allowed' => false,
                'status' => $caseCount >= $floor ? self::STATUS_HOLD_FOR_ASI02 : self::STATUS_PENDING_WINDOW,
                'reason' => $caseCount >= $floor ? self::REASON_AWAITING_ASI02_ADMISSION : self::REASON_PROCEDURAL_CASE_COUNT_SOAK,
            ],
            'skill_v1' => [
                'schema_version' => self::SKILL_SCHEMA_VERSION,
                'name' => $skillName,
                'description' => $playbook->objective,
                'source' => [
                    'kind' => self::KIND_PROCEDURAL_PLAYBOOK,
                    'task_category' => $playbook->taskCategory,
                    'case_count' => $caseCount,
                ],
                'body' => [
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
        $candidateHash = AiValueNormalizer::trimmedStringOrNull($candidate['candidate_hash'] ?? null) ?? '';
        $taskCategory = AiValueNormalizer::trimmedStringOrNull($candidate['task_category'] ?? null) ?? '';
        $evidenceRefs = [
            'procedural_playbook:'.hash('sha256', $taskCategory),
            'multj04:case_count:'.(AiValueNormalizer::trimmedStringOrNull($candidate['case_count'] ?? null) ?? (AiValueNormalizer::trimmedStringOrNull($candidate['case_count'] ?? null) ?? 0)),
        ];

        $row = AiLearningCandidate::query()->firstOrCreate(
            ['candidate_hash' => $candidateHash],
            [
                'schema_version' => AtlasLearningDistiller::SCHEMA_VERSION,
                'run_outcome_id' => null,
                'status' => self::STATUS_HELD_FOR_EVIDENCE,
                'decision' => self::STATUS_HOLD,
                'memory_type' => self::SKILL_SCHEMA_VERSION,
                'scope' => 'global',
                'claim' => sprintf(
                    'MULTJ-04 procedural-to-skill.v1 proposal for %s held under ASI-02 (case_count=%d).',
                    $taskCategory,
                    (int) (AiValueNormalizer::finiteFloatOrNull($candidate['case_count'] ?? null) ?? 0),
                ),
                'confidence' => 40,
                'promotion_allowed' => false,
                'evidence_refs' => $evidenceRefs,
                'payload' => [
                    'schema_version' => self::SCHEMA_VERSION,
                    'source' => [
                        'slice' => self::SLICE_MULTJ04,
                        'author_engine' => 'procedural_skill_promoter',
                        'frontier_promotes' => false,
                        'admission_door' => self::ADMISSION_DOOR_ASI02,
                    ],
                    'case_count_floor' => $candidate['case_count_floor'],
                    'case_count' => $candidate['case_count'],
                    'promotion_allowed' => false,
                    'skill_v1' => $candidate['skill_v1'],
                ],
                'receipt_hash' => hash('sha256', 'multj04-'.$candidateHash),
                'decided_at' => Carbon::now(),
            ],
        );

        return [
            'candidate_id' => AiValueNormalizer::trimmedStringOrNull($row->id) ?? '',
            'candidate_hash' => $candidateHash,
            'skill_name' => AiValueNormalizer::trimmedStringOrNull($candidate['skill_name'] ?? null) ?? '',
            'promotion_allowed' => false,
            'created' => $row->wasRecentlyCreated,
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
