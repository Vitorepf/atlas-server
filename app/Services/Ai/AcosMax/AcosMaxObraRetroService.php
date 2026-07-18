<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder;
use App\Services\Ai\AtlasOpenBrainWriteBackService;
use App\Services\Ai\Support\AiValueNormalizer;

final class AcosMaxObraRetroService
{
    public const SCHEMA_VERSION = 'atlas.acos_max.obra_retro.v1';

    public const SERIES_TAG = 'obra:acos-max';

    public const SCOREBOARD_RELATIVE_PATH = 'docs/engineering-knowledge-base/atlas-acos-max-execution-scoreboard-v1.md';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_UNKNOWN = 'unknown';

    public const FIELD_QUEUED = 'queued';
    public const FIELD_STATUS = 'status';
    public const FIELD_STATE = 'state';
    public const FIELD_KIND = 'kind';
    public const FIELD_EVIDENCE_REFS = 'evidence_refs';
    public const FIELD_SERIES_TAG = 'series_tag';
    public const FIELD_LOTE = 'lote';
    public const FIELD_REASON = 'reason';
    public const FIELD_LESSONS = 'lessons';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_OUTCOMES = 'outcomes';
    public const FIELD_LESSON_CANDIDATES = 'lesson_candidates';
    public const FIELD_WORKSPACE = 'workspace';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_SLICE_ID = 'slice_id';
    public const FIELD_SLICE_STATE = 'slice_state';
    public const FIELD_PATH = 'path';

    public const REASON_NO_TERMINAL_SLICES_FOR_LOTE = 'no_terminal_slices_for_lote';

    public const KIND_FAILURE_PATTERN = 'failure_pattern';

    public const LESSON_PATH_NORMAL_CAPTURE = 'normal_capture_quality_gate_and_asi_02';

    public const SOURCE_ACOS_MAX_OBRA_RETRO = 'acos_max_obra_retro';

    public const LESSON_STATUS_PENDING_REVIEW = 'pending_review';

    public const OUTCOME_STATUS_SUCCEEDED = 'succeeded';

    public const OUTCOME_STATUS_FAILED = 'failed';

    public const SLICE_STATE_LANDED = 'landed';

    public const SLICE_STATE_REFUTADO = 'refutado';

    public const SLICE_STATE_SUSPENDED = 'suspended';
    public const FIELD_ITEMS = 'items';
    public const FIELD_SCOREBOARD_PATH = 'scoreboard_path';
    public const FIELD_SLICES = 'slices';
    public const FIELD_TERMINAL = 'terminal';
    public const FIELD_IDS = 'ids';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_FLOW_ID = 'flow_id';
    public const FIELD_PRIVACY_CLASS = 'privacy_class';
    public const FIELD_ACTOR_TAG = 'actor_tag';
    public const FIELD_AI_RUN_OUTCOME_ID = 'ai_run_outcome_id';
    public const FIELD_AUTO_PROMOTED = 'auto_promoted';
    public const FIELD_CURRENT_STATE = 'current_state';
    public const FIELD_EXECUTOR = 'executor';
    public const FIELD_FUTURE_LOTE_CLOSE_REQUIRES = 'future_lote_close_requires';

    public function __construct(
        private readonly AtlasEngineeringOutcomeRecorder $outcomes,
        private readonly AtlasOpenBrainWriteBackService $writeBack,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function closeLote(int $lote): array
    {
        $lote = max(0, $lote);
        $slices = $this->terminalSlicesForLote($lote);

        if ($slices === []) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_BLOCKED,
                self::FIELD_REASON => self::REASON_NO_TERMINAL_SLICES_FOR_LOTE,
                self::FIELD_LOTE => $lote,
                self::FIELD_SERIES_TAG => self::SERIES_TAG,
                self::FIELD_OUTCOMES => [self::STATUS_RECORDED => 0, self::FIELD_ITEMS => []],
                self::FIELD_LESSON_CANDIDATES => [
                    self::FIELD_PATH => self::LESSON_PATH_NORMAL_CAPTURE,
                    self::FIELD_QUEUED => 0,
                    self::FIELD_ITEMS => [],
                ],
            ];
        }

        $outcomeItems = [];
        foreach ($slices as $slice) {
            $outcomeItems[] = $this->recordSliceOutcome($lote, $slice);
        }

        $lessonItems = [];
        foreach ($this->defaultLessonCandidates($lote, $slices) as $candidate) {
            $lessonItems[] = $this->proposeLessonCandidate($candidate);
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => self::STATUS_RECORDED,
            self::FIELD_LOTE => $lote,
            self::FIELD_SERIES_TAG => self::SERIES_TAG,
            self::FIELD_SCOREBOARD_PATH => self::SCOREBOARD_RELATIVE_PATH,
            self::FIELD_SLICES => [
                self::FIELD_TERMINAL => count($slices),
                self::FIELD_IDS => array_map(static fn (array $slice): string => AiValueNormalizer::trimmedScalarStringOrNull($slice['id'] ?? null) ?? '', $slices),
            ],
            self::FIELD_OUTCOMES => [
                self::STATUS_RECORDED => count(array_filter(
                    $outcomeItems,
                    static fn (array $item): bool => ($item[self::FIELD_STATUS] ?? null) === self::STATUS_RECORDED
                )),
                self::FIELD_ITEMS => $outcomeItems,
            ],
            self::FIELD_LESSON_CANDIDATES => [
                self::FIELD_PATH => self::LESSON_PATH_NORMAL_CAPTURE,
                self::FIELD_QUEUED => count(array_filter(
                    $lessonItems,
                    static fn (array $item): bool => ($item[self::FIELD_STATUS] ?? null) === self::LESSON_STATUS_PENDING_REVIEW
                )),
                self::FIELD_ITEMS => $lessonItems,
            ],
        ];
    }

    /**
     * Public so tests and future lote-close callers can feed an explicit candidate while
     * still using the same normal write-back path as the generated retro candidate.
     *
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function proposeLessonCandidate(array $candidate): array
    {
        $payload = array_merge([
            self::FIELD_KIND => self::KIND_FAILURE_PATTERN,
            self::FIELD_SCOPE => self::SERIES_TAG,
            self::FIELD_FLOW_ID => self::SERIES_TAG,
            self::FIELD_WORKSPACE => base_path(),
            self::FIELD_PRIVACY_CLASS => 'normal',
        ], $candidate, [
            'payload' => array_merge(AiValueNormalizer::arrayOrEmpty($candidate['payload'] ?? null), [
                'source' => self::SOURCE_ACOS_MAX_OBRA_RETRO,
                self::FIELD_SERIES_TAG => self::SERIES_TAG,
            ]),
        ]);

        $result = $this->writeBack->proposeLearning($payload);

        return [
            self::FIELD_STATUS => (AiValueNormalizer::trimmedStringOrNull($result[self::FIELD_STATUS] ?? null) ?? self::STATUS_UNKNOWN),
            self::FIELD_REASON => (AiValueNormalizer::trimmedStringOrNull($result[self::FIELD_REASON] ?? null) ?? ''),
            'proposal_id' => $result['proposal_id'] ?? null,
            self::FIELD_KIND => AiValueNormalizer::trimmedStringOrNull($result[self::FIELD_KIND] ?? $payload[self::FIELD_KIND] ?? null) ?? '',
            'quality' => AiValueNormalizer::arrayOrEmpty($result['quality'] ?? null),
            'memory_admission' => AiValueNormalizer::arrayOrEmpty($result['memory_admission'] ?? null),
            self::FIELD_AUTO_PROMOTED => (AiValueNormalizer::boolOrNull($result[self::FIELD_AUTO_PROMOTED] ?? null) ?? false),
            'requires_human_review' => (AiValueNormalizer::boolOrNull($result['requires_human_review'] ?? null) ?? true),
        ];
    }

    /**
     * @param  array<string,mixed>  $slice
     * @return array<string,mixed>
     */
    private function recordSliceOutcome(int $lote, array $slice): array
    {
        $status = match ($slice[self::FIELD_STATE]) {
            self::SLICE_STATE_LANDED => self::OUTCOME_STATUS_SUCCEEDED,
            self::SLICE_STATE_REFUTADO => self::OUTCOME_STATUS_FAILED,
            default => self::STATUS_BLOCKED,
        };

        $recorded = $this->outcomes->record([
            self::FIELD_EXECUTOR => 'forge',
            'objective' => sprintf('ACOS Max lote %d slice %s reached %s', $lote, $slice['id'], $slice[self::FIELD_STATE]),
            self::FIELD_SUMMARY => AiValueNormalizer::trimmedScalarStringOrNull($slice['line'] ?? null) ?? '',
            self::FIELD_STATUS => $status,
            self::FIELD_WORKSPACE => base_path(),
            'surface_id' => self::SERIES_TAG,
            'scope_type' => 'obra_lote',
            'scope_id' => sprintf('acos-max:lote-%d', $lote),
            'run_id' => sprintf('acos-max:lote-%d:%s:%s', $lote, $slice['id'], $slice[self::FIELD_STATE]),
            'provider' => 'local',
            'verified' => true,
            self::FIELD_ACTOR_TAG => self::SERIES_TAG,
            'outcome_flow_id' => self::SERIES_TAG,
            self::FIELD_LOTE => $lote,
            self::FIELD_SLICE_ID => $slice['id'],
            self::FIELD_SLICE_STATE => $slice[self::FIELD_STATE],
            self::FIELD_EVIDENCE_REFS => AiValueNormalizer::arrayOrEmpty($slice[self::FIELD_EVIDENCE_REFS] ?? null),
            'metrics' => [
                'tests_passed' => $status === self::OUTCOME_STATUS_SUCCEEDED,
                'obra_retro_lote' => $lote,
            ],
        ]);

        return [
            self::FIELD_SLICE_ID => AiValueNormalizer::trimmedScalarStringOrNull($slice['id'] ?? null) ?? '',
            self::FIELD_SLICE_STATE => AiValueNormalizer::trimmedScalarStringOrNull($slice[self::FIELD_STATE] ?? null) ?? '',
            self::FIELD_STATUS => (AiValueNormalizer::trimmedStringOrNull($recorded[self::FIELD_STATUS] ?? null) ?? self::STATUS_UNKNOWN),
            'outcome_id' => data_get($recorded, 'outcome.outcome_id'),
            self::FIELD_AI_RUN_OUTCOME_ID => data_get($recorded, 'spine.ai_run_outcome.id'),
            self::FIELD_SERIES_TAG => self::SERIES_TAG,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $slices
     * @return list<array<string,mixed>>
     */
    private function defaultLessonCandidates(int $lote, array $slices): array
    {
        $ids = array_map(static fn (array $slice): string => AiValueNormalizer::trimmedScalarStringOrNull($slice['id'] ?? null) ?? '', $slices);

        return [[
            self::FIELD_KIND => self::KIND_FAILURE_PATTERN,
            self::FIELD_SUMMARY => sprintf(
                'ACOS Max lote %d close showed %d terminal slices (%s) need durable scoreboard refs and the obra:acos-max series tag so future lote retros can separate construction outcomes from product outcomes.',
                $lote,
                count($slices),
                implode(', ', array_slice($ids, 0, 6)),
            ),
            self::FIELD_EVIDENCE_REFS => array_values(array_unique(array_merge(
                [sprintf('scoreboard:lote-%d', $lote)],
                array_slice(array_merge(...array_map(
                    static fn (array $slice): array => AiValueNormalizer::arrayOrEmpty($slice[self::FIELD_EVIDENCE_REFS] ?? null),
                    $slices,
                )), 0, 12),
            ))),
            self::FIELD_CURRENT_STATE => [
                self::FIELD_LOTE => $lote,
                'terminal_slice_count' => count($slices),
            ],
            'proposed_state' => [
                self::FIELD_FUTURE_LOTE_CLOSE_REQUIRES => [
                    'scoreboard_slice_refs',
                    'outc_01_outcome_records',
                    'obra:acos-max_series_tag',
                    'normal_quality_gated_lesson_candidates',
                ],
            ],
        ]];
    }

    /**
     * @return list<array{id:string,state:string,line:string,evidence_refs:list<string>}>
     */
    private function terminalSlicesForLote(int $lote): array
    {
        $path = base_path(self::SCOREBOARD_RELATIVE_PATH);
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $inside = false;
        $slices = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^## LOTE\s+(\d+)\b/u', $line, $match) === 1) {
                if ($inside) {
                    break;
                }
                $inside = ((int) $match[1]) === $lote;

                continue;
            }
            if (! $inside) {
                continue;
            }
            if (preg_match('/^- \[x\]\s+(.+?)\s+—\s+(.+)$/u', $line, $match) !== 1) {
                continue;
            }

            $state = $this->terminalState(AiValueNormalizer::trimmedScalarStringOrNull($match[2] ?? null) ?? '');
            if ($state === null) {
                continue;
            }

            $sliceId = AiValueNormalizer::trimmedStringOrNull($match[1]) ?? '';
            $slices[] = [
                'id' => $sliceId,
                self::FIELD_STATE => $state,
                'line' => $line,
                self::FIELD_EVIDENCE_REFS => [
                    sprintf('scoreboard:lote-%d:%s', $lote, $sliceId),
                    self::SCOREBOARD_RELATIVE_PATH.':'.($index + 1),
                ],
            ];
        }

        return $slices;
    }

    private function terminalState(string $statusText): ?string
    {
        $first = AiValueNormalizer::lowerTrimmedString(explode('·', $statusText, 2)[0] ?? $statusText);

        return match (true) {
            str_starts_with($first, self::SLICE_STATE_LANDED) => self::SLICE_STATE_LANDED,
            str_starts_with($first, self::SLICE_STATE_REFUTADO) => self::SLICE_STATE_REFUTADO,
            str_starts_with($first, self::SLICE_STATE_SUSPENDED) => self::SLICE_STATE_SUSPENDED,
            default => null,
        };
    }
}
