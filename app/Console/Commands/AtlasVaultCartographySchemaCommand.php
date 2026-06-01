<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasVaultCartographySchemaService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Vault Cartography Schema (index) decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-vault-cartography-schema [--json]
 *
 * Exercises the index-level cartography router / auditor on safe reference pieces:
 * a healthy repo-sourced piece (resolves, opens via OS handler, passes the
 * non-negotiables) and a missing vault-sourced architectural piece (fails closed
 * to missing_source, open action disabled, breaches the non-negotiables). Shows
 * the content-class routing too. Read-only and deterministic; it never reads a
 * file, walks the vault, opens a source, writes a doc or emits evidence.
 *
 * @see docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
 */
class AtlasVaultCartographySchemaCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-vault-cartography-schema {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Vault Cartography Schema · index-level source-aware router/auditor: content-class routing, fail-closed resolution, source-aware open actions and the five non-negotiables.';

    public function handle(AtlasVaultCartographySchemaService $service): int
    {
        try {
            // Content-class routing per the Canon table.
            $routing = [
                'architecture' => $service->routeContentClass('architecture'),
                'philosophy' => $service->routeContentClass('philosophy'),
                'unknown' => $service->routeContentClass('weather'),
            ];

            // A healthy repo-sourced piece: present on disk, opens via OS handler,
            // passes every non-negotiable.
            $healthy = $service->assess([
                'graph_source' => 'repo',
                'graph_kind' => 'step',
                'source_present' => true,
                'expected_path' => 'docs/engineering-knowledge-base/atlas-ai-master-architecture.md',
                'extra_visual_fields' => ['graph_view', 'graph_layer'],
            ]);

            // A drifted piece: an architectural step claiming graph_source: vault,
            // missing on disk yet asking to render as truth. Fails closed and
            // breaches the non-negotiables.
            $drifted = $service->assess([
                'graph_source' => 'vault',
                'graph_kind' => 'step',
                'source_present' => false,
                'renders_as_truth' => true,
                'expected_path' => 'AtlasVault/should-not-be-here.md',
                'extra_visual_fields' => ['sync_status'],
            ]);

            $payload = [
                'ok' => true,
                'schema' => AtlasVaultCartographySchemaService::SCHEMA,
                'routing' => $routing,
                'healthy_sample' => [
                    'verdict' => $healthy['verdict'],
                    'resolution_status' => $healthy['resolution']['status'],
                    'open_handler' => $healthy['open_action']['handler'],
                    'blocking_reasons' => $healthy['blocking_reasons'],
                ],
                'drifted_sample' => [
                    'verdict' => $drifted['verdict'],
                    'resolution_status' => $drifted['resolution']['status'],
                    'open_handler' => $drifted['open_action']['handler'],
                    'blocking_reasons' => $drifted['blocking_reasons'],
                ],
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'schema' => AtlasVaultCartographySchemaService::SCHEMA,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
