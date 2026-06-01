<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCyberFlowProfilesProposalService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas AI Cyber Flow Profiles Proposal.
 *
 * @see docs/engineering-knowledge-base/cyber-security/flow-profiles-proposal.md
 */
final class AtlasCyberFlowProfilesProposalCommand extends Command
{
    protected $signature = 'atlas:aaeos:cyber-flow-profiles-proposal {--json : Machine-readable JSON output}';

    protected $description = 'Show the proposed Atlas Cyber flow profiles, their anti-pattern validation and the promotion gate state.';

    public function handle(AtlasCyberFlowProfilesProposalService $service): int
    {
        try {
            $result = $service->proposal();
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
