<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService;
use Illuminate\Console\Command;

/**
 * Atlas Code Forge Work Intake CLI.
 *
 * php artisan atlas:code:forge-intake --obra=<uuid> [--objective=... --business-rule=...
 *   --acceptance=... (repeat) --doc=... (repeat) --risk-level=...]
 *   [--show] [--json] [--strict]
 *
 * Strict exit non-zero quando readiness != ready ou faltar Obra.
 */
final class AtlasCodeForgeWorkIntakeCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:code:forge-intake
        {--obra= : UUID da Obra (obrigatorio)}
        {--objective= : Objetivo enterprise da Obra}
        {--business-rule= : Regra de negocio canonica}
        {--scope-in=* : Itens de scope_in}
        {--scope-out=* : Itens de scope_out}
        {--acceptance=* : Criterios de aceite}
        {--doc=* : Docs canonicas referenciadas}
        {--expected-output=* : Outputs esperados}
        {--constraint=* : Restricoes}
        {--operator-notes= : Notas do operador}
        {--risk-level= : low|medium|high|critical}
        {--show : Somente le o intake (nao persiste)}
        {--json : Imprime JSON canonico}
        {--strict : Exit non-zero quando readiness != ready}';

    protected $description = 'Atlas Code Forge Work Intake & Spec Governance v1 · CLI canonica.';

    public function handle(AtlasCodeForgeWorkIntakeService $service): int
    {
        $obraId = $this->stringOption('obra');
        if ($obraId === null) {
            $this->emit([
                'schema_version' => AtlasCodeForgeWorkIntakeService::SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'obra_required',
                'reason' => 'atlas:code:forge-intake exige --obra.',
                'external_provider_call' => false,
            ]);

            return $this->resolveExit('blocked');
        }

        $project = AtlasProject::query()->whereKey($obraId)->first();
        if ($project === null) {
            $this->emit([
                'schema_version' => AtlasCodeForgeWorkIntakeService::SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'obra_not_found',
                'obra_id' => $obraId,
                'external_provider_call' => false,
            ]);

            return $this->resolveExit('blocked');
        }

        if ((bool) $this->option('show')) {
            $intake = $service->get($project);
            $this->emit($intake);

            return $this->resolveExit((string) ($intake['readiness_status'] ?? 'blocked'));
        }

        $payload = array_filter([
            'objective' => $this->stringOption('objective'),
            'business_rule' => $this->stringOption('business-rule'),
            'scope_in' => $this->listOption('scope-in'),
            'scope_out' => $this->listOption('scope-out'),
            'acceptance_criteria' => $this->listOption('acceptance'),
            'canonical_docs' => $this->listOption('doc'),
            'expected_outputs' => $this->listOption('expected-output'),
            'constraints' => $this->listOption('constraint'),
            'operator_notes' => $this->stringOption('operator-notes'),
            'risk_level' => $this->stringOption('risk-level'),
        ], static fn (mixed $v): bool => $v !== null && $v !== []);

        // Quando nenhum campo eh passado, atualiza apenas readiness com dados existentes.
        if ($payload === []) {
            $intake = $service->get($project);
            $this->emit($intake);

            return $this->resolveExit((string) ($intake['readiness_status'] ?? 'blocked'));
        }

        $intake = $service->save($project, $payload);
        $this->emit($intake);

        return $this->resolveExit((string) ($intake['readiness_status'] ?? 'blocked'));
    }

    private function resolveExit(string $readiness): int
    {
        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return $readiness === 'ready' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Code Forge Work Intake</>', (string) ($payload['schema_version'] ?? '—'));
        $this->components->twoColumnDetail('Status', (string) ($payload['readiness_status'] ?? $payload['status'] ?? '—'));
        $this->components->twoColumnDetail('Obra', (string) ($payload['obra_id'] ?? '—'));
        $this->components->twoColumnDetail('Objective', $this->truncate((string) ($payload['objective'] ?? '—')));
        $this->components->twoColumnDetail('Business rule', $this->truncate((string) ($payload['business_rule'] ?? '—')));
        $this->components->twoColumnDetail('Acceptance', (string) count((array) ($payload['acceptance_criteria'] ?? [])));
        $this->components->twoColumnDetail('Canonical docs', (string) count((array) ($payload['canonical_docs'] ?? [])));
        $this->components->twoColumnDetail('Next action', (string) ($payload['next_action'] ?? '—'));
    }

    private function truncate(string $value, int $max = 60): string
    {
        if ($value === '') return '—';

        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max - 1).'…';
    }


    /**
     * @return list<string>
     */
    private function listOption(string $key): array
    {
        $value = $this->option($key);
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
