<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Coherence\AtlasLoopPostEditCoherenceScanner;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopPostEditCoherenceScanner::scan()} at the operator surface: scans the changed
 * PHP files (the current git diff by default, or explicit --file paths) and emits post-edit coherence issues
 * (parse errors, unresolved `use` imports) as deterministic facts. Read-only.
 */
final class AtlasLoopPostEditCoherenceCommand extends Command
{
    protected $signature = 'atlas:loop:post-edit-coherence {--file=* : explicit files to scan (default: current git diff)} {--json}';

    protected $description = 'Read-only post-edit coherence scan of changed PHP files (parse errors / unresolved uses).';

    public function handle(AtlasLoopPostEditCoherenceScanner $scanner): int
    {
        $files = $this->targetFiles();
        $issues = $scanner->scan($files);

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.post_edit_coherence.v1',
            'files_scanned' => count($files),
            'issues_count' => count($issues),
            'issues' => $issues,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function targetFiles(): array
    {
        $explicit = array_values(array_filter(
            array_map('strval', (array) $this->option('file')),
            static fn (string $f): bool => trim($f) !== '',
        ));
        if ($explicit !== []) {
            return $explicit;
        }

        return $this->gitChangedPhpFiles();
    }

    /**
     * @return list<string>
     */
    private function gitChangedPhpFiles(): array
    {
        $root = rtrim(base_path(), '/');
        $files = [];
        foreach (['diff --name-only HEAD', 'diff --name-only --cached', 'ls-files --others --exclude-standard'] as $gitCmd) {
            $output = @shell_exec('cd '.escapeshellarg($root).' && git '.$gitCmd.' 2>/dev/null');
            foreach (preg_split('/\R/', (string) $output) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || ! str_ends_with($line, '.php')) {
                    continue;
                }
                $abs = $root.'/'.$line;
                if (is_file($abs)) {
                    $files[$abs] = true;
                }
            }
        }

        return array_keys($files);
    }
}
