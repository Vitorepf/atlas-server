<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Obra\AtlasSpecCritiqueService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * WO-17-T2 — critique a spec against the brain BEFORE implementation (P3, no LLM).
 *
 *   atlas:spec:critique docs/work-orders/some-spec.md
 *   cat spec.md | atlas:spec:critique -
 *
 * Surfaces refutations the spec walks into + governed decisions it touches. Exits 2
 * when concerns are found (a spec that collides with a refutation), so it can gate a
 * pipeline; --json for machine consumption.
 */
final class AtlasSpecCritiqueCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:spec:critique
        {spec : path to a spec file, or "-" to read stdin}
        {--workspace= : scope id (default: atlas-server)}
        {--json : machine-readable output}';

    protected $description = 'Deterministic adversarial spec critique — flag refutations + governed decisions a spec touches, before implementation.';

    public function handle(AtlasSpecCritiqueService $service): int
    {
        $arg = (string) $this->argument('spec');
        $text = $arg === '-' ? (string) file_get_contents('php://stdin') : (is_file($arg) ? (string) file_get_contents($arg) : '');
        if (trim($text) === '') {
            $this->warn('Spec vazia ou arquivo inexistente: '.$arg);

            return self::SUCCESS;
        }

        $workspace = trim((string) $this->option('workspace')) ?: 'atlas-server';
        $critique = $service->critique($text, $workspace);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($critique));

            return ($critique['concerns_count'] ?? 0) > 0 ? 2 : self::SUCCESS;
        }

        $this->info('Crítica de spec: '.$critique['verdict']);
        foreach ((array) $critique['concerns'] as $c) {
            $this->error('⚠️ COLISÃO com refutação: '.$c);
        }
        foreach ((array) $critique['refutations'] as $r) {
            $this->line('- refutação relevante: '.$r);
        }
        foreach ((array) $critique['decisions'] as $d) {
            $this->line('- decisão governante: '.$d);
        }

        return ($critique['concerns_count'] ?? 0) > 0 ? 2 : self::SUCCESS;
    }
}
