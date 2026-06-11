<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Quality\AtlasImplementationQualityScorer;
use Illuminate\Console\Command;

/**
 * Superfície CLI do quality scorer (AP-820), com DISCIPLINA DE STDOUT: imprime
 * EXATAMENTE UMA linha `QUALITY_SCORE=<0.xxxx>` — sem banner, sem tabela — para que
 * um frozen-judge com metric_pattern /QUALITY_SCORE=([0-9.]+)/ extraia a métrica
 * (fase 2). Erros de uso vão para stderr; o relatório completo só sai via --report.
 *
 * Exit 0 sempre que houve pontuação (inclusive 0.0 de gate duro); exit 1 SOMENTE
 * em erro de uso (workspace ausente/inválido ou --report não gravável).
 */
final class AtlasLoopQualityScoreCommand extends Command
{
    protected $signature = 'atlas:loop:quality-score
        {--workspace= : Candidate workspace path}
        {--report= : Optional path to write the full JSON report}';

    protected $description = 'Score the delivered-code quality of a candidate workspace (deterministic, canonical-side; never boots the candidate app).';

    public function handle(AtlasImplementationQualityScorer $scorer): int
    {
        $workspace = rtrim(trim((string) ($this->option('workspace') ?? '')), '/');
        if ($workspace === '' || ! is_dir($workspace)) {
            $this->output->getErrorStyle()->writeln('Missing or invalid --workspace.');

            return self::FAILURE;
        }

        $report = $scorer->score($workspace);

        $reportPath = trim((string) ($this->option('report') ?? ''));
        if ($reportPath !== '') {
            $dir = dirname($reportPath);
            if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                $this->output->getErrorStyle()->writeln('Cannot create report directory: '.$dir);

                return self::FAILURE;
            }
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || @file_put_contents($reportPath, $json.PHP_EOL) === false) {
                $this->output->getErrorStyle()->writeln('Cannot write report to: '.$reportPath);

                return self::FAILURE;
            }
        }

        // Contrato de stdout: uma linha, nada mais.
        $this->output->writeln(sprintf('QUALITY_SCORE=%.4f', (float) $report['score']));

        return self::SUCCESS;
    }
}
