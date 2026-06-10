<?php

namespace App\Services\Ai\Mobile;

use App\Models\AtlasInitiativeRun;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class AutoImprovementProposalScanner
{
    /** @var array<int,string> */
    private array $excludedDirs = ['.git', 'node_modules', 'vendor', 'storage', 'bootstrap/cache', '.expo', 'dist', 'build', 'coverage'];

    /** @var array<int,string> */
    private array $extensions = ['php', 'ts', 'tsx', 'js', 'jsx', 'md'];

    public function __construct(private readonly ProposalInboxEmitter $proposals)
    {
    }

    /**
     * @return array{ok:bool,run_id:?string,workspace:string,dry_run:bool,findings:array<int,array<string,mixed>>,emitted_item_ids:array<int,string>,emitted_count:int}
     */
    public function scan(string $workspace, bool $emit = false, int $limit = 3): array
    {
        $workspace = $this->resolveWorkspace($workspace);
        $limit = max(1, min(10, $limit));
        $run = $this->startRun($workspace, $emit, $limit);

        try {
            $findings = collect([
                ...$this->explicitMarkerFindings($workspace),
                ...$this->largeServiceFindings($workspace),
            ])
                ->unique('dedupe_key')
                ->sortByDesc(fn (array $finding): float => (float) ($finding['confidence'] ?? 0))
                ->take($limit)
                ->values()
                ->all();

            $emitted = [];
            if ($emit) {
                foreach ($findings as $finding) {
                    $item = $this->proposals->emit([
                        ...$finding,
                        'source_type' => 'atlas_initiative_run',
                        'source_id' => $run?->id,
                    ]);
                    if ($item) {
                        $emitted[] = $item->id;
                    }
                }
            }

            $this->finishRun($run, 'succeeded', $findings, $emitted);

            return [
                'ok' => true,
                'run_id' => $run?->id,
                'workspace' => $workspace,
                'dry_run' => ! $emit,
                'findings' => $findings,
                'emitted_item_ids' => $emitted,
                'emitted_count' => count($emitted),
            ];
        } catch (\Throwable $throwable) {
            $this->finishRun($run, 'failed', [], [], $throwable->getMessage());

            throw $throwable;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function explicitMarkerFindings(string $workspace): array
    {
        $findings = [];
        foreach ($this->sourceFiles($workspace) as $file) {
            $relative = $this->relativePath($workspace, $file->getPathname());
            $lines = @file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $index => $line) {
                $marker = $this->proposalMarker($line);
                if ($marker === null) {
                    continue;
                }

                $lineNumber = $index + 1;
                $findings[] = [
                    'title' => "Proposta marcada em {$relative}:{$lineNumber}",
                    'category' => 'auto_improvement',
                    'finding' => "Marcador explicito de proposal encontrado em {$relative}:{$lineNumber}.",
                    'problem' => $marker,
                    'solution' => 'Revisar o marcador, confirmar impacto real e transformar em patch pequeno com teste antes de aplicar.',
                    'worth_it' => 'Vale avaliar porque o marcador foi escrito explicitamente para virar proposal, mas ainda exige revisao humana.',
                    'best_solution_rationale' => 'O melhor primeiro passo e discutir/revisar o trecho marcado, nao aplicar mudanca automatica.',
                    'alternatives' => ['Ignorar se o contexto ficou obsoleto.', 'Converter em tarefa sem patch se o escopo for grande demais.'],
                    'file_refs' => [[
                        'path' => $relative,
                        'line' => $lineNumber,
                        'marker' => $marker,
                    ]],
                    'confidence' => 0.92,
                    'dedupe_key' => 'proposal:marker:'.sha1($relative.':'.$lineNumber.':'.$marker),
                ];
            }
        }

        return $findings;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function largeServiceFindings(string $workspace): array
    {
        $threshold = max(300, (int) config('atlas.mobile.proposal_scan.large_service_lines', 420));
        $findings = [];
        foreach ($this->sourceFiles($workspace) as $file) {
            $relative = $this->relativePath($workspace, $file->getPathname());
            if (! str_ends_with($relative, '.php') || ! preg_match('#(^|/)app/(Services|Http/Controllers)/#', $relative)) {
                continue;
            }

            $content = @file_get_contents($file->getPathname());
            if (! is_string($content)) {
                continue;
            }

            $lineCount = substr_count($content, "\n") + 1;
            $methodCount = (int) preg_match_all('/function\s+[A-Za-z0-9_]+\s*\(/', $content);
            $decisionCount = (int) preg_match_all('/\b(if|match|switch|case|catch)\b/', $content);
            if ($lineCount < $threshold || $methodCount < 10 || $decisionCount < 20) {
                continue;
            }

            $findings[] = [
                'title' => "Reduzir complexidade em {$relative}",
                'category' => 'refactor_scan',
                'finding' => "{$relative} tem {$lineCount} linhas, {$methodCount} metodos e {$decisionCount} pontos de decisao.",
                'problem' => 'A classe concentra responsabilidade suficiente para aumentar custo de manutencao e risco em alteracoes futuras.',
                'solution' => 'Extrair policy/helper dedicado para a responsabilidade mais isolada, mantendo contrato publico e adicionando teste de regressao.',
                'worth_it' => 'Vale se a proxima mudanca tocar este arquivo; o ganho e reduzir risco sem mexer em comportamento.',
                'best_solution_rationale' => 'Extrair um helper pequeno e melhor que reescrever a classe inteira porque reduz blast radius.',
                'alternatives' => ['Apenas adicionar testes no comportamento atual.', 'Adiar ate a proxima mudanca funcional no arquivo.'],
                'file_refs' => [[
                    'path' => $relative,
                    'line_count' => $lineCount,
                    'method_count' => $methodCount,
                    'decision_count' => $decisionCount,
                ]],
                'confidence' => 0.72,
                'dedupe_key' => 'proposal:large-service:'.sha1($relative.':v1'),
            ];
        }

        return $findings;
    }

    /**
     * @return iterable<int,SplFileInfo>
     */
    private function sourceFiles(string $workspace): iterable
    {
        if (! is_dir($workspace)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspace, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if ($this->isExcluded($workspace, $path)) {
                continue;
            }

            if (! in_array(strtolower($file->getExtension()), $this->extensions, true)) {
                continue;
            }

            if ($file->getSize() > 512_000) {
                continue;
            }

            yield $file;
        }
    }

    private function proposalMarker(string $line): ?string
    {
        foreach ([
            '/^\s*(?:(?:\/\/|#|\*|\/\*|<!--|-)\s*)?ATLAS_PROPOSAL:\s*(.+?)(?:\s*-->)?\s*$/i',
            '/^\s*(?:(?:\/\/|#|\*|\/\*|<!--|-)\s*)?TODO\s*\(\s*atlas-proposal\s*\)\s*:?\s*(.+?)(?:\s*-->)?\s*$/i',
        ] as $pattern) {
            if (preg_match($pattern, $line, $matches) === 1) {
                $marker = trim((string) $matches[1]);
                $marker = preg_replace('/\s*\*\/$/', '', $marker) ?: $marker;

                return $marker !== '' ? Str::limit($marker, 280, '...') : null;
            }
        }

        return null;
    }

    private function startRun(string $workspace, bool $emit, int $limit): ?AtlasInitiativeRun
    {
        if (! DatabaseTableAvailability::has('atlas_initiative_runs')) {
            return null;
        }

        return AtlasInitiativeRun::query()->create([
            'kind' => 'refactor_scan',
            'status' => 'running',
            'started_at' => now(),
            'scope' => [
                'workspace' => $workspace,
                'emit' => $emit,
                'limit' => $limit,
                'rules' => ['explicit_marker', 'large_service'],
            ],
            'findings' => [],
            'emitted_inbox_item_ids' => [],
            'metadata' => ['scanner' => 'auto_improvement_proposal_scanner_v1'],
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @param  array<int,string>  $emitted
     */
    private function finishRun(?AtlasInitiativeRun $run, string $status, array $findings, array $emitted, ?string $error = null): void
    {
        if (! $run) {
            return;
        }

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'findings' => $findings,
            'emitted_inbox_item_ids' => $emitted,
            'error_message' => $error,
        ]);
    }

    private function isExcluded(string $workspace, string $path): bool
    {
        $relative = str_replace('\\', '/', $this->relativePath($workspace, $path));
        foreach ($this->excludedDirs as $dir) {
            if ($relative === $dir || str_starts_with($relative, $dir.'/') || str_contains($relative, '/'.$dir.'/')) {
                return true;
            }
        }

        return false;
    }

    private function resolveWorkspace(string $workspace): string
    {
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function relativePath(string $workspace, string $path): string
    {
        $workspace = rtrim($this->resolveWorkspace($workspace), DIRECTORY_SEPARATOR);
        $resolved = realpath($path) ?: $path;

        return str_starts_with($resolved, $workspace.DIRECTORY_SEPARATOR)
            ? substr($resolved, strlen($workspace) + 1)
            : $resolved;
    }
}
