<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexFreshnessBridge;
use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexRiskGapLens;
use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexSnapshotComposer;
use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexSourceInventory;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only CLI for the Self-Construction Cortex observation surface. Four verbs:
 *   inventory — emit the source-inventory projection.
 *   freshness — emit the freshness-bridge readiness rows.
 *   risk      — emit the risk-gap lens facts.
 *   snapshot  — emit the composed world snapshot (calls all three sub-projectors).
 *
 * Every verb is FACTS-ONLY: no provider call, no shell, no git, no storage mutation. Fails closed on
 * missing or invalid --facts payloads.
 */
final class AtlasSelfConstructionCortexCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:self-construction:cortex {action : inventory|freshness|risk|snapshot} {--facts=} {--json}';

    /** @var string */
    protected $description = 'Read-only Self-Construction Cortex surface: inventory / freshness / risk / snapshot.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $facts = $this->readJson('facts');

        $payload = match ($action) {
            'inventory' => $this->inventory($facts),
            'freshness' => $this->freshness($facts),
            'risk' => $this->risk($facts),
            'snapshot' => $this->snapshot($facts),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line($this->encode($payload));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function inventory(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionCortexSourceInventory::class)->inventory($facts);

        return ['status' => 'ok', 'inventory' => $r];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function freshness(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionCortexFreshnessBridge::class)->adapt($facts);

        return ['status' => 'ok', 'freshness' => $r];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function risk(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionCortexRiskGapLens::class)->project($facts);

        return ['status' => 'ok', 'risk_gaps' => $r];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function snapshot(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        // Derive each sub-section and feed into the composer for one envelope.
        $inventory = $this->app()->make(AtlasSelfConstructionCortexSourceInventory::class)->inventory($facts);
        $freshness = $this->app()->make(AtlasSelfConstructionCortexFreshnessBridge::class)->adapt($facts);
        $risk = $this->app()->make(AtlasSelfConstructionCortexRiskGapLens::class)->project($facts);

        $sections = [
            'inventory' => $inventory,
            'freshness' => $freshness,
            'risk_gaps' => $risk,
            'queue_state' => is_array($facts['queue_state'] ?? null) ? $facts['queue_state'] : ['observed_at_unix' => 0],
            'task_coverage' => is_array($facts['task_coverage'] ?? null) ? $facts['task_coverage'] : ['observed_at_unix' => 0],
            'evidence_refs' => is_array($facts['evidence_refs'] ?? null) ? $facts['evidence_refs'] : ['observed_at_unix' => 0],
        ];
        $r = $this->app()->make(AtlasSelfConstructionCortexSnapshotComposer::class)->compose($sections);

        return ['status' => 'ok', 'snapshot' => $r];
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
