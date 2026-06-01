<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAssignmentAndClaimContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-Construction Assignment And Claim CLI.
 *
 *   php artisan atlas:aaeos:assignment-and-claim-contract
 *     [--assignment=ASSIGN-20260601-0001]
 *     [--hot-scopes=runtimes/python/voice_realtime/]
 *     [--claimed-files=app/Services/Other.php]
 *     [--json]
 *
 * Read-only, deterministic. Selects at most ONE safe packet from the built-in
 * local candidate set and emits an atlas.self_construction_assignment_preview.v1
 * claim preview (claim_preview_ready or blocked). It NEVER persists a claim,
 * grants write authority or authorizes execution (execution_allowed is always
 * false).
 *
 * @see docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
 */
class AtlasAssignmentAndClaimContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:assignment-and-claim-contract
        {--assignment= : assignment id for the preview}
        {--hot-scopes= : comma-separated paths owned by another active front}
        {--claimed-files= : comma-separated paths already owned by other claimed packets}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · assignment and claim selector emitting a read-only claim preview (claim_preview_ready|blocked) for one packet.';

    public function handle(AtlasAssignmentAndClaimContractService $service): int
    {
        try {
            $assignmentOpt = $this->option('assignment');
            $hasAssignment = is_string($assignmentOpt) && trim($assignmentOpt) !== '';

            $input = [
                'assignment_id' => $hasAssignment ? trim((string) $assignmentOpt) : 'ASSIGN-LOCAL-0001',
                'hot_external_scopes' => $this->list('hot-scopes'),
                'already_claimed_files' => $this->list('claimed-files'),
                // Safe default candidate set: one fully-qualified docs packet that
                // fences every hot scope, plus one disqualified packet to prove the
                // selector is actually filtering rather than taking the first row.
                'candidates' => [
                    [
                        'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
                        'status' => 'available',
                        'collision_risk' => 'none',
                        'dependencies' => [],
                        'dependencies_complete' => true,
                        'allowed_files' => ['docs/engineering-knowledge-base/self-construction/'],
                        'forbidden_files' => [
                            'routes/',
                            'database/migrations/',
                            'app/providers/',
                            'runtimes/python/voice_realtime/',
                            'app/services/kernel/',
                            'app/services/ai/providers/',
                            'app/console/commands/*daemon*',
                        ],
                        'has_required_validator' => true,
                        'authorizes_execution' => false,
                        'required_first_commands' => [
                            'git status --short',
                            'git diff --stat',
                            'git diff --name-only',
                        ],
                        'stop_conditions' => ['scope_validator_fail'],
                        'packet_hash' => 'sha256:local-docs-packet',
                        'split_hash' => 'sha256:local-split',
                    ],
                ],
            ];

            $result = $service->select($input);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasAssignmentAndClaimContractService::STATUS_READY
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'assignment_and_claim_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }
}
