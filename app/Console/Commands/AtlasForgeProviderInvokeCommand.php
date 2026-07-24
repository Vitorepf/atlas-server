<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Concerns\EmitsCanonicalJson;
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
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:forge:provider-invoke
        {--obra= : UUID da Obra (obrigatorio em --strict)}
        {--role=primary_builder : Role canonica}
        {--mode=dry_run : dry_run | execute}
        {--dispatch= : Dispatch id especifico (default: ultimo plan da Obra)}
        {--confirm-provider-call : Aprova invocacao de provider (necessario em execute)}
        {--confirm-budget : Aprova budget para provider externo (necessario em execute se driver chama provider externo)}
        {--confirm-runtime-dispatch : Confirma uso do runtime dispatch plan vigente}
        {--driver-status : Imprime apenas status dos drivers (nao chama provider)}
        {--plan-driver : Imprime driver plan packet (nao chama provider)}
        {--provider-timeout=120 : Timeout do driver runtime em segundos (1..3600); alias de --timeout}
        {--timeout=120 : Timeout em segundos (1..3600)}
        {--max-output-chars=12000 : Limite de excerpt para output capturado}
        {--redact-output=1 : Redact secrets do excerpt antes de imprimir (default true)}
        {--json : Imprime JSON canonico}
        {--strict : Exit non-zero se status nao for planned (dry_run) ou executed (execute)}';

    protected $description = 'Atlas Forge Governed Provider Invocation · plan-only por padrao; execute exige aprovacao explicita.';

    public function handle(
        AtlasForgeProviderInvocationService $service,
        \App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter $router,
    ): int {
        // Sub-mode: --driver-status (no provider call ever)
        if ((bool) $this->option('driver-status')) {
            $status = $router->driverStatus();
            if ((bool) $this->option('json')) {
                $this->line($this->encode($status));
            } else {
                foreach ((array) ($status['drivers'] ?? []) as $entry) {
                    $this->components->twoColumnDetail(
                        (string) ($entry['provider'] ?? '—'),
                        sprintf('configured=%s runtime=%s auth=%s', $entry['configured'] ? 'yes' : 'no', $entry['runtime_present'] ? 'yes' : 'no', (string) ($entry['auth_state'] ?? '—')),
                    );
                }
            }

            return self::SUCCESS;
        }

        // Sub-mode: --plan-driver (no provider call ever, returns driver plan packet)
        if ((bool) $this->option('plan-driver')) {
            $obraId = $this->stringOption('obra');
            if ($obraId === null && (bool) $this->option('strict')) {
                $payload = ['status' => 'blocked', 'blocker' => 'obra_required', 'note' => 'plan-driver --strict requires --obra'];
                $this->line($this->encode($payload));

                return self::FAILURE;
            }
            $payload = $service->invoke([
                'obra_id' => $obraId,
                'role' => $this->stringOption('role'),
                'mode' => 'dry_run',
                'dispatch_id' => $this->stringOption('dispatch'),
                'timeout_seconds' => $this->resolveTimeout(),
                'max_output_chars' => (int) $this->option('max-output-chars'),
            ]);
            $payload['driver_status'] = $router->driverStatus($payload['provider'] ?? null);
            $payload['driver_plan'] = $router->driverPlan($payload['provider'] ?? null, [
                'model' => $payload['model'] ?? null,
                'cwd' => null,
            ]);
            if ((bool) $this->option('json')) {
                $this->line($this->encode($payload));
            } else {
                $this->renderHuman($payload);
            }

            return $this->resolveExit($payload, (bool) $this->option('strict'));
        }

        $payload = $service->invoke([
            'obra_id' => $this->stringOption('obra'),
            'role' => $this->stringOption('role'),
            'mode' => $this->stringOption('mode'),
            'dispatch_id' => $this->stringOption('dispatch'),
            'confirm_provider_call' => (bool) $this->option('confirm-provider-call'),
            'confirm_budget' => (bool) $this->option('confirm-budget'),
            'confirm_runtime_dispatch' => (bool) $this->option('confirm-runtime-dispatch'),
            'timeout_seconds' => $this->resolveTimeout(),
            'max_output_chars' => (int) $this->option('max-output-chars'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->renderHuman($payload);
        }

        return $this->resolveExit($payload, (bool) $this->option('strict'));
    }

    private function resolveTimeout(): int
    {
        $providerTimeout = (int) $this->option('provider-timeout');
        $timeout = (int) $this->option('timeout');

        return $providerTimeout > 0 && $providerTimeout !== 120 ? $providerTimeout : $timeout;
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


}
