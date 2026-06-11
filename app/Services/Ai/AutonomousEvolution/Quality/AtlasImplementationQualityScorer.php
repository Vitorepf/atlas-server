<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quality;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasP3FindingDispatcher;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FinalDeliveryQualityGateService;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * O QUALITY SCORER — o escalar determinístico de qualidade de código entregue (AP-820).
 *
 * Calcula a nota SEMPRE do lado canônico, tratando o workspace candidato como DADO:
 * nunca dá boot na app do candidato (esse seria o furo fatal de injeção — código do
 * candidato executando dentro do processo que o avalia). Segue o padrão P3 já provado
 * ({@see AtlasP3FindingDispatcher}): o
 * tooling do REPO canônico (phpstan/pint do vendor canônico) roda CONTRA o caminho do
 * workspace, em processo separado, com timeout.
 *
 * Restrições de projeto:
 *  - Nenhum input vem do chamador além do caminho do workspace; tudo é recomputado
 *    do git do próprio workspace (anti-gaming: o candidato não escolhe o que é medido).
 *  - phpstan é medido em DELTA (workspace vs baseline HEAD extraída via `git show`),
 *    então ruído de ambiente (unknown classes por autoload) cancela por construção.
 *  - Supressões adicionadas (`@phpstan-ignore`/`@psalm-suppress`) contam CONTRA o
 *    candidato — guarda anti-gaming da revisão adversarial.
 *  - Gates duros (nota 0.0): marker de entrega não-final NOVO em código de produto
 *    (reusa {@see FinalDeliveryQualityGateService}, baseline-aware), vocabulário
 *    proibido nas linhas ADICIONADAS do diff (reusa as constantes do
 *    {@see AtlasConstitutionalKernelService} — nunca declara lista própria), e
 *    workspace que não é um repo git utilizável.
 *  - Outage de tooling NUNCA trava o ratchet: phpstan indisponível => fail-open para
 *    o componente (delta nulo, nota neutra no eixo phpstan) com nota de honestidade
 *    em `notes`; pint indisponível => contribui 0 com nota em `notes`.
 *  - No-op (sem mudanças) = exatamente 0.5 (neutro). cwd das ferramentas é um temp
 *    dir privado para que um phpstan.neon/pint.json escrito PELO candidato nunca
 *    seja auto-carregado (outro vetor de gaming fechado).
 */
final class AtlasImplementationQualityScorer
{
    public const SCHEMA_VERSION = 'atlas.loop.implementation_quality.v1';

    public const NEUTRAL = 0.5;

    public const REASON_WORKSPACE_NOT_GIT = 'workspace_not_git';

    public const REASON_PROHIBITED_VOCABULARY = 'prohibited_vocabulary';

    /** Timeout por execução de ferramenta (phpstan/pint), em segundos. */
    private const TOOL_TIMEOUT_SECONDS = 60.0;

    /** Linhas adicionadas que suprimem análise estática contam contra o candidato. */
    private const SUPPRESSION_PATTERN = '/@phpstan-ignore|@psalm-suppress/';

    public function __construct(
        private readonly FinalDeliveryQualityGateService $finalDeliveryGate,
    ) {}

    /**
     * Pontua um workspace candidato. Determinístico, canônico-lado, sem boot do candidato.
     *
     * @return array{
     *     schema_version: string,
     *     score: float,
     *     neutral: float,
     *     components: array{phpstan_delta:int|null, phpstan_workspace_errors:int|null, phpstan_baseline_errors:int|null, ignore_suppressions_added:int, pint_dirty:int},
     *     gates: array{markers_clean:bool, vocab_clean:bool, workspace_is_git:bool, has_changes:bool},
     *     changed_files: list<string>,
     *     hard_zero_reason: string|null,
     *     notes: list<string>
     * }
     */
    public function score(string $workspace): array
    {
        $workspace = rtrim(trim($workspace), '/');

        if ($workspace === '' || ! is_dir($workspace) || ! $this->hasGitHead($workspace)) {
            return $this->result(0.0, $this->components(), [
                'markers_clean' => true,
                'vocab_clean' => true,
                'workspace_is_git' => false,
                'has_changes' => false,
            ], [], self::REASON_WORKSPACE_NOT_GIT);
        }

        [$changed, $untracked] = $this->changedFiles($workspace);
        if ($changed === []) {
            // No-op: neutro EXATO por contrato (0.5), nenhuma ferramenta roda.
            return $this->result(self::NEUTRAL, $this->components(), [
                'markers_clean' => true,
                'vocab_clean' => true,
                'workspace_is_git' => true,
                'has_changes' => false,
            ], [], null);
        }

        $addedLines = $this->addedLines($workspace, $untracked);

        // Gate duro 1 — markers de entrega não-final (baseline-aware: só marker NOVO bloqueia).
        $markersClean = $this->markersClean($workspace, $changed);

        // Gate duro 2 — vocabulário proibido nas linhas adicionadas (constantes do kernel).
        $vocabClean = $this->vocabClean($addedLines);

        $gates = [
            'markers_clean' => $markersClean,
            'vocab_clean' => $vocabClean,
            'workspace_is_git' => true,
            'has_changes' => true,
        ];

        if (! $markersClean || ! $vocabClean) {
            $reason = ! $markersClean ? FinalDeliveryQualityGateService::BLOCKER : self::REASON_PROHIBITED_VOCABULARY;

            return $this->result(0.0, $this->components(), $gates, $changed, $reason, ['analysis_skipped_hard_zero']);
        }

        $notes = [];
        $suppressions = $this->countSuppressions($addedLines);

        // Delta phpstan: tooling canônico contra workspace E baseline extraída do git
        // do workspace. cwd = temp dir privado (config do candidato nunca é carregada).
        $runRoot = sys_get_temp_dir().'/atlas-quality-score-'.bin2hex(random_bytes(4));
        @mkdir($runRoot, 0o755, true);

        try {
            [$workspaceErrors, $baselineErrors] = $this->phpstanErrorCounts($workspace, $changed, $runRoot);
            $phpstanSkipped = $workspaceErrors === null || $baselineErrors === null;
            if ($phpstanSkipped) {
                // Fail-open honesto: o ratchet nunca bloqueia por outage de tooling,
                // mas o relatório registra que o eixo phpstan não foi medido.
                $workspaceErrors = null;
                $baselineErrors = null;
                $notes[] = 'phpstan_skipped';
            }

            $pintDirty = $this->pintDirtyCount($workspace, $changed, $runRoot);
            if ($pintDirty === null) {
                $pintDirty = 0;
                $notes[] = 'pint_skipped';
            }
        } finally {
            if (is_dir($runRoot)) {
                (new Process(['rm', '-rf', $runRoot]))->run();
            }
        }

        $delta = ($workspaceErrors === null || $baselineErrors === null) ? null : $workspaceErrors - $baselineErrors;
        $deltaForScore = $delta ?? 0;
        $effectiveDelta = max(0, $deltaForScore) + $suppressions;
        $fixed = max(0, -$deltaForScore);

        $score = self::NEUTRAL
            - 0.10 * $effectiveDelta
            + 0.05 * min($fixed, 6)
            - 0.02 * $pintDirty;

        return $this->result($score, $this->components($delta, $workspaceErrors, $baselineErrors, $suppressions, $pintDirty), $gates, $changed, null, $notes);
    }

    /** Workspace utilizável = repo git COM HEAD (sem HEAD não existe baseline para o delta). */
    private function hasGitHead(string $workspace): bool
    {
        $process = new Process(['git', 'rev-parse', '--verify', 'HEAD'], $workspace, null, null, 30.0);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Censo de mudanças: tracked vs HEAD + untracked (honrando .gitignore), deduplicado.
     *
     * @return array{0: list<string>, 1: list<string>} [todos os mudados, só os untracked]
     */
    private function changedFiles(string $workspace): array
    {
        $tracked = $this->gitLines($workspace, ['git', 'diff', '--name-only', '--no-ext-diff', 'HEAD']);
        $untracked = $this->gitLines($workspace, ['git', 'ls-files', '--others', '--exclude-standard']);

        $all = [];
        foreach ([...$tracked, ...$untracked] as $file) {
            $all[$file] = true;
        }
        $files = array_keys($all);
        sort($files);

        return [array_values($files), $untracked];
    }

    /**
     * Linhas ADICIONADAS pelo candidato: diff unified-0 contra HEAD + conteúdo integral
     * dos untracked (um arquivo novo é 100% linhas adicionadas — fecha o desvio de
     * esconder vocabulário/supressão em arquivo novo).
     *
     * @param  list<string>  $untracked
     * @return list<string>
     */
    private function addedLines(string $workspace, array $untracked): array
    {
        $lines = [];
        $process = new Process(['git', 'diff', '--no-ext-diff', '--unified=0', 'HEAD'], $workspace, null, null, 30.0);
        $process->run();
        foreach (preg_split('/\R/', (string) $process->getOutput()) ?: [] as $line) {
            if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $lines[] = substr($line, 1);
            }
        }

        foreach ($untracked as $rel) {
            $abs = $workspace.'/'.$rel;
            if (! is_file($abs) || (int) @filesize($abs) > 524_288) {
                continue;
            }
            $content = (string) @file_get_contents($abs);
            if ($content === '' || str_contains($content, "\0")) {
                continue; // binário/vazio não entra no censo textual
            }
            foreach (preg_split('/\R/', $content) ?: [] as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Gate de markers: conteúdo do workspace vs conteúdo da baseline (git show HEAD:path);
     * o serviço reutilizado já é baseline-aware (só marker NOVO bloqueia) e exime testes.
     *
     * @param  list<string>  $changed
     */
    private function markersClean(string $workspace, array $changed): bool
    {
        $files = [];
        $baseline = [];
        foreach ($changed as $rel) {
            $abs = $workspace.'/'.$rel;
            $files[$rel] = is_file($abs) ? (string) @file_get_contents($abs) : '';
            $baseline[$rel] = $this->baselineContent($workspace, $rel) ?? '';
        }

        $assessment = $this->finalDeliveryGate->assess($files, $baseline);

        return (bool) ($assessment['final'] ?? false);
    }

    /**
     * Gate de vocabulário: reusa as constantes do kernel constitucional (nunca uma lista
     * própria). Claims casam por palavra inteira; vocab por substring — mesma semântica
     * do próprio kernel.
     *
     * @param  list<string>  $addedLines
     */
    private function vocabClean(array $addedLines): bool
    {
        $haystack = mb_strtolower(implode("\n", $addedLines));
        if ($haystack === '') {
            return true;
        }

        foreach (AtlasConstitutionalKernelService::PROHIBITED_VOCAB as $bad) {
            if (str_contains($haystack, $bad)) {
                return false;
            }
        }
        foreach (AtlasConstitutionalKernelService::PROHIBITED_CLAIMS as $bad) {
            if (preg_match('/\b'.preg_quote($bad, '/').'\b/u', $haystack) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $addedLines
     */
    private function countSuppressions(array $addedLines): int
    {
        $count = 0;
        foreach ($addedLines as $line) {
            if (preg_match(self::SUPPRESSION_PATTERN, $line) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Conta erros phpstan dos arquivos .php mudados, nas versões workspace e baseline.
     * Arquivo novo no candidato não tem lado baseline (contribui 0 lá, por contrato).
     *
     * @param  list<string>  $changed
     * @return array{0: int|null, 1: int|null} [erros workspace, erros baseline]; null = tooling indisponível
     */
    private function phpstanErrorCounts(string $workspace, array $changed, string $runRoot): array
    {
        $phpFiles = array_values(array_filter($changed, static fn (string $f): bool => str_ends_with(strtolower($f), '.php')));
        if ($phpFiles === []) {
            return [0, 0];
        }

        $workspaceTargets = [];
        foreach ($phpFiles as $rel) {
            $abs = $workspace.'/'.$rel;
            if (is_file($abs)) {
                $workspaceTargets[] = $abs;
            }
        }

        $baselineTargets = [];
        $baselineRoot = $runRoot.'/baseline';
        foreach ($phpFiles as $rel) {
            $content = $this->baselineContent($workspace, $rel);
            if ($content === null) {
                continue; // novo no candidato: lado baseline contribui 0
            }
            $abs = $baselineRoot.'/'.$rel;
            @mkdir(dirname($abs), 0o755, true);
            file_put_contents($abs, $content);
            $baselineTargets[] = $abs;
        }

        $workspaceErrors = $workspaceTargets === [] ? 0 : $this->runPhpstan($workspaceTargets, $runRoot);
        $baselineErrors = $baselineTargets === [] ? 0 : $this->runPhpstan($baselineTargets, $runRoot);

        return [$workspaceErrors, $baselineErrors];
    }

    /**
     * Roda o phpstan CANÔNICO contra caminhos absolutos, como processo externo, com
     * timeout. cwd = temp dir privado: nenhuma config do candidato é auto-carregada.
     *
     * @param  list<string>  $targets
     * @return int|null total de file_errors; null = timeout/crash/parse (tooling indisponível)
     */
    private function runPhpstan(array $targets, string $cwd): ?int
    {
        $argv = [
            PHP_BINARY,
            base_path('vendor/bin/phpstan'),
            'analyse',
            '--level=5',
            '--no-progress',
            '--error-format=json',
            '--memory-limit=512M',
            '--autoload-file='.base_path('vendor/autoload.php'),
            ...$targets,
        ];

        try {
            $process = new Process($argv, $cwd, null, null, self::TOOL_TIMEOUT_SECONDS);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        $exit = $process->getExitCode() ?? -1;
        if ($exit !== 0 && $exit !== 1) {
            return null; // 0 = limpo, 1 = erros encontrados; resto = falha de tooling
        }

        try {
            $report = json_decode((string) $process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($report)) {
            return null;
        }

        return (int) ($report['totals']['file_errors'] ?? 0);
    }

    /**
     * pint --test canônico sobre os .php mudados que existem no workspace.
     *
     * @param  list<string>  $changed
     * @return int|null quantidade de arquivos fora do estilo; null = tooling indisponível
     */
    private function pintDirtyCount(string $workspace, array $changed, string $cwd): ?int
    {
        $targets = [];
        foreach ($changed as $rel) {
            $abs = $workspace.'/'.$rel;
            if (str_ends_with(strtolower($rel), '.php') && is_file($abs)) {
                $targets[] = $abs;
            }
        }
        if ($targets === []) {
            return 0;
        }

        try {
            $process = new Process([PHP_BINARY, base_path('vendor/bin/pint'), '--test', ...$targets], $cwd, null, null, self::TOOL_TIMEOUT_SECONDS);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        $exit = $process->getExitCode() ?? -1;
        if ($exit === 0) {
            return 0;
        }
        if ($exit !== 1) {
            return null;
        }

        $report = json_decode((string) $process->getOutput(), true);
        if (is_array($report) && is_array($report['files'] ?? null)) {
            return max(1, count($report['files']));
        }

        return 1;
    }

    /** Conteúdo da versão HEAD de um caminho relativo, ou null quando não existe na baseline. */
    private function baselineContent(string $workspace, string $rel): ?string
    {
        $process = new Process(['git', 'show', 'HEAD:'.$rel], $workspace, null, null, 30.0);
        $process->run();

        return $process->isSuccessful() ? (string) $process->getOutput() : null;
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function gitLines(string $workspace, array $argv): array
    {
        $process = new Process($argv, $workspace, null, null, 30.0);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }

        $lines = [];
        foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return array{phpstan_delta:int|null, phpstan_workspace_errors:int|null, phpstan_baseline_errors:int|null, ignore_suppressions_added:int, pint_dirty:int}
     */
    private function components(?int $delta = 0, ?int $workspaceErrors = 0, ?int $baselineErrors = 0, int $suppressions = 0, int $pintDirty = 0): array
    {
        return [
            'phpstan_delta' => $delta,
            'phpstan_workspace_errors' => $workspaceErrors,
            'phpstan_baseline_errors' => $baselineErrors,
            'ignore_suppressions_added' => $suppressions,
            'pint_dirty' => $pintDirty,
        ];
    }

    /**
     * @param  array{phpstan_delta:int|null, phpstan_workspace_errors:int|null, phpstan_baseline_errors:int|null, ignore_suppressions_added:int, pint_dirty:int}  $components
     * @param  array{markers_clean:bool, vocab_clean:bool, workspace_is_git:bool, has_changes:bool}  $gates
     * @param  list<string>  $changedFiles
     * @param  list<string>  $notes
     * @return array<string,mixed>
     */
    private function result(float $score, array $components, array $gates, array $changedFiles, ?string $hardZeroReason, array $notes = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'score' => round(max(0.0, min(1.0, $score)), 4),
            'neutral' => self::NEUTRAL,
            'components' => $components,
            'gates' => $gates,
            'changed_files' => $changedFiles,
            'hard_zero_reason' => $hardZeroReason,
            'notes' => $notes,
        ];
    }
}
