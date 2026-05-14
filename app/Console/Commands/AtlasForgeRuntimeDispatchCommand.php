<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasForgeRuntimeDispatchService;
use Illuminate\Console\Command;

/**
 * Atlas Forge Runtime Dispatch CLI.
 *
 * Prepara um runtime dispatch plan governado. NUNCA chama provider externo.
 * Fail-closed sem Obra/topology live/receipt/role. Suporta simulacao de falha
 * de provider e geracao de child Decision Receipt sob fallback.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
 */
final class AtlasForgeRuntimeDispatchCommand extends Command
{
    protected $signature = 'atlas:forge:runtime-dispatch
        {--obra= : UUID da Obra (obrigatorio em --strict)}
        {--role=primary_builder : Role canonica (primary_builder|critical_reviewer|context_scout|repair_agent|local_tool_runner)}
        {--simulate-provider-failure= : rate_limit|quota_exhausted|auth_failed|timeout|context_limit|model_unavailable|provider_error|insufficient_capability|provider_capacity_exhausted}
        {--create-child-receipt : Gera child Decision Receipt para fallback reroute}
        {--fast-path-run-id= : ULID do fast path run correlacionado}
        {--execution-mode=prepare_dispatch_plan : prepare_dispatch_plan (default; nunca executa provider real)}
        {--json : Imprime JSON canonico atlas.forge.runtime_dispatch_plan.v1}
        {--strict : Exit non-zero quando status nao for dispatch_planned}';

    protected $description = 'Atlas Forge Runtime Dispatcher · prepara dispatch plan governado sem chamar provider externo.';

    public function handle(AtlasForgeRuntimeDispatchService $service): int
    {
        $plan = $service->dispatch([
            'obra_id' => $this->stringOption('obra'),
            'role' => $this->stringOption('role'),
            'simulate_provider_failure' => $this->stringOption('simulate-provider-failure'),
            'create_child_receipt' => (bool) $this->option('create-child-receipt'),
            'fast_path_run_id' => $this->stringOption('fast-path-run-id'),
            'execution_mode' => $this->stringOption('execution-mode'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($plan));
        } else {
            $this->renderHuman($plan);
        }

        return $this->resolveExit($plan, (bool) $this->option('strict'));
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function renderHuman(array $plan): void
    {
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Forge Runtime Dispatcher</>', (string) $plan['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $plan['status']);
        $this->components->twoColumnDetail('Obra', (string) ($plan['obra_id'] ?? '—'));
        $this->components->twoColumnDetail('Dispatch id', (string) ($plan['dispatch_id'] ?? '—'));
        $this->components->twoColumnDetail('Decision receipt', (string) ($plan['decision_receipt_id'] ?? '—'));
        $this->components->twoColumnDetail('Decision source', (string) ($plan['decision_source'] ?? '—'));
        $this->components->twoColumnDetail('Role', (string) ($plan['role'] ?? '—'));
        $this->components->twoColumnDetail('Provider/Model', sprintf('%s / %s', (string) ($plan['provider'] ?? '—'), (string) ($plan['model'] ?? '—')));
        $this->components->twoColumnDetail('Runtime dispatch allowed', $plan['runtime_dispatch_allowed'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('External provider call', $plan['external_provider_call'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Completion claim promoted', $plan['completion_claim_promoted'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Next action', (string) ($plan['next_action'] ?? '—'));

        $childId = (string) ($plan['child_decision_receipt_id'] ?? '');
        if ($childId !== '') {
            $this->newLine();
            $this->components->info('Child Decision Receipt');
            $this->components->twoColumnDetail('child_decision_receipt_id', $childId);
            $this->components->twoColumnDetail('child_decision_receipt_hash', (string) ($plan['child_decision_receipt_hash'] ?? '—'));
            $this->components->twoColumnDetail('fallback_event_id', (string) ($plan['fallback_event_id'] ?? '—'));
            $this->components->twoColumnDetail('fallback_failure_type', (string) ($plan['fallback_failure_type'] ?? '—'));
        }

        $blockers = is_array($plan['blockers'] ?? null) ? $plan['blockers'] : [];
        if ($blockers !== []) {
            $this->newLine();
            $this->components->bulletList($blockers);
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function resolveExit(array $plan, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }

        return ((string) ($plan['status'] ?? '')) === AtlasForgeRuntimeDispatchService::STATUS_DISPATCH_PLANNED
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
