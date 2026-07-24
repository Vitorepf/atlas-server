<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Receipts\AtlasSelfConstructionReceiptMemoryExportPlan;
use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricGiveBackLearningIntegrator;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only CLI for the Learning Transfer surface. Four verbs:
 *   classify — group give_back events and propose per-packet recommendation FACTS.
 *   gate     — apply a transfer admission gate (must be bound + proven + non-secret).
 *   plan     — emit a lesson-transfer plan (export ids + reasons) without writing anything.
 *   template — emit the canonical lesson record template (shape only — no data).
 *
 * Every verb is FACTS-ONLY: never writes memory, docs, or queue records.
 */
final class AtlasSelfConstructionLearningTransferCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:self-construction:learning-transfer {action : classify|gate|plan|template} {--facts=} {--json}';

    /** @var string */
    protected $description = 'Read-only Learning Transfer surface: classify / gate / plan / template.';

    public const LESSON_TEMPLATE = [
        'schema' => 'atlas.learning_transfer.lesson_record.v1',
        'lesson_id' => null,
        'source_receipt_ref' => null,
        'kind' => null,           // capability_lift | failure_removal | autonomy_lift | simplification | reuse
        'fact_summary' => null,
        'observation_ts' => null,
        'evidence_refs' => [],
        'provider_safe' => true,
    ];

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $facts = $this->readJson('facts');

        $payload = match ($action) {
            'classify' => $this->classify($facts),
            'gate' => $this->gate($facts),
            'plan' => $this->plan($facts),
            'template' => $this->template(),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line($this->encode($payload));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function classify(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['events'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with events[] required'];
        }
        $events = is_array($facts['events']) ? $facts['events'] : [];
        $r = $this->app()->make(AtlasTaskFabricGiveBackLearningIntegrator::class)->integrate($events);

        return ['status' => 'ok', 'classification' => $r];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function gate(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['candidates'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with candidates[] required'];
        }
        $candidates = is_array($facts['candidates']) ? $facts['candidates'] : [];
        $r = $this->app()->make(AtlasSelfConstructionReceiptMemoryExportPlan::class)->plan($candidates);

        return ['status' => 'ok', 'gate' => $r];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function plan(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['candidates'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with candidates[] required'];
        }
        $candidates = is_array($facts['candidates']) ? $facts['candidates'] : [];
        $export = $this->app()->make(AtlasSelfConstructionReceiptMemoryExportPlan::class)->plan($candidates);

        // Convert each accepted export into a lesson-plan row (template instance — NOT persisted).
        $lessons = [];
        foreach ($export['export_plan'] as $row) {
            $lessons[] = array_merge(self::LESSON_TEMPLATE, [
                'lesson_id' => $row['export_id'],
                'source_receipt_ref' => $row['source_id'],
                'kind' => $row['kind'],
                'fact_summary' => $row['fact_summary'],
            ]);
        }

        return ['status' => 'ok', 'transfer_plan' => [
            'lessons' => $lessons,
            'rejected_count' => count($export['rejections']),
        ]];
    }

    /**
     * @return array<string,mixed>
     */
    private function template(): array
    {
        return ['status' => 'ok', 'template' => self::LESSON_TEMPLATE];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
