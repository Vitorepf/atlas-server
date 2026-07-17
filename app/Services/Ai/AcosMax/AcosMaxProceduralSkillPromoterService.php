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

    public function __construct(
        private readonly ?AtlasProceduralPlaybookLedger $ledger = null,
    ) {}

    /** @return array<string,mixed> */
    public function report(?int $floor = null, bool $enqueue = false): array
    {
        $freeze = AcosMaxLote2MeasureService::freezePayload('MULTJ-04');
        $effectiveFloor = max(1, (int) ($floor ?? data_get($freeze, 'thresholds.procedural_case_count_floor', 8)));
        $ledger = $this->ledger ?? new AtlasProceduralPlaybookLedger;
        $cadence = $ledger->cadence();
        $enqueueRequested = $enqueue && $this->enqueueEnabled();

        $candidates = [];
        foreach ($cadence as $row) {
            $playbook = $ledger->retrieve(AiValueNormalizer::trimmedString($row['task_category'] ?? ''));
            if ($playbook === null) {
                continue;
            }

            $candidates[] = $this->candidatePayload($playbook, $row, $effectiveFloor);
        }

        $eligible = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => (bool) ($candidate['case_count_floor_met'] ?? false),
        ));

        $enqueued = [];
        if ($enqueueRequested && DatabaseTableAvailability::has('ai_learning_candidates')) {
            foreach ($eligible as $candidate) {
                $enqueued[] = $this->enqueueCandidate($candidate);
            }
        }

        $status = $eligible === [] ? 'pending_window' : 'ok';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'slice' => 'MULTJ-04',
            'status' => $status,
            'reason' => $status === 'ok' ? null : 'procedural_case_count_soak',
            'generated_at' => Carbon::now()->toIso8601String(),
            'freeze' => $freeze,
            'measure_id' => self::measureId(),
            'skill_schema_version' => self::SKILL_SCHEMA_VERSION,
            'case_count_floor' => $effectiveFloor,
            'promotion_allowed' => false,
            'scoreboard' => [
                'landed' => ['mechanism'],
                'pending_window' => $status === 'pending_window' ? ['procedural_case_count_soak'] : [],
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
                'admission_door' => 'ASI-02',
                'promotion_allowed' => false,
                'status' => 'hold',
                'reason' => $status === 'ok' ? 'awaiting_asi02_admission' : 'procedural_case_count_soak',
            ],
            'claim_policy' => [
                'default_off' => true,
                'read_only' => ! $enqueueRequested,
                'enqueue_enabled' => $this->enqueueEnabled(),
                'enqueue_requested' => $enqueue,
                'enqueue_effective' => $enqueueRequested,
                'queue' => 'ai_learning_candidates',
                'admission_door' => 'ASI-02',
                'promotion_allowed' => false,
                'auto_promotion_allowed' => false,
                'skill_files_written' => false,
                'provider_calls_made' => false,
            ],
        ];
    }

    public static function measureId(): string
    {
        return 'atlas.ai.procedural_skill_promoter.v1';
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function candidatePayload(ProceduralPlaybook $playbook, array $row, int $floor): array
    {
        $caseCount = max(0, (int) ($row['attempts'] ?? 0));
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
            'successes' => (int) ($row['successes'] ?? 0),
            'success_rate' => (float) ($row['success_rate'] ?? 0.0),
            'fake_green_suppressed' => (int) ($row['fake_green_suppressed'] ?? 0),
            'corrections' => (int) ($row['corrections'] ?? 0),
            'promotion_allowed' => false,
            'gate' => [
                'admission_door' => 'ASI-02',
                'promotion_allowed' => false,
                'status' => $caseCount >= $floor ? 'hold_for_asi02' : 'pending_window',
                'reason' => $caseCount >= $floor ? 'awaiting_asi02_admission' : 'procedural_case_count_soak',
            ],
            'skill_v1' => [
                'schema_version' => self::SKILL_SCHEMA_VERSION,
                'name' => $skillName,
                'description' => $playbook->objective,
                'source' => [
                    'kind' => 'procedural_playbook',
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
        $candidateHash = AiValueNormalizer::trimmedString($candidate['candidate_hash'] ?? '');
        $taskCategory = AiValueNormalizer::trimmedString($candidate['task_category'] ?? '');
        $evidenceRefs = [
            'procedural_playbook:'.hash('sha256', $taskCategory),
            'multj04:case_count:'.AiValueNormalizer::trimmedString($candidate['case_count'] ?? 0),
        ];

        $row = AiLearningCandidate::query()->firstOrCreate(
            ['candidate_hash' => $candidateHash],
            [
                'schema_version' => AtlasLearningDistiller::SCHEMA_VERSION,
                'run_outcome_id' => null,
                'status' => 'held_for_evidence',
                'decision' => 'hold',
                'memory_type' => self::SKILL_SCHEMA_VERSION,
                'scope' => 'global',
                'claim' => sprintf(
                    'MULTJ-04 procedural-to-skill.v1 proposal for %s held under ASI-02 (case_count=%d).',
                    $taskCategory,
                    (int) $candidate['case_count'],
                ),
                'confidence' => 40,
                'promotion_allowed' => false,
                'evidence_refs' => $evidenceRefs,
                'payload' => [
                    'schema_version' => self::SCHEMA_VERSION,
                    'source' => [
                        'slice' => 'MULTJ-04',
                        'author_engine' => 'procedural_skill_promoter',
                        'frontier_promotes' => false,
                        'admission_door' => 'ASI-02',
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
            'candidate_id' => AiValueNormalizer::trimmedString($row->id),
            'candidate_hash' => $candidateHash,
            'skill_name' => AiValueNormalizer::trimmedString($candidate['skill_name'] ?? ''),
            'promotion_allowed' => false,
            'created' => $row->wasRecentlyCreated,
        ];
    }

    private function enqueueEnabled(): bool
    {
        return (bool) config('atlas.ai.procedural_skill_promoter.enqueue_enabled', false);
    }

    private function skillName(string $taskCategory): string
    {
        $slug = trim(AiValueNormalizer::lowerTrimmedString(preg_replace('/[^a-zA-Z0-9]+/', '-', AiValueNormalizer::trimmedString($taskCategory)) ?? ''), '-');
        if ($slug === '') {
            $slug = substr(hash('sha256', $taskCategory), 0, 12);
        }

        return substr('procedural-'.$slug, 0, 64);
    }
}
