<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Gap4.F5 — Self-Construction OS sub-command extraction (MergeReview).
 *
 * Thin shell that exposes the canonical entry point for the AgentMergeReview
 * family (~7 services). The mother command remains runtime-active.
 */
class AtlasAiSelfConstructionMergeReviewCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:self-construction:merge-review {--json : machine-readable}';

    protected $description = 'Atlas Self-Construction OS — AgentMergeReview family (7 services).';

    public function handle(): int
    {
        $payload = [
            'schema_version' => 'atlas.self_construction.sub_command_shell.v1',
            'family' => 'AgentMergeReview',
            'estimated_service_count' => 7,
            'status' => 'shell_ready',
            'detail' => 'Canonical entry point for AgentMergeReview. Full action set still served by atlas:ai:self-construction mother.',
            'mother_command' => 'atlas:ai:self-construction',
        ];
        $this->jsonLine($payload);

        return self::SUCCESS;
    }
}
