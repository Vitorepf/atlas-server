<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAntigravityCliGovernedTerminalExecutorService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Antigravity CLI (`agy`) governed-terminal policy CLI.
 *
 *   php artisan atlas:aaeos:antigravity-cli-governed-terminal-executor
 *     [--token=--sandbox,--add-dir,/model]   // comma list of agy tokens present
 *     [--driver]                             // caller intends agy as Atlas runtime driver
 *     [--no-sandbox-arg]                     // drop the --sandbox baseline token
 *     [--work-packet=WP-123]                 // scope lock id
 *     [--secrets]                            // secrets/canonical memory in prompt
 *     [--json]
 *
 * Read-only, deterministic. Emits the invocation verdict (permitted|blocked),
 * the minimal atlas.antigravity_cli_reference.v1 envelope and the contract
 * manifest. It NEVER spawns `agy`.
 *
 * @see docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
 */
class AtlasAntigravityCliGovernedTerminalExecutorCommand extends Command
{
    protected $signature = 'atlas:aaeos:antigravity-cli-governed-terminal-executor
        {--token= : comma-separated agy tokens present (flags/subcommands/slash-commands)}
        {--driver : caller intends to use agy as an Atlas runtime driver}
        {--no-sandbox-arg : drop the default --sandbox baseline token from the run}
        {--work-packet= : Work Packet scope-lock id authorizing the experiment}
        {--secrets : mark canonical secrets/memory as placed in the CLI prompt}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · Antigravity CLI (agy) governed-terminal policy: classify tokens, decide invocation, emit reference envelope. SDK-first, no CLI driver.';

    public function handle(AtlasAntigravityCliGovernedTerminalExecutorService $service): int
    {
        try {
            $rawTokens = $this->option('token');
            $tokens = [];
            if (is_string($rawTokens) && trim($rawTokens) !== '') {
                foreach (explode(',', $rawTokens) as $tok) {
                    if (trim($tok) !== '') {
                        $tokens[] = trim($tok);
                    }
                }
            }

            // Safe default: a governed-discovery run starts sandboxed unless the
            // operator explicitly drops the baseline token.
            if (! (bool) $this->option('no-sandbox-arg') && ! in_array('--sandbox', $tokens, true)) {
                $tokens[] = '--sandbox';
            }

            $workPacket = $this->option('work-packet');

            $decision = $service->decideInvocation([
                'tokens' => $tokens,
                'atlas_workspace' => true,
                'as_runtime_driver' => (bool) $this->option('driver'),
                'sdk_path_active' => true,
                'work_packet_id' => is_string($workPacket) ? $workPacket : null,
                'secrets_in_prompt' => (bool) $this->option('secrets'),
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'decision' => $decision,
                'reference_envelope' => $service->referenceEnvelope([
                    'binary_path' => '/Users/vitorepf/.local/bin/agy',
                    'present' => true,
                    'flags_seen' => $tokens,
                ]),
                'manifest' => $service->manifest(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'antigravity_cli_governed_terminal_executor_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
