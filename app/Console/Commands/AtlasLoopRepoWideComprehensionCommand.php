<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideComprehensionModel;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopRepoWideComprehensionModel::build()} at the operator surface: reads the
 * per-scope comprehension models from a JSON file and emits the federated repo-wide model — unioned symbols /
 * clones and the cross-domain-resolved orphan set (a scope-local orphan called from another scope is dropped) —
 * as deterministic facts.
 *
 * Read-only + pure: same inputs ⇒ a byte-identical model and a stable model_hash. No I/O, provider, or mutation.
 */
final class AtlasLoopRepoWideComprehensionCommand extends Command
{
    protected $signature = 'atlas:loop:repo-wide-comprehension {--input=} {--json}';

    protected $description = 'Read-only federated repo-wide comprehension model from per-scope models (cross-domain orphan resolution).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('repo-wide-comprehension requires --input=<path to a readable per-scope models JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON array/object');
        }
        $perScope = is_array($decoded['scopes'] ?? null) ? $decoded['scopes']
            : (is_array($decoded['per_scope_models'] ?? null) ? $decoded['per_scope_models'] : $decoded);

        $model = app(AtlasLoopRepoWideComprehensionModel::class)->build(array_values($perScope));

        if ($this->option('json')) {
            $this->line((string) json_encode($model, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('scopes: '.$model['scope_count'].'  symbols: '.$model['symbol_count'].'  orphans: '.count($model['orphans']));
            $this->line('model_hash: '.$model['model_hash']);
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
