<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasForgeContinuumCertificationService;
use Illuminate\Console\Command;

/**
 * Atlas Forge Continuum Certify CLI.
 *
 * Materializa a certificacao end-to-end do Atlas Forge Continuum OS:
 *   doc-mae -> Atlas Code Forge-only -> Obra -> Work Intake -> Atlas Decide
 *   -> Provider Topology -> Governed Fallback -> State Projection
 *   -> Desktop Cockpit UI -> Review/Completion -> Repair -> Evidence -> Rivals isolado.
 *
 * Fail-closed sem Obra em strict. Suporta simulacao de falha de provider para
 * validar reroute governado e blocker honesto `provider_capacity_exhausted`.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-continuum-os.md
 *      docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
 */
final class AtlasForgeContinuumCertifyCommand extends Command
{
    protected $signature = 'atlas:forge:continuum-certify
        {--obra= : UUID da Obra (opcional; obrigatorio em --strict)}
        {--simulate-provider-failure= : rate_limit|quota_exhausted|auth_failed|timeout|context_limit|model_unavailable|provider_error|insufficient_capability|provider_capacity_exhausted}
        {--strategy= : Override de estrategia (default one_shot_enterprise_default)}
        {--workspace= : Workspace root opcional para auditar artefatos}
        {--json : Imprime JSON canonico atlas.forge_continuum_certification.v1}
        {--strict : Exit non-zero se status nao for available ou available_without_obra_context}';

    protected $description = 'Atlas Forge Continuum OS certification (Provider Topology + Governed Fallback). Read-model; nunca chama provider externo.';

    public function handle(AtlasForgeContinuumCertificationService $service): int
    {
        $payload = $service->certify([
            'obra_id' => $this->stringOption('obra'),
            'simulate_provider_failure' => $this->stringOption('simulate-provider-failure'),
            'strategy' => $this->stringOption('strategy'),
            'workspace' => $this->stringOption('workspace'),
            'strict' => (bool) $this->option('strict'),
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
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Forge Continuum OS</>', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Obra', (string) ($payload['obra_id'] ?? '—'));
        $this->components->twoColumnDetail('Strict', $payload['strict'] ? 'yes' : 'no');

        $invariants = is_array($payload['invariants'] ?? null) ? $payload['invariants'] : [];
        foreach ($invariants as $name => $value) {
            $mark = $value === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->components->twoColumnDetail("· {$name}", $mark);
        }

        $blockers = is_array($payload['blockers'] ?? null) ? $payload['blockers'] : [];
        if ($blockers !== []) {
            $this->newLine();
            $this->components->bulletList($blockers);
        }

        $topology = is_array($payload['provider_topology'] ?? null) ? $payload['provider_topology'] : [];
        $roles = is_array($topology['roles'] ?? null) ? $topology['roles'] : [];
        if ($roles !== []) {
            $this->newLine();
            $this->components->info('Provider Topology Roles');
            foreach ($roles as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $label = sprintf(
                    '%-22s  %-12s  %s/%s',
                    (string) ($entry['role'] ?? '—'),
                    (string) ($entry['status'] ?? '—'),
                    (string) ($entry['provider'] ?? '—'),
                    (string) ($entry['model'] ?? '—'),
                );
                $this->line($label);
            }
        }

        $lastEvent = $topology['last_fallback_event'] ?? null;
        if (is_array($lastEvent)) {
            $this->newLine();
            $this->components->info('Last Fallback Event');
            $this->components->twoColumnDetail('failure', (string) ($lastEvent['failure_type'] ?? '—'));
            $this->components->twoColumnDetail('action', (string) ($lastEvent['action'] ?? '—'));
            $this->components->twoColumnDetail('blocker', (string) ($lastEvent['blocker'] ?? '—'));
            $this->components->twoColumnDetail('selected_fallback_role', (string) ($lastEvent['selected_fallback_role'] ?? '—'));
            $this->components->twoColumnDetail('silent', $lastEvent['silent'] === true ? 'yes' : 'no');
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveExit(array $payload, bool $strict): int
    {
        $status = (string) ($payload['status'] ?? '');

        if (! $strict) {
            return self::SUCCESS;
        }

        $okStatuses = [
            AtlasForgeContinuumCertificationService::STATUS_AVAILABLE,
        ];

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
