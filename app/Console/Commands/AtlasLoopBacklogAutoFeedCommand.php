<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBacklogAutoFeederService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;

/**
 * L4-2 · Daily auto-feeder for the Loop backlog manifest.
 */
final class AtlasLoopBacklogAutoFeedCommand extends Command
{
    protected $signature = 'atlas:loop:backlog-feed
        {--campaign= : Escopar a um campaign_id (default: todos)}
        {--window-hours= : Janela de sinais em horas (default: config)}
        {--min-signal-count= : Sinais mínimos por intent (default: config)}
        {--max-items= : Máximo de intents por execução (default: config)}
        {--manifest-path= : Path explícito do manifesto (default: storage/app/atlas/loop/backlog-intents.json)}
        {--dry-run : Detecta e imprime ações sem escrever no manifesto}
        {--json : Saída JSON canônica}';

    protected $description = 'Auto-alimenta o manifesto de backlog do Loop a partir de sinais reais dedupados.';

    public function handle(AtlasLoopBacklogAutoFeederService $feeder): int
    {
        // §0 MASTER SWITCH — fail-closed gate at the very top, before any signal query / manifest write. OFF ⇒
        // zero respawn vector (master-OFF must mean the backlog never auto-refills). Clean SUCCESS no-op so a
        // scheduler/watchdog invocation never escalates. Mirrors AtlasLoopCampaignCommand's §0 gate.
        if (! AtlasLoopMasterSwitch::enabled()) {
            $this->line((string) json_encode(
                ['status' => 'master_switch_off', 'message' => 'master_switch_off:backlog-feed:skipped'],
                JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $result = $feeder->feed(
            trim((string) $this->option('campaign')) ?: null,
            array_filter([
                'window_hours' => $this->intOption('window-hours'),
                'min_signal_count' => $this->intOption('min-signal-count'),
                'max_items' => $this->intOption('max-items'),
                'manifest_path' => $this->stringOption('manifest-path'),
                'write' => ! (bool) $this->option('dry-run'),
            ], static fn (mixed $v): bool => $v !== null),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info('Loop backlog auto-feed');
        $this->components->twoColumnDetail('Status', (string) $result['status']);
        $this->components->twoColumnDetail('Candidates', (string) $result['candidate_count']);
        $this->components->twoColumnDetail('Actions', (string) $result['actions_count']);
        $this->components->twoColumnDetail('Enqueued', (string) $result['enqueued_count']);
        foreach ((array) $result['actions'] as $action) {
            $item = (array) ($action['item'] ?? []);
            $this->line(sprintf(
                '- %s · %s · %s',
                (string) ($action['status'] ?? 'unknown'),
                (string) ($item['source'] ?? 'unknown'),
                (string) ($item['path'] ?? 'no-target'),
            ));
        }

        return self::SUCCESS;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }

    private function stringOption(string $key): ?string
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw !== '' ? $raw : null;
    }
}
