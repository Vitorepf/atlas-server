<?php

namespace App\Console\Commands;

use App\Services\Ai\Mobile\AutoImprovementProposalScanner;
use App\Services\Ai\Mobile\InsightWatcherService;
use App\Services\Ai\Mobile\SelfDiagnosticEmitter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasInitiativesCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:initiatives
        {operation=list : list or run}
        {initiative? : refactor-scan, self-diagnostic, insight-watch}
        {--workspace= : Workspace/repo para refactor-scan}
        {--limit=3 : Maximo de findings/proposals em refactor-scan}
        {--dry-run : Forca execucao sem criar inbox item}
        {--emit : Permite criar inbox items}
        {--json : Imprime saida JSON}';

    protected $description = 'Lista e executa iniciativas autonomas do Atlas com dry-run seguro por padrao.';

    public function handle(
        AutoImprovementProposalScanner $scanner,
        SelfDiagnosticEmitter $diagnostics,
        InsightWatcherService $insights,
    ): int {
        $operation = Str::of((string) $this->argument('operation'))->lower()->replace('_', '-')->value();

        return match ($operation) {
            'list' => $this->listInitiatives(),
            'run' => $this->runInitiative($scanner, $diagnostics, $insights),
            default => $this->failWith("Operacao invalida: {$operation}. Use list ou run."),
        };
    }

    private function listInitiatives(): int
    {
        $items = [
            [
                'id' => 'refactor-scan',
                'description' => 'Varre repo/workspace e cria proposals seguras quando --emit for usado.',
                'default_schedule' => (string) config('atlas.mobile.proposal_scan.time', '06:30'),
                'enabled' => (bool) config('atlas.mobile.proposal_scan.enabled', false),
            ],
            [
                'id' => 'self-diagnostic',
                'description' => 'Compara qualidade recente contra baseline e emite self_diagnostic acima do threshold.',
                'default_schedule' => (string) config('atlas.mobile.self_diagnostic.time', '06:15'),
                'enabled' => (bool) config('atlas.mobile.self_diagnostic.enabled', false),
            ],
            [
                'id' => 'insight-watch',
                'description' => 'Observa saude, foco digital e provider health para emitir insights contextuais.',
                'default_schedule' => (string) config('atlas.mobile.insight_watch.time', '06:45'),
                'enabled' => (bool) config('atlas.mobile.insight_watch.enabled', false),
            ],
        ];

        return $this->printPayload(['initiatives' => $items]);
    }

    private function runInitiative(
        AutoImprovementProposalScanner $scanner,
        SelfDiagnosticEmitter $diagnostics,
        InsightWatcherService $insights,
    ): int {
        $initiative = $this->normalizeInitiative($this->argument('initiative'));
        if ($initiative === null) {
            return $this->failWith('Informe uma initiative: refactor-scan, self-diagnostic ou insight-watch.');
        }

        $emit = (bool) $this->option('emit');
        $dryRun = (bool) $this->option('dry-run') || ! $emit;

        $result = match ($initiative) {
            'refactor-scan' => $scanner->scan(
                $this->workspace(),
                ! $dryRun,
                max(1, (int) $this->option('limit')),
            ),
            'self-diagnostic' => $diagnostics->run($dryRun),
            'insight-watch' => $insights->run($dryRun),
            default => null,
        };

        if ($result === null) {
            return $this->failWith("Initiative invalida: {$initiative}.");
        }

        return $this->printPayload([
            'initiative' => $initiative,
            'dry_run' => $dryRun,
            'emits_inbox' => ! $dryRun,
            'result' => $result,
        ]);
    }

    private function workspace(): string
    {
        return (string) ($this->option('workspace')
            ?: config('atlas.mobile.proposal_scan.workspace')
            ?: config('atlas.ai.workdir')
            ?: base_path());
    }

    private function normalizeInitiative(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = Str::of($value)->lower()->replace('_', '-')->value();

        return match ($normalized) {
            'refactor', 'refactor-scan', 'proposal-scan', 'proposals-scan' => 'refactor-scan',
            'self', 'diagnostic', 'self-diagnostic', 'self-monitoring' => 'self-diagnostic',
            'insight', 'insights', 'insight-watch', 'watch-insights' => 'insight-watch',
            default => $normalized,
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function printPayload(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
