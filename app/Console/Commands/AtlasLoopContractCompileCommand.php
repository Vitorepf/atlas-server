<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricArchitectureContractCompiler;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Arms the dormant orphan {@see AtlasTaskFabricArchitectureContractCompiler::compile()} at the operator surface:
 * compiles an approved architecture contract into one or more atomic task-packet drafts (objective, allowed_files,
 * scope_in, acceptance/evidence, spec_hash) — one draft per impl file paired with its adjacent test.
 *
 * Pure + read-only: it never enqueues, dispatches, shells, gits, or mutates storage. Invalid contracts (broad
 * directories, empty seeds, non-Atlas-native ownership) are refused.
 */
final class AtlasLoopContractCompileCommand extends Command
{
    protected $signature = 'atlas:loop:contract-compile {--contract=} {--json}';

    protected $description = 'Read-only compile of an architecture contract into atomic task-packet drafts.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('contract'));
        if ($raw === '') {
            return $this->refuse('contract-compile requires --contract=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $contract = json_decode($raw, true);
        if (! is_array($contract)) {
            return $this->refuse('--contract must be a JSON object');
        }

        try {
            $drafts = app(AtlasTaskFabricArchitectureContractCompiler::class)->compile($contract);
        } catch (RuntimeException $e) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'compile_refused',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $facts = [
            'schema' => 'atlas.loop.contract_compile.v1',
            'draft_count' => count($drafts),
            'drafts' => $drafts,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('draft_count: '.$facts['draft_count']);
            foreach ($drafts as $d) {
                $this->line($d['id'].'  '.$d['objective']);
            }
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
