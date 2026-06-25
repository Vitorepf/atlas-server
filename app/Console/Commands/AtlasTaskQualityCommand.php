<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBulkRespecDraft;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskRespecPlanBuilder;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskWorkerInstructionLint;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only CLI for the Atlas task-quality audit surface. Four verbs:
 *   inspect      — services + non-execution guarantees.
 *   respec-plan  — apply the respec plan builder to a single --packet JSON.
 *   bulk-draft   — apply the bulk respec drafter to a --input JSON list.
 *   lint         — apply the worker instruction lint to a --packet JSON.
 *
 * Every verb is FACTS-ONLY: never mutates queue records, never marks a packet complete.
 */
final class AtlasTaskQualityCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:task:quality {action : inspect|respec-plan|bulk-draft|lint} {--packet=} {--input=} {--json}';

    /** @var string */
    protected $description = 'Read-only task quality audit: inspect / respec-plan / bulk-draft / lint.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'inspect' => $this->inspect(),
            'respec-plan' => $this->respecPlan(),
            'bulk-draft' => $this->bulkDraft(),
            'lint' => $this->lint(),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function inspect(): array
    {
        return [
            'status' => 'ok',
            'verbs' => ['inspect', 'respec-plan', 'bulk-draft', 'lint'],
            'services' => [
                AtlasTaskRespecPlanBuilder::SCHEMA,
                AtlasTaskBulkRespecDraft::SCHEMA,
                AtlasTaskWorkerInstructionLint::SCHEMA,
            ],
            'non_execution_guarantees' => [
                'mutates_queue_records' => false,
                'marks_packet_complete' => false,
                'invokes_shell_or_git' => false,
                'calls_external_providers' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function respecPlan(): array
    {
        $packet = $this->readJson('packet');
        if (! is_array($packet)) {
            return ['status' => 'usage_error', 'reason' => '--packet JSON file required'];
        }
        $r = $this->app()->make(AtlasTaskRespecPlanBuilder::class)->build($packet);

        return ['status' => 'ok', 'respec_plan' => $r];
    }

    /** @return array<string,mixed> */
    private function bulkDraft(): array
    {
        $input = $this->readJson('input');
        if (! is_array($input) || ! isset($input['records'])) {
            return ['status' => 'usage_error', 'reason' => '--input JSON with records[] required'];
        }
        $records = is_array($input['records']) ? $input['records'] : [];
        $r = $this->app()->make(AtlasTaskBulkRespecDraft::class)->draft($records);

        return ['status' => 'ok', 'bulk_draft' => $r];
    }

    /** @return array<string,mixed> */
    private function lint(): array
    {
        $packet = $this->readJson('packet');
        if (! is_array($packet)) {
            return ['status' => 'usage_error', 'reason' => '--packet JSON file required'];
        }
        $r = $this->app()->make(AtlasTaskWorkerInstructionLint::class)->lint($packet);

        return ['status' => 'ok', 'lint' => $r];
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
