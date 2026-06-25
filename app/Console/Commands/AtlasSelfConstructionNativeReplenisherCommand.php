<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionFrontierToPacketDrafter;
use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionNativeReplenisherEnqueueRunner;
use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionNativeReplenisherFrontierContract;
use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionNativeReplenisherPreflight;
use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionQueueTopUpPolicy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only CLI for the Self-Construction native replenisher pipeline. Five verbs:
 *   contract  — normalize frontier facts.
 *   draft     — turn accepted frontiers into packet drafts.
 *   preflight — run the quality preflight over packet drafts.
 *   top-up    — invoke the queue top-up policy.
 *   run       — dry-run the end-to-end flow (no orchestrator binding).
 *
 * The run verb composes the four prior services into facts but does NOT spawn processes or call
 * providers. Every drafted packet carries final_runtime_owner=atlas_native in its autonomy_contract.
 */
final class AtlasSelfConstructionNativeReplenisherCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:self-construction:native-replenisher {action : contract|draft|preflight|top-up|run} {--facts=} {--json}';

    /** @var string */
    protected $description = 'Read-only native-replenisher surface: contract / draft / preflight / top-up / run.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $facts = $this->readJson('facts');

        $payload = match ($action) {
            'contract' => $this->contract($facts),
            'draft' => $this->draft($facts),
            'preflight' => $this->preflight($facts),
            'top-up' => $this->topUp($facts),
            'run' => $this->run($facts),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string,mixed>|null $facts @return array<string,mixed> */
    private function contract(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['frontiers'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with frontiers[] required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionNativeReplenisherFrontierContract::class)->normalize((array) $facts['frontiers']);

        return ['status' => 'ok', 'contract' => $r];
    }

    /** @param array<string,mixed>|null $facts @return array<string,mixed> */
    private function draft(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['accepted_frontiers'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with accepted_frontiers[] required'];
        }
        try {
            $r = $this->app()->make(AtlasSelfConstructionFrontierToPacketDrafter::class)->draft((array) $facts['accepted_frontiers']);
        } catch (Throwable $e) {
            return ['status' => 'draft_invalid', 'reason' => $e->getMessage()];
        }

        return ['status' => 'ok', 'packet_drafts' => $r];
    }

    /** @param array<string,mixed>|null $facts @return array<string,mixed> */
    private function preflight(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['packet_drafts'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with packet_drafts[] required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionNativeReplenisherPreflight::class)->preflight((array) $facts['packet_drafts']);

        return ['status' => 'ok', 'preflight' => $r];
    }

    /** @param array<string,mixed>|null $facts @return array<string,mixed> */
    private function topUp(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionQueueTopUpPolicy::class)->decide($facts);

        return ['status' => 'ok', 'top_up' => $r];
    }

    /**
     * Dry-run end-to-end: contract → draft → preflight → top-up. No orchestrator binding ⇒ enqueue
     * step reports counts=0 (verifiable that final_runtime_owner is atlas_native in every draft).
     *
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function run(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['frontiers'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with frontiers[] required'];
        }
        $contract = $this->app()->make(AtlasSelfConstructionNativeReplenisherFrontierContract::class)->normalize((array) $facts['frontiers']);
        try {
            $drafts = $this->app()->make(AtlasSelfConstructionFrontierToPacketDrafter::class)->draft($contract['accepted']);
        } catch (Throwable $e) {
            return ['status' => 'draft_invalid', 'reason' => $e->getMessage()];
        }
        $preflight = $this->app()->make(AtlasSelfConstructionNativeReplenisherPreflight::class)->preflight($drafts);
        $topUp = $this->app()->make(AtlasSelfConstructionQueueTopUpPolicy::class)->decide(is_array($facts['queue_facts'] ?? null) ? $facts['queue_facts'] : []);

        // Verify atlas_native owner on every draft (FACT, never modified).
        foreach ($drafts as $d) {
            if (($d['autonomy_contract']['runtime_owner'] ?? null) !== 'atlas_native') {
                return ['status' => 'invariant_violation', 'reason' => 'final_runtime_owner_not_atlas_native:'.(string) ($d['frontier_id'] ?? '')];
            }
        }

        return [
            'status' => 'ok',
            'contract' => $contract,
            'packet_drafts' => $drafts,
            'preflight' => $preflight,
            'top_up' => $topUp,
            'enqueued' => [],
            'skipped' => [],
            'blocked' => [],
            'final_runtime_owner' => 'atlas_native',
            'dry_run' => true,
        ];
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
