<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSchemasAndPacketsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Research Self-Improvement Schemas And Packets decider CLI.
 *
 *   php artisan atlas:aaeos:schemas-and-packets [--json]
 *
 * Read-only, deterministic. Runs the full Fail-Closed pipeline over a worked
 * example (a finding that carries a source judgment but whose proposal still
 * requires review) and emits the per-gate verdicts plus the terminal
 * disposition. No doc is written, no code is applied, no tool is run.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md
 */
class AtlasSchemasAndPacketsCommand extends Command
{
    protected $signature = 'atlas:aaeos:schemas-and-packets
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas research · schemas & packets Fail-Closed pipeline (source judgment, docs law, code, auto-apply gates).';

    public function handle(AtlasSchemasAndPacketsService $service): int
    {
        try {
            $result = $service->evaluatePipeline(
                // Research packet with a judged source.
                [
                    'schema_version' => AtlasSchemasAndPacketsService::SCHEMA_RESEARCH_PACKET,
                    'packet_id' => 'rp-demo',
                    'objective' => 'demo',
                    'question' => 'demo',
                    'source_ids' => ['s1'],
                    'recommended_action' => 'promote_to_doc',
                ],
                // One tier-1 official source judgment (may back truth).
                [[
                    'schema_version' => AtlasSchemasAndPacketsService::SCHEMA_SOURCE_JUDGMENT,
                    'source_id' => 's1',
                    'tier' => 1,
                ]],
                // Docs promotion explicitly allowed.
                [
                    'schema_version' => AtlasSchemasAndPacketsService::SCHEMA_DOCS_PROMOTION,
                    'promotion_allowed' => true,
                ],
                // Implementation explicitly allowed.
                [
                    'schema_version' => AtlasSchemasAndPacketsService::SCHEMA_IMPLEMENTATION_PLAN,
                    'implementation_allowed' => true,
                ],
                // Proposal still requires review => auto-apply must be blocked.
                [
                    'schema_version' => AtlasSchemasAndPacketsService::SCHEMA_SELF_IMPROVEMENT,
                    'review_required' => true,
                    'autonomy_level' => 'proposal_only',
                ],
            );

            $this->line((string) json_encode([
                'ok' => true,
                'pipeline' => $result,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'schemas_and_packets_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
