<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasHumanKnowledgeSurfaceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Human Knowledge Surface (HKS) authority gate CLI.
 *
 *   php artisan atlas:aaeos:human-knowledge-surface [--json]
 *
 * Runs the documented HKS gate over a small set of safe reference items so the
 * authority tiers, context-pack admission and canon-overwrite rules are visible:
 * a repo-canon piece (technical truth), a vault note admitted as curated context,
 * a sourceless note (rejected), and a human note attempting to overwrite canon
 * without a decision (denied). Read-only and deterministic; it never reads a
 * file, walks the vault, opens a source, writes a doc or emits evidence.
 *
 * @see docs/engineering-knowledge-base/system-graph/hks.md
 */
class AtlasHumanKnowledgeSurfaceCommand extends Command
{
    protected $signature = 'atlas:aaeos:human-knowledge-surface {--json : machine-readable JSON output (default true)}';

    protected $description = 'Human Knowledge Surface · classifies knowledge-item authority (repo canon vs human surface), gates context-pack admission and blocks note-overwrites of official docs without a decision.';

    public function handle(AtlasHumanKnowledgeSurfaceService $service): int
    {
        try {
            // Repo canon: technical truth, admitted, may govern itself.
            $repoCanon = $service->gate([
                'source' => 'repo',
            ]);

            // Vault note: curated context, admitted with a source attribution.
            $vaultNote = $service->gate([
                'source' => 'vault',
            ]);

            // Sourceless note: rejected — curated context must carry a source.
            $noSource = $service->gate([
                'source' => '',
            ]);

            // Human note attempting to overwrite canon WITHOUT a decision: denied.
            $overwriteAttempt = $service->gate([
                'source' => 'note',
                'requests_overwrite' => true,
            ]);

            $payload = [
                'ok' => true,
                'schema' => AtlasHumanKnowledgeSurfaceService::SCHEMA,
                'repo_canon' => [
                    'verdict' => $repoCanon['verdict'],
                    'badge' => $repoCanon['authority']['badge'],
                    'tier' => $repoCanon['authority']['tier'],
                ],
                'vault_note' => [
                    'verdict' => $vaultNote['verdict'],
                    'badge' => $vaultNote['authority']['badge'],
                    'tier' => $vaultNote['authority']['tier'],
                ],
                'sourceless_note' => [
                    'verdict' => $noSource['verdict'],
                    'reasons' => $noSource['reasons'],
                ],
                'overwrite_without_decision' => [
                    'verdict' => $overwriteAttempt['verdict'],
                    'reasons' => $overwriteAttempt['reasons'],
                ],
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'schema' => AtlasHumanKnowledgeSurfaceService::SCHEMA,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
