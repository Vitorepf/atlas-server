<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiHarnessGovernanceAndQualityService;
use Illuminate\Console\Command;
use Throwable;

/**
 * AI Harness governance admission decider CLI.
 *
 *   php artisan atlas:aaeos:ai-harness-governance-and-quality
 *     [--obra=obra-123]                         // envelope.obra_id (a demo envelope is built from defaults)
 *     [--domain=technical]
 *     [--task-type=code|writing|research|long_work|...]
 *     [--risk=normal|sensitive]
 *     [--missing=section,deadline]              // drop these envelope fields to simulate loose chat
 *     [--checkpoint=financial_action,scope_change]
 *     [--model=local-7b] [--local]              // provider.model / provider.is_local
 *     [--providers=claude,codex]                // multi-provider participants
 *     [--workspace-bound]                       // already coordinated via the shared workspace
 *     [--json]
 *
 * Read-only, deterministic. Emits the admit|clarify|route_workspace|checkpoint
 * decision plus an AI-session-trace skeleton + receipt.
 *
 * @see docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md
 */
class AtlasAiHarnessGovernanceAndQualityCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-harness-governance-and-quality
        {--obra= : envelope.obra_id}
        {--domain= : envelope.domain (technical|strategic|educational|academic|...)}
        {--task-type= : envelope.task_type (code|writing|research|long_work|...)}
        {--risk= : envelope.risk (normal|sensitive)}
        {--missing= : comma-separated required envelope fields to omit (simulate loose chat)}
        {--checkpoint= : comma-separated active human-checkpoint triggers}
        {--model= : provider model name}
        {--local : mark provider.is_local=true}
        {--providers= : comma-separated provider participants (>1 => multi-provider)}
        {--workspace-bound : action already coordinated via Obras Shared Workspace}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Obras · AI harness admission decider (admit|clarify|route_workspace|checkpoint) for one Obra AI action.';

    public function handle(AtlasAiHarnessGovernanceAndQualityService $service): int
    {
        try {
            $envelope = [
                'obra_id' => $this->stringOption('obra', 'obra-demo'),
                'domain' => $this->stringOption('domain', 'technical'),
                'section' => 'root',
                'task_type' => $this->stringOption('task-type', 'writing'),
                'sources' => [],
                'decisions' => [],
                'deadline' => '2026-12-31',
                'quality_gate' => 'definition_of_done',
                'risk' => $this->stringOption('risk', 'normal'),
            ];

            // Optionally drop fields to demonstrate the loose-chat guard.
            foreach ($this->csvOption('missing') as $field) {
                unset($envelope[$field]);
            }

            $decision = $service->decide([
                'envelope' => $envelope,
                'checkpoint_flags' => $this->csvOption('checkpoint'),
                'provider' => [
                    'model' => $this->stringOption('model', 'unspecified'),
                    'is_local' => (bool) $this->option('local'),
                ],
                'providers' => $this->csvOption('providers'),
                'workspace_bound' => (bool) $this->option('workspace-bound'),
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'ai_harness_admission_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function stringOption(string $name, string $default): string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name): array
    {
        $value = $this->option($name);
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn ($v) => $v !== '',
        ));
    }
}
