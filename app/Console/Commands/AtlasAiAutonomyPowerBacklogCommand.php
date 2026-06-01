<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiAutonomyPowerBacklogService;
use Illuminate\Console\Command;

/**
 * Runtime surface for the Atlas AI Autonomy And Power Backlog doc. Without args
 * it prints the governance snapshot (central permission rule, ordered backlog,
 * promotion requirements). With --action it classifies one autonomous action
 * against the central permission rule; with --item + --gate-satisfied it
 * evaluates whether a backlog item may be implemented now.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
 */
final class AtlasAiAutonomyPowerBacklogCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-autonomy-power-backlog
        {--action= : Classify one autonomous action (detect|plan|sandbox-test|mutate-production|spend-money|access-privacy)}
        {--item= : Backlog item id to evaluate for implementation readiness}
        {--gate-satisfied : Mark the backlog item gate as satisfied (used with --item)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the Atlas AI autonomy power backlog (permission rule, ordered backlog, promotion gate) runtime.';

    public function handle(AtlasAiAutonomyPowerBacklogService $service): int
    {
        $json = (bool) $this->option('json');

        try {
            $action = $this->option('action');
            if (is_string($action) && trim($action) !== '') {
                return $this->emit($service->classifyAction(str_replace('-', '_', $action)), $json);
            }

            $item = $this->option('item');
            if (is_string($item) && trim($item) !== '') {
                $result = $service->itemReadyToImplement($item, (bool) $this->option('gate-satisfied'));

                return $this->emit($result, $json);
            }

            return $this->emit($service->snapshot(), $json);
        } catch (\Throwable $e) {
            $this->emit([
                'schema_version' => AtlasAiAutonomyPowerBacklogService::SCHEMA_VERSION,
                'error' => true,
                'message' => $e->getMessage(),
            ], $json);

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, bool $json): int
    {
        $this->line((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return self::SUCCESS;
    }
}
