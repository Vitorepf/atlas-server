<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationService;
use Illuminate\Console\Command;

/**
 * Atlas Forge Governed Provider Invocation CLI.
 *
 * Plan-by-default. Execute only with `--confirm-provider-call`,
 * `--confirm-budget` and `--confirm-runtime-dispatch`. NEVER calls an external
 * provider without these flags + a configured runtime driver.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
 */
final class AtlasForgeProviderInvokeCommand extends Command
{
    protected $signature = 'atlas:forge:provider-invoke
        {--obra= : UUID da Obra (obrigatorio em --strict)}
        {--role=primary_builder : Role canonica}
        {--mode=dry_run : dry_run | execute}
        {--dispatch= : Dispatch id especifico (default: ultimo plan da Obra)}
        {--confirm-provider-call : Aprova invocacao de provider (necessario em execute)}
        {--confirm-budget : Aprova budget para provider externo (necessario em execute se driver chama provider externo)}
        {--confirm-runtime-dispatch : Confirma uso do runtime dispatch plan vigente}
        {--timeout=120 : Timeout em segundos (1..3600)}
        {--max-output-chars=12000 : Limite de excerpt para output capturado}
        {--json : Imprime JSON canonico atlas.forge.provider_invocation.v1}
        {--strict : Exit non-zero se status nao for planned (dry_run) ou executed (execute)}';

    protected $description = 'Atlas Forge Governed Provider Invocation · plan-only por padrao; execute exige aprovacao explicita.';

    public function handle(AtlasForgeProviderInvocationService $service): int
    {
        $payload = $service->invoke([
            'obra_id' => $this->stringOption('obra'),
            'role' => $this->stringOption('role'),
            'mode' => $this->stringOption('mode'),
            'dispatch_id' => $this->stringOption('dispatch'),
            'confirm_provider_call' => (bool) $this->option('confirm-provider-call'),
            'confirm_budget' => (bool) $this->option('confirm-budget'),
            'confirm_runtime_dispatch' => (bool) $this->option('confirm-runtime-dispatch'),
            'timeout_seconds' => (int) $this->option('timeout'),
            'max_output_chars' => (int) $this->option('max-output-chars'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->renderHuman($payload);
        }

        return $this->resolveExit($payload, (bool) $this->option('strict'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Forge Provider Invocation</>', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('Obra', (string) ($payload['obra_id'] ?? '—'));
        $this->components->twoColumnDetail('Invocation id', (string) ($payload['invocation_id'] ?? '—'));
        $this->components->twoColumnDetail('Role', (string) ($payload['role'] ?? '—'));
        $this->components->twoColumnDetail('Provider/Model', sprintf('%s / %s', (string) ($payload['provider'] ?? '—'), (string) ($payload['model'] ?? '—')));
        $this->components->twoColumnDetail('Operator confirmed', $payload['operator_confirmed_provider_call'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Budget approved', $payload['budget_approved'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Provider called', $payload['provider_called'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('External provider call', $payload['external_provider_call'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Provider tokens spent', $payload['provider_tokens_spent'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Completion claim promoted', $payload['completion_claim_promoted'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Next action', (string) ($payload['next_action'] ?? '—'));

        $blockers = is_array($payload['blockers'] ?? null) ? $payload['blockers'] : [];
        if ($blockers !== []) {
            $this->newLine();
            $this->components->bulletList($blockers);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveExit(array $payload, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }

        $status = (string) ($payload['status'] ?? '');
        $mode = (string) ($payload['mode'] ?? '');
        $okStatuses = $mode === AtlasForgeProviderInvocationService::MODE_EXECUTE
            ? [AtlasForgeProviderInvocationService::STATUS_EXECUTED]
            : [AtlasForgeProviderInvocationService::STATUS_PLANNED];

        return in_array($status, $okStatuses, true) ? self::SUCCESS : self::FAILURE;
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
