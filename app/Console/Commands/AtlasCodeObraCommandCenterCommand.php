<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasCodeObraCommandCenterService;
use Illuminate\Console\Command;

/**
 * Atlas Code Obra Command Center CLI.
 *
 * Tool de diagnostico que imprime o read-model canonico do Command Center
 * humano da Obra. Fail-closed sem Obra em --strict. Nunca chama provider real.
 *
 * Doc: docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
 */
final class AtlasCodeObraCommandCenterCommand extends Command
{
    protected $signature = 'atlas:code:obra-command-center
        {--obra= : UUID da Obra (obrigatorio em --strict)}
        {--json : Imprime JSON canonico atlas.code.obra_command_center.v1}
        {--strict : Exit non-zero quando status for no_obra/blocked}';

    protected $description = 'Atlas Code Obra Command Center · resumo humano canonico da Obra (lifecycle, decision inbox, trust).';

    public function handle(AtlasCodeObraCommandCenterService $service): int
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
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Code Obra Command Center</>', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Obra', (string) ($payload['obra_id'] ?? '—'));
        $this->components->twoColumnDetail('Titulo', (string) ($payload['obra_title'] ?? '—'));
        $this->components->twoColumnDetail('Human status', (string) ($payload['human_status_label'] ?? '—'));
        $this->components->twoColumnDetail('Current phase', (string) ($payload['current_phase'] ?? '—'));
        $this->components->twoColumnDetail('Next phase', (string) ($payload['next_phase'] ?? '—'));
        $this->components->twoColumnDetail('Primary action', (string) ($payload['primary_action_label'] ?? '—'));
        $this->components->twoColumnDetail('Next safe action', (string) ($payload['next_safe_action'] ?? '—'));
        $this->components->twoColumnDetail('Readiness', $this->percentLabel($payload, 'readiness_progress'));
        $this->components->twoColumnDetail('Proven delivery', $this->percentLabel($payload, 'proven_delivery_progress'));
        $this->components->twoColumnDetail('External provider call', $payload['external_provider_call'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Completion claim promoted', $payload['completion_claim_promoted'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Review gate preserved', $payload['review_gate_preserved'] ? 'yes' : 'no');

        $decisions = (array) ($payload['decision_inbox'] ?? []);
        if ($decisions !== []) {
            $this->newLine();
            $this->components->info('Decision Inbox:');
            foreach ($decisions as $d) {
                $this->components->twoColumnDetail(
                    (string) ($d['label'] ?? ''),
                    (string) ($d['recommended_action'] ?? ''),
                );
            }
        }
    }

    private function percentLabel(array $payload, string $key): string
    {
        $node = (array) ($payload[$key] ?? []);

        return sprintf('%d%% (%d/%d)', (int) ($node['percent'] ?? 0), (int) ($node['reached'] ?? 0), (int) ($node['total'] ?? 0));
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

        return in_array($status, [
            AtlasCodeObraCommandCenterService::STATUS_NO_OBRA,
            AtlasCodeObraCommandCenterService::STATUS_BLOCKED,
        ], true) ? self::FAILURE : self::SUCCESS;
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
