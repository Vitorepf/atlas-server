<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMultiRepoMergeAuthority;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AuditJournal;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2BlastRadiusCalculator;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2EnterpriseMergeGate;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2ScopeManifestRegistry;
use Illuminate\Console\Command;

/**
 * ADVISORY merge-authorization preview. Runs the dormant deterministic
 * {@see AtlasLoopV2EnterpriseMergeGate::authorize()} on a proposal assembled from --options and/or an injected
 * proposal source, and emits the decision (authorized + the deny reasons/violations).
 *
 * PREVIEW ONLY: it never performs a merge, never advances any ladder, never mutates the queue or git. The
 * gate's own audit-journal append is authorize()'s only side effect — the loop can SEE its merge decision
 * before acting on it.
 */
final class AtlasLoopMergeAuthPreviewCommand extends Command
{
    /** Container key for an injected proposal source (test/integration seam): array|callable():array. */
    private const PROPOSAL_BINDING = 'atlas.loop.merge_auth_preview.proposal';

    protected $signature = 'atlas:loop:merge-auth-preview {--scope=} {--changed-files=} {--critical-paths=} {--json}';

    protected $description = 'ADVISORY: preview the loop V2 merge-authorization decision for a scope+proposal (never merges).';

    public function handle(): int
    {
        $scopeId = trim((string) $this->option('scope'));
        if ($scopeId === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'scope_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $decision = $this->resolveGate()->authorize($scopeId, $this->assembleProposal());

        $reasons = $decision['deny_reason'] !== null ? [$decision['deny_reason']] : [];
        $this->line((string) json_encode([
            'schema' => 'atlas.loop.merge_auth_preview.v1',
            'advisory' => true,
            'scope_id' => $decision['scope_id'],
            'authorized' => $decision['allowed'],
            'reasons' => $reasons,
            'blast_tier' => $decision['blast_tier'],
            'authority_scope' => $decision['authority_scope'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Prefer a bound gate (test/integration seam); otherwise assemble it from container-resolved collaborators
     * with a default audit-journal path. Read-only resolution — building the gate performs no merge.
     */
    private function resolveGate(): AtlasLoopV2EnterpriseMergeGate
    {
        $app = $this->getLaravel();
        if ($app->bound(AtlasLoopV2EnterpriseMergeGate::class)) {
            return $app->make(AtlasLoopV2EnterpriseMergeGate::class);
        }

        return new AtlasLoopV2EnterpriseMergeGate(
            $app->make(AtlasLoopV2ScopeManifestRegistry::class),
            $app->make(AtlasLoopMultiRepoMergeAuthority::class),
            $app->make(AtlasLoopV2BlastRadiusCalculator::class),
            new AtlasLoopV2AuditJournal(storage_path('atlas/loop/v2/merge_auth_preview.ndjson')),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function assembleProposal(): array
    {
        $proposal = [];
        $app = $this->getLaravel();
        if ($app->bound(self::PROPOSAL_BINDING)) {
            $bound = $app->make(self::PROPOSAL_BINDING);
            if (is_callable($bound)) {
                $bound = $bound();
            }
            if (is_array($bound)) {
                $proposal = $bound;
            }
        }

        foreach (['changed-files' => 'changed_files', 'critical-paths' => 'critical_paths'] as $opt => $key) {
            $csv = trim((string) $this->option($opt));
            if ($csv !== '') {
                $proposal[$key] = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $s): bool => $s !== ''));
            }
        }

        return $proposal;
    }
}
