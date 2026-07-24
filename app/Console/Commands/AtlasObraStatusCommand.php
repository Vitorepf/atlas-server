<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Obra\AtlasObraService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AOBG N3.F4 — `atlas:obra:status`: inspect a commissioned obra (READ ONLY, cost-free).
 *
 * The read side of the operator surface. Shows the plan-DAG, per-step status,
 * certification verdict, and the ONE obra branch with the review/merge commands —
 * WITHOUT spending or touching git. With no --obra it lists recent obras so the
 * operator can find an id.
 *
 *     atlas:obra:status                       # list recent obras
 *     atlas:obra:status --obra=obra-abc123    # the plan-DAG + per-step status + branch
 *     atlas:obra:status --obra=obra-abc123 --json
 *
 * Local DB only — ZERO provider spend. Commissioning (which spends, branch-only, never
 * main) is the separate atlas:obra:deliver command.
 */
class AtlasObraStatusCommand extends Command
{
    use EmitsCanonicalJson;

    public const SCHEMA = 'atlas.obra.status_command.v1';

    protected $signature = 'atlas:obra:status
        {--obra= : the obra/plan id to inspect (from atlas:obra:deliver / atlas:obra:plan). Omit to list recent obras}
        {--limit= : when listing, the max obras to show (default 20)}
        {--json : machine-readable output}';

    protected $description = 'AOBG N3.F4: inspect a commissioned obra — the plan-DAG, per-step status, certification + the one branch (READ ONLY, cost-free).';

    public function handle(AtlasObraService $service): int
    {
        $obraId = $this->stringOption('obra');

        // No id → list recent obras (cost-free directory of what has been commissioned).
        if ($obraId === null || $obraId === '') {
            return $this->renderList();
        }

        $status = $service->status($obraId);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($status));

            return ($status['found'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        if (! ($status['found'] ?? false)) {
            $this->error('obra not found: '.$obraId.' (reason: '.((string) ($status['reason'] ?? '?')).')');

            return self::FAILURE;
        }

        $this->render($status);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $status
     */
    private function render(array $status): void
    {
        $this->info(sprintf(
            'atlas:obra:status  obra=%s  status=%s  workspace=%s  decomposer=%s  nodes=%d',
            (string) ($status['obra_id'] ?? '?'),
            (string) ($status['status'] ?? '?'),
            (string) ($status['workspace_id'] ?? '?'),
            (string) ($status['decomposer'] ?? '?'),
            (int) ($status['node_count'] ?? 0),
        ));
        $this->line('  intent: '.(string) ($status['intent'] ?? ''));
        $this->line('  branch: '.((string) ($status['branch'] ?? '(none yet)')));
        $this->line('');

        $this->line('  plan-DAG (one branch, dependency order):');
        foreach ((array) ($status['plan'] ?? []) as $node) {
            $deps = array_values((array) ($node['depends_on'] ?? []));
            $this->line(sprintf(
                '    [%d] %-10s %s  (%s)%s',
                (int) ($node['seq'] ?? 0),
                (string) ($node['status'] ?? '?'),
                (string) ($node['title'] ?? ''),
                (string) ($node['id'] ?? ''),
                $deps === [] ? '' : '  deps='.implode(',', $deps),
            ));
            $files = array_values((array) ($node['files_changed'] ?? []));
            if ($files !== []) {
                $this->line('         files: '.implode(', ', array_slice($files, 0, 8)));
            }
        }
        $this->line('');

        $branch = (string) ($status['branch'] ?? '');
        if ($branch !== '') {
            $this->line('  Review the obra branch (your sovereignty — Atlas never merges):');
            $this->line('    git -C <repo> diff main..'.$branch);
            $this->line('    git -C <repo> checkout '.$branch);
        }
    }

    /**
     * List recent obras (read-only directory). Local DB only.
     */
    private function renderList(): int
    {
        $limit = $this->positiveIntOption('limit') ?? 20;
        $limit = min($limit, 100);

        $rows = DB::table('atlas_obra_plans')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'intent', 'status', 'workspace_id', 'updated_at']);

        $list = $rows->map(static fn ($r): array => [
            'obra_id' => (string) $r->id,
            'intent' => (string) $r->intent,
            'status' => (string) $r->status,
            'workspace_id' => (string) $r->workspace_id,
            'updated_at' => $r->updated_at,
        ])->all();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'schema' => self::SCHEMA,
                'count' => count($list),
                'obras' => $list,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($list === []) {
            $this->comment('No obras yet. Commission one: atlas:obra:deliver "your intent here"');

            return self::SUCCESS;
        }

        $this->info('atlas:obra:status — recent obras ('.count($list).'):');
        foreach ($list as $obra) {
            $this->line(sprintf(
                '  %-8s %-22s %s',
                (string) $obra['status'],
                (string) $obra['obra_id'],
                mb_substr((string) $obra['intent'], 0, 70),
            ));
        }
        $this->line('');
        $this->comment('Inspect one: atlas:obra:status --obra=<id>');

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $v = $this->option($key);

        return is_string($v) ? $v : null;
    }

    private function positiveIntOption(string $key): ?int
    {
        $v = $this->option($key);
        if (! is_string($v) || ! is_numeric(trim($v))) {
            return null;
        }
        $n = (int) trim($v);

        return $n > 0 ? $n : null;
    }
}
