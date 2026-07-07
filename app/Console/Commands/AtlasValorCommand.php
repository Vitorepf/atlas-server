<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Instrumentation\AtlasValueMetricsService;
use Illuminate\Console\Command;

/**
 * B4 (fechamento ACOS) — `atlas valor`: reports the real Obra #17 instruments
 * (TPE = turns-até-1ª-edição, perguntas-ao-operador) COUNTED from session transcripts.
 * One session via --transcript, or a week's rollup via --dir (glob *.jsonl). No number
 * is fabricated — each is a count over the JSONL the harness already writes.
 */
final class AtlasValorCommand extends Command
{
    protected $signature = 'atlas:valor
        {--transcript= : path to one session transcript JSONL}
        {--dir= : directory of *.jsonl transcripts to roll up (a "valor semana")}
        {--json : machine-readable output}';

    protected $description = 'B4 · valor: TPE (turns-até-1ª-edição) + perguntas-ao-operador, medidos de transcripts reais';

    public function handle(AtlasValueMetricsService $metrics): int
    {
        $transcript = (string) ($this->option('transcript') ?? '');
        $dir = (string) ($this->option('dir') ?? '');

        if ($transcript === '' && $dir === '') {
            $this->error('informe --transcript=<arquivo.jsonl> ou --dir=<pasta com *.jsonl>');

            return self::FAILURE;
        }

        $files = [];
        if ($transcript !== '') {
            if (! is_file($transcript)) {
                $this->error("transcript não encontrado: {$transcript}");

                return self::FAILURE;
            }
            $files[] = $transcript;
        }
        if ($dir !== '') {
            if (! is_dir($dir)) {
                $this->error("pasta não encontrada: {$dir}");

                return self::FAILURE;
            }
            $files = array_merge($files, glob(rtrim($dir, '/').'/*.jsonl') ?: []);
        }

        $perSession = [];
        foreach ($files as $file) {
            $perSession[] = $metrics->metricsForTranscript($this->readLines($file));
        }

        $rollup = $metrics->rollup($perSession);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema' => 'atlas.value.v1',
                'sessions' => $perSession,
                'rollup' => $rollup,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('atlas valor — instrumentos Obra #17 (medição real)');
        $this->line(sprintf('  sessões: %d (com edição: %d)', $rollup['sessions'], $rollup['sessions_with_edit']));
        $this->line(sprintf('  TPE mediana (turns até 1ª edição): %s',
            $rollup['median_turns_to_first_edit'] === null ? 'n/a' : (string) $rollup['median_turns_to_first_edit']));
        $this->line(sprintf('  perguntas ao operador: %d total, %.2f/sessão',
            $rollup['total_clarifying_questions'], $rollup['avg_clarifying_questions']));
        $this->comment('  nota: "…correta" (edit landou) e "evitável" (resposta já na memória) exigem joins receipt/registry ainda não feitos — medido o sinal cru, não fabricado.');

        return self::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readLines(string $file): array
    {
        $out = [];
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return $out;
        }
        while (($raw = fgets($fh)) !== false) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        fclose($fh);

        return $out;
    }
}
