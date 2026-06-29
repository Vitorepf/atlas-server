<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Cortex\AtlasCortexUniversalConfigLoader;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Arms the dormant pure {@see AtlasCortexUniversalConfigLoader::load()} at the operator surface: loads and
 * validates a repo cortex config (from --raw when supplied, else the repo's cortex.yaml/.toml) and emits the
 * normalized config (scope_roots, doc_roots, forbidden_globs, clone_min_lines, schema_id) as deterministic
 * facts. Pure (io=0 when --raw is given), read-only. An invalid/absent config is surfaced as a config error.
 *
 * --raw accepts inline config (JSON or the YAML subset) or a path to a config file.
 */
final class AtlasLoopCortexConfigLoadCommand extends Command
{
    protected $signature = 'atlas:loop:cortex-config-load {--repo-root=} {--raw=} {--json}';

    protected $description = 'Read-only: load + validate a repo cortex config into its normalized facts.';

    public function handle(AtlasCortexUniversalConfigLoader $loader): int
    {
        $repoRoot = trim((string) $this->option('repo-root'));
        if ($repoRoot === '') {
            $repoRoot = base_path();
        }

        $rawOption = $this->option('raw');
        $rawOverride = null;
        if ($rawOption !== null && trim((string) $rawOption) !== '') {
            $rawOverride = is_file((string) $rawOption) ? (string) file_get_contents((string) $rawOption) : (string) $rawOption;
        }

        try {
            $config = $loader->load($repoRoot, $rawOverride);
        } catch (InvalidArgumentException $e) {
            $this->line((string) json_encode(['status' => 'config_error', 'reason' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.cortex_config_load.v1', 'config' => $config],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
