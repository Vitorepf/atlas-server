<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Carbon;

final class AtlasFlywheelFunnelService
{
    public const SCHEMA_VERSION = 'atlas.m.funnel.v1';

    public const MEASURE_ID = 'atlas.m.funnel.v1';

    public const FORMULA_VERSION = 'atlas_m_funnel_v1';

    public const STATUS_OK = 'ok';

    public const STATUS_NO_SIGNAL = 'no_signal';

    public const STATUS_INSUFFICIENT = 'insufficient';
    public const FIELD_STATUS = 'status';
    public const FIELD_STAGES = 'stages';
    public const FIELD_BY_EXECUTOR = 'by_executor';
    public const FIELD_OUTCOME_COUNT = 'outcome_count';
    public const FIELD_OUTCOMES_WITHOUT_LESSON = 'outcomes_without_lesson';
    public const FIELD_LESSONS_WITHOUT_PROMOTION = 'lessons_without_promotion';
    public const FIELD_PROMOTED_WITHOUT_RECALL = 'promoted_without_recall';
    public const FIELD_RECALLS_WITHOUT_CITATION = 'recalls_without_citation';

    /** @var list<string> */
    public const STAGES = [
        'outcomes_without_lesson',
        'lessons_without_promotion',
        'promoted_without_recall',
        'recalls_without_citation',
        'citations_without_better_outcome',
    ];

    public function __construct(
        private readonly AtlasDecideLiveOutcomeFeedbackService $outcomes,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(?string $outcomesPath = null, int $denominatorMin = 1): array
    {
        $path = $outcomesPath ?: $this->outcomes->logPath();
        $rows = $this->readJsonl($path);
        $denominatorMin = max(1, $denominatorMin);

        if ($rows === []) {
            return $this->payload(self::STATUS_NO_SIGNAL, $path, $denominatorMin, [], 0);
        }

        $byExecutorRows = [];
        foreach ($rows as $row) {
            $executor = $this->executor($row);
            if ($executor === '') {
                continue;
            }
            $byExecutorRows[$executor][] = $row;
        }
        ksort($byExecutorRows);

        $byExecutor = [];
        foreach ($byExecutorRows as $executor => $executorRows) {
            $byExecutor[$executor] = [
                self::FIELD_OUTCOME_COUNT => count($executorRows),
                self::FIELD_STAGES => $this->stages($executorRows, $denominatorMin),
            ];
        }

        return $this->payload($byExecutor === [] ? self::STATUS_NO_SIGNAL : self::STATUS_OK, $path, $denominatorMin, $byExecutor, count($rows));
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,array{num:int,den:int,status:string}>
     */
    private function stages(array $rows, int $denominatorMin): array
    {
        $withLesson = array_values(array_filter($rows, fn (array $row): bool => $this->hasLesson($row)));
        $promoted = array_values(array_filter($withLesson, static fn (array $row): bool => ($row['lesson_promoted'] ?? false) === true));
        $recalled = array_values(array_filter($promoted, static fn (array $row): bool => ($row['promoted_lesson_recalled'] ?? false) === true));
        $cited = array_values(array_filter($recalled, static fn (array $row): bool => ($row['promoted_lesson_cited'] ?? false) === true));

        return [
            self::FIELD_OUTCOMES_WITHOUT_LESSON => $this->stage(
                count(array_filter($rows, fn (array $row): bool => ! $this->hasLesson($row))),
                count($rows),
                $denominatorMin,
            ),
            self::FIELD_LESSONS_WITHOUT_PROMOTION => $this->stage(
                count(array_filter($withLesson, static fn (array $row): bool => ($row['lesson_promoted'] ?? false) !== true)),
                count($withLesson),
                $denominatorMin,
            ),
            self::FIELD_PROMOTED_WITHOUT_RECALL => $this->stage(
                count(array_filter($promoted, static fn (array $row): bool => ($row['promoted_lesson_recalled'] ?? false) !== true)),
                count($promoted),
                $denominatorMin,
            ),
            self::FIELD_RECALLS_WITHOUT_CITATION => $this->stage(
                count(array_filter($recalled, static fn (array $row): bool => ($row['promoted_lesson_cited'] ?? false) !== true)),
                count($recalled),
                $denominatorMin,
            ),
            'citations_without_better_outcome' => $this->stage(
                count(array_filter($cited, static fn (array $row): bool => ($row['subsequent_outcome_improved'] ?? false) !== true)),
                count($cited),
                $denominatorMin,
            ),
        ];
    }

    /**
     * @return array{num:int,den:int,status:string}
     */
    private function stage(int $num, int $den, int $denominatorMin): array
    {
        return [
            'num' => $num,
            'den' => $den,
            self::FIELD_STATUS => $den >= $denominatorMin ? self::STATUS_OK : self::STATUS_INSUFFICIENT,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function hasLesson(array $row): bool
    {
        return in_array((AiValueNormalizer::trimmedStringOrNull($row['learning_status'] ?? null) ?? ''), ['candidate', 'promoted', 'applied'], true)
            || ($row['lesson_promoted'] ?? false) === true;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function executor(array $row): string
    {
        $role = AiValueNormalizer::lowerTrimmedString($row['role'] ?? '');
        if (in_array($role, ['dev', 'forge', 'autonomos'], true)) {
            return $role;
        }

        $actor = AiValueNormalizer::lowerTrimmedString($row['actor'] ?? '');
        foreach (['dev', 'forge', 'autonomos'] as $executor) {
            if (str_contains($actor, $executor)) {
                return $executor;
            }
        }

        return '';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (AiValueNormalizer::trimmedStringOrNull($raw) === null) {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = AiValueNormalizer::trimmedStringOrNull($line) ?? '';
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $byExecutor
     * @return array<string,mixed>
     */
    private function payload(string $status, string $path, int $denominatorMin, array $byExecutor, int $rowCount): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            self::FIELD_STATUS => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'source' => [
                'outcomes_path' => $path,
                'outcome_rows' => $rowCount,
            ],
            'denominator_min' => $denominatorMin,
            self::FIELD_STAGES => self::STAGES,
            self::FIELD_BY_EXECUTOR => $byExecutor,
            'windows' => [
                'all' => [
                    self::FIELD_STATUS => $status,
                    self::FIELD_BY_EXECUTOR => $byExecutor,
                ],
            ],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'single_scalar_score_emitted' => false,
                'used_as_producer_target' => false,
                'diagnostic_only' => true,
            ],
        ];
    }
}
