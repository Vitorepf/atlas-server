<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder;
use App\Services\Ai\AtlasOpenBrainWriteBackService;

final class AcosMaxObraRetroService
{
    public const SCHEMA_VERSION = 'atlas.acos_max.obra_retro.v1';

    public const SERIES_TAG = 'obra:acos-max';

    private const SCOREBOARD_RELATIVE_PATH = 'docs/engineering-knowledge-base/atlas-acos-max-execution-scoreboard-v1.md';

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
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'reason' => 'no_terminal_slices_for_lote',
                'lote' => $lote,
                'series_tag' => self::SERIES_TAG,
                'outcomes' => ['recorded' => 0, 'items' => []],
                'lesson_candidates' => [
                    'path' => 'normal_capture_quality_gate_and_asi_02',
                    'queued' => 0,
                    'items' => [],
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
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'recorded',
            'lote' => $lote,
            'series_tag' => self::SERIES_TAG,
            'scoreboard_path' => self::SCOREBOARD_RELATIVE_PATH,
            'slices' => [
                'terminal' => count($slices),
                'ids' => array_map(static fn (array $slice): string => (string) $slice['id'], $slices),
            ],
            'outcomes' => [
                'recorded' => count(array_filter(
                    $outcomeItems,
                    static fn (array $item): bool => ($item['status'] ?? null) === 'recorded'
                )),
                'items' => $outcomeItems,
            ],
            'lesson_candidates' => [
                'path' => 'normal_capture_quality_gate_and_asi_02',
                'queued' => count(array_filter(
                    $lessonItems,
                    static fn (array $item): bool => ($item['status'] ?? null) === 'pending_review'
                )),
                'items' => $lessonItems,
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
            'kind' => 'failure_pattern',
            'scope' => self::SERIES_TAG,
            'flow_id' => self::SERIES_TAG,
            'workspace' => base_path(),
            'privacy_class' => 'normal',
        ], $candidate, [
            'payload' => array_merge((array) ($candidate['payload'] ?? []), [
                'source' => 'acos_max_obra_retro',
                'series_tag' => self::SERIES_TAG,
            ]),
        ]);

        $result = $this->writeBack->proposeLearning($payload);

        return [
            'status' => (string) ($result['status'] ?? 'unknown'),
            'reason' => (string) ($result['reason'] ?? ''),
            'proposal_id' => $result['proposal_id'] ?? null,
            'kind' => (string) ($result['kind'] ?? ($payload['kind'] ?? '')),
            'quality' => (array) ($result['quality'] ?? []),
            'memory_admission' => (array) ($result['memory_admission'] ?? []),
            'auto_promoted' => (bool) ($result['auto_promoted'] ?? false),
            'requires_human_review' => (bool) ($result['requires_human_review'] ?? true),
        ];
    }

    /**
     * @param  array<string,mixed>  $slice
     * @return array<string,mixed>
     */
    private function recordSliceOutcome(int $lote, array $slice): array
    {
        $status = match ($slice['state']) {
            'landed' => 'succeeded',
            'refutado' => 'failed',
            default => 'blocked',
        };

        $recorded = $this->outcomes->record([
            'executor' => 'forge',
            'objective' => sprintf('ACOS Max lote %d slice %s reached %s', $lote, $slice['id'], $slice['state']),
            'summary' => (string) $slice['line'],
            'status' => $status,
            'workspace' => base_path(),
            'surface_id' => self::SERIES_TAG,
            'scope_type' => 'obra_lote',
            'scope_id' => sprintf('acos-max:lote-%d', $lote),
            'run_id' => sprintf('acos-max:lote-%d:%s:%s', $lote, $slice['id'], $slice['state']),
            'provider' => 'local',
            'verified' => true,
            'actor_tag' => self::SERIES_TAG,
            'outcome_flow_id' => self::SERIES_TAG,
            'lote' => $lote,
            'slice_id' => $slice['id'],
            'slice_state' => $slice['state'],
            'evidence_refs' => (array) $slice['evidence_refs'],
            'metrics' => [
                'tests_passed' => $status === 'succeeded',
                'obra_retro_lote' => $lote,
            ],
        ]);

        return [
            'slice_id' => (string) $slice['id'],
            'slice_state' => (string) $slice['state'],
            'status' => (string) ($recorded['status'] ?? 'unknown'),
            'outcome_id' => data_get($recorded, 'outcome.outcome_id'),
            'ai_run_outcome_id' => data_get($recorded, 'spine.ai_run_outcome.id'),
            'series_tag' => self::SERIES_TAG,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $slices
     * @return list<array<string,mixed>>
     */
    private function defaultLessonCandidates(int $lote, array $slices): array
    {
        $ids = array_map(static fn (array $slice): string => (string) $slice['id'], $slices);

        return [[
            'kind' => 'failure_pattern',
            'summary' => sprintf(
                'ACOS Max lote %d close showed %d terminal slices (%s) need durable scoreboard refs and the obra:acos-max series tag so future lote retros can separate construction outcomes from product outcomes.',
                $lote,
                count($slices),
                implode(', ', array_slice($ids, 0, 6)),
            ),
            'evidence_refs' => array_values(array_unique(array_merge(
                [sprintf('scoreboard:lote-%d', $lote)],
                array_slice(array_merge(...array_map(
                    static fn (array $slice): array => (array) $slice['evidence_refs'],
                    $slices,
                )), 0, 12),
            ))),
            'current_state' => [
                'lote' => $lote,
                'terminal_slice_count' => count($slices),
            ],
            'proposed_state' => [
                'future_lote_close_requires' => [
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

            $state = $this->terminalState((string) $match[2]);
            if ($state === null) {
                continue;
            }

            $sliceId = trim((string) $match[1]);
            $slices[] = [
                'id' => $sliceId,
                'state' => $state,
                'line' => $line,
                'evidence_refs' => [
                    sprintf('scoreboard:lote-%d:%s', $lote, $sliceId),
                    self::SCOREBOARD_RELATIVE_PATH.':'.($index + 1),
                ],
            ];
        }

        return $slices;
    }

    private function terminalState(string $statusText): ?string
    {
        $first = strtolower(trim(explode('·', $statusText, 2)[0] ?? $statusText));

        return match (true) {
            str_starts_with($first, 'landed') => 'landed',
            str_starts_with($first, 'refutado') => 'refutado',
            str_starts_with($first, 'suspended') => 'suspended',
            default => null,
        };
    }
}
