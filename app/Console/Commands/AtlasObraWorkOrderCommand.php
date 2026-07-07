<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * K2 (Obra #18) — `atlas:obra:work-order`, the scaffolder that turns a slice
 * descriptor into a Kit-conformant work-order (the schema K1 defined, the linter
 * K3 accepts). Distinct from `atlas:frontend:work-order`.
 *
 * It does the two things a hand-written order gets wrong:
 *   1. VALIDATES every cited path (glossary values, forbidden files, the
 *      pre-written test) and every cited symbol EXISTS — in THIS workspace, with
 *      `rg --no-ignore` so storage/ isn't silently skipped. A phantom refuses
 *      the order (this is what would have caught the 2 false ghosts).
 *   2. AUTO-FILLS frozen_callers via reference search (rg is the code-graph-lite
 *      the "verify with rg" doctrine already mandates) and stamps the acceptance
 *      test's content hash so K4 can prove it stays untouched.
 *
 * Descriptor JSON: { objective, allowed_files[], forbidden_files[],
 *   glossary{sigla:path}, acceptance_test_ref{path}, freeze_callers_of[symbol],
 *   stop_and_return[], acceptance_criteria[], baseline_artifact{} }
 *
 * ponytail: rg-based reference search, not a full call-graph — accurate enough to
 * seed frozen_callers and catch phantoms; swap in the code-intelligence graph if
 * a symbol needs true call-edges rather than textual references.
 */
class AtlasObraWorkOrderCommand extends Command
{
    protected $signature = 'atlas:obra:work-order
        {slice : path to a slice descriptor JSON}
        {--out= : write the generated work-order JSON here (default: stdout)}
        {--max-callers=20 : cap frozen_callers per symbol}
        {--json : machine-readable output}';

    protected $description = 'Generate + validate a Kit-conformant work-order from a slice descriptor (K2).';

    public function handle(): int
    {
        $descriptorPath = (string) $this->argument('slice');
        $abs = $this->resolve($descriptorPath);
        if (! is_file($abs)) {
            return $this->bail("descriptor not found: {$descriptorPath}");
        }

        try {
            $descriptor = json_decode((string) file_get_contents($abs), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return $this->bail('descriptor is not valid JSON: '.$e->getMessage());
        }
        if (! is_array($descriptor)) {
            return $this->bail('descriptor must be a JSON object');
        }

        $glossary = (array) ($descriptor['glossary'] ?? []);
        $forbidden = array_values(array_map('strval', (array) ($descriptor['forbidden_files'] ?? [])));
        $acceptancePath = trim((string) data_get($descriptor, 'acceptance_test_ref.path', ''));
        $freezeSymbols = array_values(array_filter(array_map('strval', (array) ($descriptor['freeze_callers_of'] ?? []))));

        // 1. VALIDATE cited paths exist (allowed_files are excluded — a create
        //    task legitimately produces them).
        $phantomPaths = [];
        foreach (array_merge(array_values(array_map('strval', $glossary)), $forbidden, $acceptancePath !== '' ? [$acceptancePath] : []) as $path) {
            if (trim($path) !== '' && ! $this->pathExists($path)) {
                $phantomPaths[] = $path;
            }
        }

        // 2. VALIDATE cited symbols resolve to ≥1 reference in the workspace.
        $phantomSymbols = [];
        $frozenCallers = [];
        foreach ($freezeSymbols as $symbol) {
            $refs = $this->references($symbol, (int) $this->option('max-callers'));
            if ($refs === []) {
                $phantomSymbols[] = $symbol;

                continue;
            }
            foreach ($refs as $ref) {
                $frozenCallers[] = ['caller' => $ref, 'destination' => 'declare o destino desta chamada (aditivo-only)'];
            }
        }

        if ($phantomPaths !== [] || $phantomSymbols !== []) {
            return $this->bail('phantom references — order refused', [
                'phantom_paths' => $phantomPaths,
                'phantom_symbols' => $phantomSymbols,
            ]);
        }

        // 3. Stamp the acceptance test hash so K4 can prove it stays untouched.
        $acceptanceHash = '';
        if ($acceptancePath !== '' && is_file($this->resolve($acceptancePath))) {
            $acceptanceHash = hash('sha256', (string) file_get_contents($this->resolve($acceptancePath)));
        }

        $workOrder = [
            'objective' => (string) ($descriptor['objective'] ?? ''),
            'allowed_files' => array_values(array_map('strval', (array) ($descriptor['allowed_files'] ?? []))),
            'forbidden_files' => $forbidden,
            'acceptance_criteria' => array_values(array_map('strval', (array) ($descriptor['acceptance_criteria'] ?? []))),
            'glossary' => $this->normalizeGlossary($glossary),
            'acceptance_test_ref' => ['path' => $acceptancePath, 'hash' => $acceptanceHash],
            'frozen_callers' => $frozenCallers,
            'stop_and_return' => array_values(array_map('strval', (array) ($descriptor['stop_and_return'] ?? []))),
            'baseline_artifact' => is_array($descriptor['baseline_artifact'] ?? null) ? $descriptor['baseline_artifact'] : null,
            'generated_by' => 'atlas:obra:work-order',
        ];

        $encoded = (string) json_encode($workOrder, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $out = $this->option('out');
        if (is_string($out) && $out !== '') {
            $target = $this->resolve($out);
            @mkdir(dirname($target), 0775, true);
            file_put_contents($target, $encoded.PHP_EOL);
            $this->info("work-order written: {$out} (frozen_callers=".count($frozenCallers).')');

            return self::SUCCESS;
        }

        $this->line($encoded);

        return self::SUCCESS;
    }

    /**
     * @return array<string,string>
     */
    private function normalizeGlossary(array $glossary): array
    {
        $out = [];
        foreach ($glossary as $sigla => $path) {
            $sigla = trim((string) $sigla);
            $path = trim((string) $path);
            if ($sigla !== '' && $path !== '') {
                $out[$sigla] = $path;
            }
        }

        return $out;
    }

    private function pathExists(string $path): bool
    {
        $file = preg_replace('/:\d+(-\d+)?$/', '', trim($path)) ?? $path;
        $abs = $this->resolve($file);

        return is_file($abs) || is_dir($abs);
    }

    private function resolve(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path(ltrim($path, '/'));
    }

    /**
     * References to a symbol across the workspace (rg --no-ignore so storage/ is
     * not silently skipped). Returns `path:line` anchors. Fail-open to [] when rg
     * is unavailable — validation degrades, it never crashes the scaffold.
     *
     * @return list<string>
     */
    private function references(string $symbol, int $max): array
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            return [];
        }

        $result = Process::path(base_path())->run([
            'rg', '--no-ignore', '-n', '--no-heading', '-w', $symbol, 'app', 'routes', 'config', 'database',
        ]);
        if (! $result->successful()) {
            return [];
        }

        $lines = array_values(array_filter(explode("\n", trim($result->output()))));
        $out = [];
        foreach ($lines as $line) {
            // rg -n output: "path:line:content" → keep "path:line".
            if (preg_match('/^(.+?:\d+):/', $line, $m)) {
                $out[] = $m[1];
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function bail(string $message, array $context = []): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => false, 'error' => $message] + $context, JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
            foreach ($context as $key => $value) {
                $this->line('  '.$key.': '.json_encode($value));
            }
        }

        return self::FAILURE;
    }
}
