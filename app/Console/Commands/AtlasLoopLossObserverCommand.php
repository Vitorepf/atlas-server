<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopLossObserverService;
use Illuminate\Console\Command;

/**
 * L4-4 · Loss observer: daily autopsy of repeated Loop rejection reasons.
 */
final class AtlasLoopLossObserverCommand extends Command
{
    protected $signature = 'atlas:loop:loss-observer
        {--campaign= : Escopar a um campaign_id (default: todos)}
        {--window-hours= : Janela de observação em horas (default: config)}
        {--min-occurrences= : Ocorrências mínimas para agir (default: config)}
        {--manifest-path= : Path explícito do manifesto (default: storage/app/atlas/loop/backlog-intents.json)}
        {--dry-run : Detecta e imprime ações sem escrever no manifesto}
        {--json : Saída JSON canônica}';

    protected $description = 'Detecta padrões dominantes de perda/rejeição do Loop e enfileira intents de backlog dedupados.';

    public function handle(AtlasLoopLossObserverService $observer): int
    {
        $result = $observer->observe(
            trim((string) $this->option('campaign')) ?: null,
            array_filter([
                'window_hours' => $this->intOption('window-hours'),
                'min_occurrences' => $this->intOption('min-occurrences'),
                'manifest_path' => $this->stringOption('manifest-path'),
                'write' => ! (bool) $this->option('dry-run'),
            ], static fn (mixed $v): bool => $v !== null),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info('Loop loss observer');
        $this->components->twoColumnDetail('Status', (string) $result['status']);
        $this->components->twoColumnDetail('Window hours', (string) $result['window_hours']);
        $this->components->twoColumnDetail('Dominant patterns', (string) $result['dominant_count']);
        $this->components->twoColumnDetail('Actions', (string) $result['actions_count']);
        foreach ((array) $result['dominant_patterns'] as $pattern) {
            $this->line(sprintf(
                '- %s · %dx · %s',
                (string) ($pattern['reason'] ?? 'unknown'),
                (int) ($pattern['occurrences'] ?? 0),
                (string) ($pattern['target_path'] ?? 'no-target'),
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
