<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasLessonQualityService;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasAcosFreezeCommand extends Command
{
    use EmitsCanonicalJson;

    public const SCHEMA_VERSION = 'atlas.acos.measure_freeze.v1';

    public const JSONL_RELATIVE_PATH = 'app/atlas/evidence/acos-measure-freeze.jsonl';

    protected $signature = 'atlas:acos:freeze
        {--json= : Measure-freeze payload JSON; omitted uses the MAXG-01 default freeze payload}
        {--path= : Override freeze JSONL path (tests and explicit operator freezes)}';

    protected $description = 'Record an ACOS measure_freeze in the Evidence Ledger with independent judge assertion.';

    public function handle(): int
    {
        $payload = $this->payloadFromOption();
        if ($payload === null) {
            return $this->emit(['ok' => false, 'reason' => 'invalid_json_payload'], self::FAILURE);
        }

        $author = trim((string) ($payload['author_engine_id'] ?? ''));
        $judge = trim((string) ($payload['judge_engine_id'] ?? ''));
        $measureId = trim((string) ($payload['measure_id'] ?? ''));

        if (($payload['kind'] ?? null) !== 'measure_freeze' || $measureId === '' || $author === '' || $judge === '') {
            return $this->emit(['ok' => false, 'reason' => 'invalid_measure_freeze_payload'], self::FAILURE);
        }
        if ($author === $judge) {
            return $this->emit(['ok' => false, 'reason' => 'judge_engine_id_must_differ_from_author_engine_id'], self::FAILURE);
        }

        // JSONL is the durable freeze surface when Postgres availability checks hang
        // (Schema::hasTable can block without throwing). Always check/write JSONL first.
        $jsonlPath = $this->freezePath();
        if ($this->jsonlAlreadyFrozen($measureId, $jsonlPath)) {
            return $this->emit([
                'ok' => true,
                'status' => 'already_frozen',
                'measure_id' => $measureId,
                'storage' => 'jsonl',
                'jsonl_path' => $jsonlPath,
            ], self::SUCCESS);
        }

        $payload['schema_version'] = self::SCHEMA_VERSION;
        $payload['event_name'] = 'measure.freeze.recorded';
        $payload['recorded_at'] = now()->toIso8601String();
        $payload['judge_author_distinct'] = true;
        $payload['content_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // JSONL-first: Postgres Schema::hasTable can hang without throwing when the
        // local container is wedged; freeze must still seal. DB mirror is optional later.
        @mkdir(dirname($jsonlPath), 0775, true);
        (new JsonlReceiptStore($jsonlPath))->append($payload);

        $dualReadExit = $this->recordDualRead($measureId, $payload);

        return $this->emit([
            'ok' => true,
            'status' => 'frozen',
            'measure_id' => $measureId,
            'event_id' => null,
            'storage' => 'jsonl',
            'content_hash' => $payload['content_hash'],
            'jsonl_path' => $jsonlPath,
            'dual_read_exit_code' => $dualReadExit,
        ], self::SUCCESS);
    }

    private function jsonlAlreadyFrozen(string $measureId, string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode(trim($line), true);
                if (! is_array($row)) {
                    continue;
                }
                if (($row['measure_id'] ?? null) === $measureId && ($row['kind'] ?? null) === 'measure_freeze') {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    private function freezePath(): string
    {
        $path = $this->option('path');
        if (is_string($path) && trim($path) !== '') {
            return trim($path);
        }

        return storage_path(self::JSONL_RELATIVE_PATH);
    }

    /** @return array<string,mixed> */
    public static function defaultFreezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => 'aobg.latency_ledger.v1',
            'formula' => 'p50/p95 wall_ms per op from daily JSONL; ops∈{pack,recall,hook}',
            'thresholds' => [
                'pack_p95_ms_alert' => 18000,
                'recall_p95_ms_alert' => 15000,
                'hook_p95_ms_alert' => 20000,
                'denominator_min_samples' => 5,
            ],
            'denominator_min' => 5,
            'ttl_days' => 90,
            'author_engine_id' => 'cursor-grok-acos-max',
            'judge_engine_id' => 'codex-external-judge',
            'baseline_manual_2026_07_11' => [
                'turn_ms' => 65000,
                'hooks_ms' => [15180, 8110, 5220],
                'pack_hotspot_ms_range' => [13000, 18000],
                'basis' => 'manual_stopwatch_degraded',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function asiMetricMFreezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => AtlasAcosMSeriesCommand::MEASURE_ID,
            'formula_version' => AtlasAcosMSeriesCommand::FORMULA_VERSION,
            'formula' => AtlasAcosMSeriesCommand::FORMULA_TEXT,
            'thresholds' => [
                'denominator_min_turns_per_arm' => AtlasAcosMSeriesCommand::DENOMINATOR_MIN,
                'raw_value_per_turn_must_be_positive' => true,
            ],
            'denominator_min' => AtlasAcosMSeriesCommand::DENOMINATOR_MIN,
            'ttl_days' => 90,
            'author_engine_id' => 'cursor-acos-max-elev-02',
            'judge_engine_id' => 'codex-independent-measure-judge',
            'series' => [
                'id' => 'm_measured.v1',
                'reader_command' => 'atlas:acos:m-series --json',
                'default_input_path' => 'storage/app/atlas/evidence/acos-m-series-input.jsonl',
                'registry_status' => 'pending_elev_20s',
            ],
            'components' => [
                'context_component' => 'used_ratio_measured * (post_execution_utility / 100)',
                'green_component' => 'green_run_pass_rate',
                'rework_component' => 'rework_avoided / turns',
            ],
            'comparison' => 'M = value_per_turn_acos / value_per_turn_raw, grouped separately by window and mode (interactive|delegated).',
            'dual_read_required' => false,
        ];
    }

    /** @return array<string,mixed> */
    public static function lessonQualityFreezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => AtlasLessonQualityService::MEASURE_ID,
            'formula_version' => AtlasLessonQualityService::FORMULA_VERSION,
            'formula' => 'Group ai_learning_candidates joined to ai_rag_feedback_events by memory_candidate_id and OUTC-01 run outcomes by memory_type x flow_id x scope; expose candidate_count, promoted_rate, measured_lift, case_count, negative_count, measured_count, total.',
            'thresholds' => [
                'denominator_min_measured_cases_per_group' => AtlasLessonQualityService::DENOMINATOR_MIN,
                'empty_window_status' => 'insufficient_signal',
                'single_scalar_score_allowed' => false,
            ],
            'denominator_min' => AtlasLessonQualityService::DENOMINATOR_MIN,
            'ttl_days' => 30,
            'author_engine_id' => 'cursor-acos-max-maxj-01',
            'judge_engine_id' => 'codex-independent-lesson-quality-judge',
            'series' => [
                'id' => AtlasLessonQualityService::MEASURE_ID,
                'reader_command' => 'atlas:ai:lesson-quality --json',
                'registry_status' => 'registered_elev_20s',
            ],
            'dual_read' => [
                'valor_antigo' => 'atlas.ai.learning_recall_use_lift.v1 aggregate reader unchanged',
                'valor_novo' => 'atlas.ai.lesson_quality.v2 grouped lesson-quality reader',
                'justificativa' => 'MAXJ-01 adds a grouped v2 medidor beside the frozen aggregate RecallUseLift v1 reader.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function lessonTypeYieldFreezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_MEASURE_ID,
            'formula_version' => AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_FORMULA_VERSION,
            'formula' => 'Segment existing learning recall/use A/B feedback by active ai_compounding_memories.memory_type; each type compares cases where that type was actually used against baseline feedback with no recalled memory type, and reports lift only when case_count and baseline_count both satisfy the hard v2 floor.',
            'thresholds' => [
                'denominator_min_cases_per_type' => AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_DENOMINATOR_MIN,
                'floor_policy' => 'max(8, requested_min_cases)',
                'aggregate_v1_config_mutation_allowed' => false,
            ],
            'denominator_min' => AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_DENOMINATOR_MIN,
            'ttl_days' => 30,
            'author_engine_id' => 'cursor-acos-max-maxj-05',
            'judge_engine_id' => 'codex-independent-lesson-type-yield-judge',
            'series' => [
                'id' => AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_MEASURE_ID,
                'reader_command' => 'atlas:ai:lesson-type-yield --json',
                'registry_status' => 'registered_elev_20s',
            ],
            'dual_read' => [
                'valor_antigo' => 'atlas.ai.learning_recall_use_lift.v1 aggregate reader with existing min_cases_per_arm config',
                'valor_novo' => 'atlas.ai.lesson_type_yield.v2 memory_type segmented reader with hard n>=8 floor',
                'justificativa' => 'MAXJ-05 adds a new v2 series beside the frozen aggregate v1 reader; config/atlas.php learning_recall_use_lift.min_cases_per_arm remains untouched.',
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function payloadFromOption(): ?array
    {
        $raw = $this->option('json');
        if (! is_string($raw) || trim($raw) === '') {
            return self::defaultFreezePayload();
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $payload */
    private function recordDualRead(string $measureId, array $payload): int
    {
        if (($payload['dual_read_required'] ?? true) === false) {
            return self::SUCCESS;
        }

        try {
            $thresholds = (array) ($payload['thresholds'] ?? []);
            $dualRead = (array) ($payload['dual_read'] ?? []);
            $dual = [
                'schema_version' => AtlasMeasureDualReadCommand::SCHEMA_VERSION,
                'medidor' => $measureId,
                'valor_antigo' => (string) ($dualRead['valor_antigo'] ?? 'simulated_arlcg_estimate:degraded_manual'),
                'valor_novo' => (string) ($dualRead['valor_novo'] ?? 'real_latency_ledger:pending_samples_min='.(int) ($payload['denominator_min'] ?? $thresholds['denominator_min_samples'] ?? 5)),
                'justificativa' => (string) ($dualRead['justificativa'] ?? 'MAXG-01 freeze: degraded simulated ARLCG latency estimate will be dual-read against real AOBG JSONL p50/p95 samples.'),
                'commit' => trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD 2>/dev/null')) ?: 'unknown',
                'recorded_at' => now()->toIso8601String(),
            ];
            $path = storage_path(AtlasMeasureDualReadCommand::JSONL_RELATIVE_PATH);
            @mkdir(dirname($path), 0775, true);
            (new JsonlReceiptStore($path))->append($dual);

            return self::SUCCESS;
        } catch (Throwable) {
            return self::FAILURE;
        }
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        $this->line($this->encode($payload));

        return $code;
    }
}
