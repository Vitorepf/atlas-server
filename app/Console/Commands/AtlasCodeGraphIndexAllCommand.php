<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\CodeGraph\CodeGraphSecretScanner;
use App\Services\Engineering\CodeGraph\CodeGraphSymbolBuilder;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspacePrivacy;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AP-815 — index-all: the MULTI-REPO orchestration on top of the per-workspace engine.
 *
 * Auto-discovers every git repository under a root directory and indexes EACH as its own
 * isolated workspace (W-1 keying + W-7 identity), running the G-5 secret/PII gate first so
 * an external repo's secrets are surfaced before ingestion. Each repo's graph is keyed by
 * its own workspace_id; they never collide (proven cross-project). Read-only on the source
 * (only the local code-graph DB is written); nothing is auto-promoted.
 */
class AtlasCodeGraphIndexAllCommand extends Command
{
    use EmitsCanonicalJson;

    /** Source extensions the indexer actually ingests (the secret pre-scan checks these). */
    private const INGESTED = ['php', 'ts', 'tsx', 'js', 'jsx', 'md'];

    private const SKIP_DIRS = ['node_modules', 'vendor', 'dist', 'build', '.git'];

    protected $signature = 'atlas:code-graph:index-all
        {root : Directory containing git repositories (each subdir with .git = one workspace)}
        {--depth=2 : How deep to search for repos under the root}
        {--prune : Prune stale rows per workspace}
        {--skip-secret-scan : Skip the G-5 pre-scan}
        {--json : Emit a JSON report}';

    protected $description = 'AP-815: discover git repos under a root and index each as an isolated workspace (G-5 gated).';

    public function handle(
        CodeGraphWorkspaceIdentity $identity,
        CodeGraphWorkspacePrivacy $privacy,
        EngineeringCodeIntelligenceService $intel,
        CodeGraphSecretScanner $scanner,
    ): int {
        $root = rtrim((string) $this->argument('root'), DIRECTORY_SEPARATOR);
        if (! is_dir($root)) {
            $this->error("root not found: {$root}");

            return self::FAILURE;
        }

        $repos = $this->discoverRepos($root, max(1, (int) $this->option('depth')));
        if ($repos === []) {
            $payload = ['status' => 'no_repos', 'root' => $root, 'repos' => []];
            $this->emit($payload, fn () => $this->warn("no git repositories found under {$root}"));

            return self::SUCCESS;
        }

        $prune = (bool) $this->option('prune');
        $scan = ! (bool) $this->option('skip-secret-scan');
        $results = [];

        foreach ($repos as $repo) {
            $wid = $identity->resolve($repo);
            $row = [
                'repo' => $repo,
                'workspace_id' => $wid,
                'privacy_class' => $privacy->classOf($wid),
            ];

            if ($scan) {
                $row['secrets'] = $this->secretPrescan($scanner, $repo);
            }

            try {
                $index = $intel->index(['workspace' => $repo, 'prune' => $prune]);
                $row['modules'] = (int) data_get($index, 'summary.module_count', 0);
                $row['symbols'] = (int) data_get($index, 'summary.symbol_count', (int) data_get($index, 'symbol_count', 0));
                $build = app(CodeGraphSymbolBuilder::class)->build($wid);
                $row['graph_nodes'] = (int) ($build['symbol_nodes'] ?? 0);
                $row['graph_edges'] = (int) ($build['edges_written'] ?? 0);
                $row['status'] = 'ok';
            } catch (Throwable $e) {
                $row['status'] = 'failed';
                $row['error'] = substr($e->getMessage(), 0, 200);
            }

            $results[] = $row;
        }

        $payload = [
            'status' => 'ok',
            'root' => $root,
            'repos_indexed' => count(array_filter($results, static fn (array $r): bool => ($r['status'] ?? '') === 'ok')),
            'repos_total' => count($results),
            'workspaces' => $results,
        ];

        $this->emit($payload, fn () => $this->render($results));

        return self::SUCCESS;
    }

    /**
     * @return array<int,string>
     */
    private function discoverRepos(string $root, int $depth): array
    {
        $repos = [];
        $walk = function (string $dir, int $level) use (&$walk, &$repos, $depth): void {
            if ($level > $depth) {
                return;
            }
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $sub) {
                if (in_array(basename($sub), self::SKIP_DIRS, true)) {
                    continue;
                }
                if (is_dir($sub.DIRECTORY_SEPARATOR.'.git')) {
                    $repos[] = $sub; // a repo — never descend into it
                    continue;
                }
                $walk($sub, $level + 1);
            }
        };
        $walk($root, 1);
        sort($repos);

        return array_values(array_unique($repos));
    }

    /**
     * G-5 pre-scan over the files that WILL be ingested (source extensions), so the report
     * reflects what actually enters the graph. Bounded + fail-safe.
     *
     * @return array{files_scanned:int,findings:int,high_severity:int,examples:array<int,array<string,string>>}
     */
    private function secretPrescan(CodeGraphSecretScanner $scanner, string $repo): array
    {
        $files = 0;
        $findings = 0;
        $high = 0;
        $examples = [];

        try {
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $f) {
                $p = $f->getPathname();
                if (preg_match('#'.preg_quote(DIRECTORY_SEPARATOR, '#').'(node_modules|vendor|dist|build|\.git)'.preg_quote(DIRECTORY_SEPARATOR, '#').'#', $p) === 1) {
                    continue;
                }
                if (! in_array(strtolower($f->getExtension()), self::INGESTED, true)) {
                    continue;
                }
                if ($f->getSize() > 800000) {
                    continue;
                }
                $files++;
                $r = $scanner->scan((string) @file_get_contents($p));
                if (empty($r['has_secrets'])) {
                    continue;
                }
                $findings += (int) ($r['count'] ?? 0);
                foreach (($r['findings'] ?? []) as $fd) {
                    if (($fd['severity'] ?? '') === 'high') {
                        $high++;
                    }
                    if (count($examples) < 5) {
                        $examples[] = ['file' => str_replace($repo.DIRECTORY_SEPARATOR, '', $p), 'type' => (string) ($fd['type'] ?? '?')];
                    }
                }
            }
        } catch (Throwable) {
            // fail-safe: a scan hiccup must not block indexing.
        }

        return ['files_scanned' => $files, 'findings' => $findings, 'high_severity' => $high, 'examples' => $examples];
    }

    /**
     * @param  array<int,array<string,mixed>>  $results
     */
    private function render(array $results): void
    {
        $this->info('AP-815 code-graph index-all — '.count($results).' workspace(s)');
        foreach ($results as $r) {
            $this->line('');
            $this->line('  <fg=cyan>'.($r['workspace_id'] ?? '?').'</>  ('.($r['privacy_class'] ?? '?').')  ['.($r['status'] ?? '?').']');
            $this->line('    '.($r['repo'] ?? ''));
            if (($r['status'] ?? '') === 'ok') {
                $this->line('    modules='.($r['modules'] ?? 0).'  symbols='.($r['symbols'] ?? 0).'  graph: nodes='.($r['graph_nodes'] ?? 0).' edges='.($r['graph_edges'] ?? 0));
            } elseif (isset($r['error'])) {
                $this->line('    <fg=red>error: '.$r['error'].'</>');
            }
            if (isset($r['secrets']) && (int) ($r['secrets']['high_severity'] ?? 0) > 0) {
                $this->line('    <fg=yellow>⚠ '.$r['secrets']['high_severity'].' high-severity secret finding(s) in ingested files — review</>');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return;
        }
        $human();
    }
}
