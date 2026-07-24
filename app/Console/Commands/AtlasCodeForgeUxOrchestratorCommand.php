<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Programming\AtlasCodeForgeUxOrchestratorService;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * Atlas Code Forge Human-First UX Orchestrator CLI.
 *
 * Diagnostic tool that prints the canonical UX state machine for an Obra.
 * Fail-closed sem Obra em --strict. NUNCA chama provider externo.
 *
 * Doc: docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
 */
final class AtlasCodeForgeUxOrchestratorCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:code:forge-ux
        {--obra= : UUID da Obra (obrigatorio em --strict)}
        {--json : Imprime JSON canonico atlas.code.forge_ux_orchestrator.v1}
        {--strict : Exit non-zero quando state for no_obra/blocked}';

    protected $description = 'Atlas Code Forge UX Orchestrator · imprime o estado humano da Obra (camada acima do runtime).';

    public function handle(AtlasCodeForgeUxOrchestratorService $service): int
    {
        $payload = $service->snapshot([
            'obra_id' => $this->stringOption('obra'),
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
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Code Forge UX</>', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('State', (string) $payload['state']);
        $this->components->twoColumnDetail('Obra', (string) ($payload['obra_id'] ?? '—'));
        $this->components->twoColumnDetail('Human status', (string) ($payload['human_status_label'] ?? '—'));
        $this->components->twoColumnDetail('Primary action', (string) ($payload['primary_action_label'] ?? '—'));
        $this->components->twoColumnDetail('Primary kind', (string) ($payload['primary_action_kind'] ?? '—'));
        $this->components->twoColumnDetail('Primary enabled', YesNo::format($payload['primary_action_enabled']));
        $this->components->twoColumnDetail('Next safe step', (string) ($payload['next_safe_step'] ?? '—'));
        $this->components->twoColumnDetail('External provider call', YesNo::format($payload['external_provider_call']));
        $this->components->twoColumnDetail('Completion claim promoted', YesNo::format($payload['completion_claim_promoted']));
        $this->components->twoColumnDetail('Progress', (string) ($payload['progress_percent'] ?? 0).'%');

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
        $state = (string) ($payload['state'] ?? '');

        return in_array($state, [
            AtlasCodeForgeUxOrchestratorService::STATE_NO_OBRA,
            AtlasCodeForgeUxOrchestratorService::STATE_BLOCKED,
        ], true) ? self::FAILURE : self::SUCCESS;
    }


}
