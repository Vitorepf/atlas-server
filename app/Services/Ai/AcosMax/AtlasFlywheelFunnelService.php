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
            return $this->payload('no_signal', $path, $denominatorMin, [], 0);
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
                'outcome_count' => count($executorRows),
                'stages' => $this->stages($executorRows, $denominatorMin),
            ];
        }

        return $this->payload($byExecutor === [] ? 'no_signal' : 'ok', $path, $denominatorMin, $byExecutor, count($rows));
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
            'outcomes_without_lesson' => $this->stage(
                count(array_filter($rows, fn (array $row): bool => ! $this->hasLesson($row))),
                count($rows),
                $denominatorMin,
            ),
            'lessons_without_promotion' => $this->stage(
                count(array_filter($withLesson, static fn (array $row): bool => ($row['lesson_promoted'] ?? false) !== true)),
                count($withLesson),
                $denominatorMin,
            ),
            'promoted_without_recall' => $this->stage(
                count(array_filter($promoted, static fn (array $row): bool => ($row['promoted_lesson_recalled'] ?? false) !== true)),
                count($promoted),
                $denominatorMin,
            ),
            'recalls_without_citation' => $this->stage(
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
            'status' => $den >= $denominatorMin ? 'ok' : 'insufficient',
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function hasLesson(array $row): bool
    {
        return in_array((string) ($row['learning_status'] ?? ''), ['candidate', 'promoted', 'applied'], true)
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
            $line = AiValueNormalizer::trimmedString($line);
            if ($line === '') {
                continue;
            }
            $decoded = AiValueNormalizer::arrayOrEmpty(json_decode($line, true));
            if ($decoded !== []) {
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
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'source' => [
                'outcomes_path' => $path,
                'outcome_rows' => $rowCount,
            ],
            'denominator_min' => $denominatorMin,
            'stages' => self::STAGES,
            'by_executor' => $byExecutor,
            'windows' => [
                'all' => [
                    'status' => $status,
                    'by_executor' => $byExecutor,
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
