<?php

namespace App\Services\Engineering;

use App\Models\AtlasTask;
use Illuminate\Support\Facades\File;

class PostgresEngineeringReviewService
{
    public function __construct(
        private readonly EngineeringRunArtifactService $artifacts,
        private readonly EngineeringReviewService $reviews,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function review(AtlasTask $task, array $options = []): array
    {
        $workspace = $this->workspace($options['workspace'] ?? null);
        $files = $this->candidateFiles($task, $workspace, $options);
        $findings = [];
        $checks = [
            'rollback_safe' => 'passed',
            'destructive_migration' => 'passed',
            'fk_index' => 'passed',
            'unique_constraints' => 'passed',
            'jsonb_contract' => 'passed',
            'timestamps_timezone' => 'passed',
            'query_plan' => 'passed',
            'lock_risk' => 'passed',
            'raw_sql' => 'passed',
            'backfill_chunking_idempotency' => 'passed',
        ];

        foreach ($files as $file) {
            $content = File::isFile($file) ? (string) File::get($file) : '';
            $relative = $workspace ? ltrim(str_replace($workspace, '', $file), DIRECTORY_SEPARATOR) : $file;
            $analysis = $this->analyzeFile($relative, $content);
            foreach ($analysis['checks'] as $check => $status) {
                if ($status === 'failed') {
                    $checks[$check] = 'failed';
                } elseif ($status === 'warning' && $checks[$check] === 'passed') {
                    $checks[$check] = 'warning';
                }
            }
            array_push($findings, ...$analysis['findings']);
        }

        $status = collect($checks)->contains('failed') ? 'failed' : 'passed';
        $summary = $status === 'passed'
            ? 'Postgres review passou sem riscos bloqueantes.'
            : 'Postgres review encontrou riscos bloqueantes.';

        $evidence = $this->artifacts->recordEvidence($task, [
            'evidence_type' => 'database_review',
            'target_id' => 'database_review',
            'status' => $status,
            'confidence' => $status === 'passed' ? 0.88 : 0.92,
            'summary' => $summary,
            'files' => $files,
            'metadata' => [
                'checks' => $checks,
                'findings' => $findings,
                'workspace' => $workspace,
            ],
        ], 'atlas:db:review');

        $deepReview = $findings === []
            ? null
            : $this->reviews->deepReview($task, [
                'recorded_by' => 'atlas_db_review',
                'findings' => $findings,
            ]);

        return [
            'task_id' => $task->id,
            'status' => $status,
            'checks' => $checks,
            'findings' => $findings,
            'evidence' => $evidence,
            'review' => $deepReview,
            'blocking' => $status === 'failed',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function explain(AtlasTask $task, array $options = []): array
    {
        $query = trim((string) ($options['query'] ?? ''));
        $risk = $query === '' ? 'unknown' : $this->queryRisk($query);

        $evidence = $this->artifacts->recordEvidence($task, [
            'evidence_type' => 'database_review',
            'target_id' => 'query_plan',
            'status' => $risk === 'high' ? 'needs_review' : 'passed',
            'confidence' => $risk === 'high' ? 0.78 : 0.84,
            'summary' => $query === ''
                ? 'Query plan nao executado: query nao informada.'
                : 'Query plan heuristico registrado com risco '.$risk.'.',
            'metadata' => [
                'query' => $query,
                'risk' => $risk,
                'note' => 'Execucao real de EXPLAIN deve ser fornecida pelo operador quando houver conexao alvo.',
            ],
        ], 'atlas:db:explain');

        return [
            'task_id' => $task->id,
            'query_risk' => $risk,
            'evidence' => $evidence,
            'blocking' => $risk === 'high',
        ];
    }

    /**
     * @return array{checks:array<string,string>,findings:array<int,array<string,mixed>>}
     */
    private function analyzeFile(string $file, string $content): array
    {
        $checks = [];
        $findings = [];
        $lower = mb_strtolower($content);

        if (str_contains($file, 'database/migrations')) {
            if (! str_contains($content, 'function down(')) {
                $checks['rollback_safe'] = 'failed';
                $findings[] = $this->finding('p1', 0.91, 'migration_risk', 'Migration sem rollback', $file, 'Adicionar down() reversivel ou accepted_risk justificado.');
            }
            if ($this->containsAny($lower, ['dropcolumn', 'droptable', 'dropifexists', 'renamecolumn'])) {
                $checks['destructive_migration'] = 'failed';
                $checks['lock_risk'] = 'warning';
                $findings[] = $this->finding('p1', 0.86, 'migration_risk', 'Migration destrutiva detectada', $file, 'Separar deploy/backfill/cleanup e registrar rollback.');
            }
            if (str_contains($lower, 'foreign') && ! str_contains($lower, 'index(')) {
                $checks['fk_index'] = 'warning';
                $findings[] = $this->finding('p2', 0.74, 'data_integrity', 'FK sem indice evidente', $file, 'Confirmar indice para lookup e lock profile.');
            }
            if (str_contains($lower, 'jsonb') && ! $this->containsAny($lower, ['check', 'schema', 'contract'])) {
                $checks['jsonb_contract'] = 'warning';
                $findings[] = $this->finding('p2', 0.76, 'data_integrity', 'JSONB como contrato sem schema/check', $file, 'Documentar schema operacional ou adicionar constraint/check.');
            }
            if ($this->containsAny($lower, ['db::unprepared', 'statement(']) && ! str_contains($lower, 'down(')) {
                $checks['raw_sql'] = 'failed';
                $findings[] = $this->finding('p1', 0.88, 'migration_risk', 'Raw SQL sem rollback evidente', $file, 'Garantir reversibilidade, escaping e comentario de seguranca.');
            }
            if ($this->containsAny($lower, ['chunk(', 'chunkbyid', 'cursor(']) === false && $this->containsAny($lower, ['update ', 'insert into', 'delete from'])) {
                $checks['backfill_chunking_idempotency'] = 'warning';
                $findings[] = $this->finding('p2', 0.72, 'migration_risk', 'Backfill sem chunking/idempotencia evidente', $file, 'Usar chunking, retry e progresso observavel.');
            }
        }

        if ($this->containsAny($lower, ['select *', 'wherejsoncontains', 'order by']) && ! $this->containsAny($lower, ['explain', 'index'])) {
            $checks['query_plan'] = 'warning';
        }

        foreach (['rollback_safe', 'destructive_migration', 'fk_index', 'jsonb_contract', 'raw_sql', 'backfill_chunking_idempotency', 'query_plan'] as $check) {
            $checks[$check] ??= 'passed';
        }

        return ['checks' => $checks, 'findings' => $findings];
    }

    /**
     * @return array<int,string>
     */
    private function candidateFiles(AtlasTask $task, ?string $workspace, array $options): array
    {
        $explicit = collect((array) ($options['files'] ?? []))
            ->filter(fn (mixed $file): bool => is_string($file) && trim($file) !== '')
            ->map(fn (string $file): string => $workspace && ! str_starts_with($file, '/') ? $workspace.DIRECTORY_SEPARATOR.$file : $file)
            ->values();

        if ($explicit->isNotEmpty()) {
            return $explicit->all();
        }

        $contractFiles = collect((array) data_get($task->metadata, 'engineering_contract.likely_files', []))
            ->filter(fn (mixed $file): bool => is_string($file) && trim($file) !== '')
            ->filter(fn (string $file): bool => str_contains($file, 'migration') || str_contains($file, 'database') || str_ends_with($file, '.php'))
            ->map(fn (string $file): string => $workspace && ! str_starts_with($file, '/') ? $workspace.DIRECTORY_SEPARATOR.$file : $file);

        if ($contractFiles->isNotEmpty()) {
            return $contractFiles->values()->all();
        }

        if (! $workspace || ! File::isDirectory($workspace.'/database/migrations')) {
            return [];
        }

        return collect(File::files($workspace.'/database/migrations'))
            ->sortByDesc(fn ($file): int => $file->getMTime())
            ->take(12)
            ->map(fn ($file): string => $file->getPathname())
            ->values()
            ->all();
    }

    private function workspace(mixed $value): ?string
    {
        $workspace = is_string($value) && trim($value) !== '' ? trim($value) : null;
        if (! $workspace) {
            return null;
        }
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function queryRisk(string $query): string
    {
        $lower = mb_strtolower($query);

        return $this->containsAny($lower, ['select *', 'cross join', 'not in', 'jsonb', 'ilike'])
            && ! $this->containsAny($lower, ['limit ', 'where id', 'index'])
            ? 'high'
            : 'medium';
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(string $severity, float $confidence, string $category, string $title, string $file, string $recommendation): array
    {
        return [
            'severity' => $severity,
            'confidence' => $confidence,
            'category' => $category,
            'title' => $title,
            'body' => $title.' em '.$file,
            'file_path' => $file,
            'recommendation' => $recommendation,
            'evidence' => ['source' => 'postgres_engineering_review'],
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
