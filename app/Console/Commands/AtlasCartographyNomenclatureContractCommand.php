<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCartographyNomenclatureContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Cartography Nomenclature Contract decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-cartography-nomenclature-contract [--json]
 *
 * Exercises the nomenclature decider on safe reference frontmatter: a piece that
 * declares a real maturity leap (Self-Construction OS -> Self-Programming OS), an
 * Atlas-Vox-style piece that declares versions V0/V3/V4/V6 with no patamar (must
 * read "patamar ainda nao declarado" + "versao declarada, nao patamar"), an
 * attempt to feed forbidden fields into Patamares (must be rejected), and the
 * Vox-vs-Voice-Realtime routing. Read-only and deterministic; it never reads a
 * file, renders a modal, mutates state or emits evidence.
 *
 * @see docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
 */
class AtlasCartographyNomenclatureContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-cartography-nomenclature-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Cartography Nomenclature Contract · separates patamar / versao / camada / fonte for the modal: classifies frontmatter fields, rejects patamar inference, resolves the undeclared-patamar sentinel.';

    public function handle(AtlasCartographyNomenclatureContractService $service): int
    {
        try {
            // A piece that declares a real maturity leap.
            $patamarPiece = [
                'patamar_current' => 'Self-Construction OS',
                'patamar_next' => 'Self-Programming OS',
                'graph_layer' => 'system',
                'flows_to' => ['atlas-cartography'],
            ];

            // An Atlas-Vox-style piece: versions, no patamar declared.
            $voxPiece = [
                'version_family' => 'Atlas Vox',
                'versions' => ['V0', 'V3', 'V4', 'V6'],
                'graph_layer' => 'surface',
            ];

            $patamares = $service->resolvePatamares($patamarPiece);
            $voxPatamares = $service->resolvePatamares($voxPiece);
            $voxVersoes = $service->resolveVersoes($voxPiece);

            // Attempt to feed forbidden fields into Patamares -> must be rejected.
            $inferenceAttempt = $service->assertPatamares([
                'patamar_current',
                'graph_layer',
                'flows_to',
                'versions',
            ]);

            $routing = [
                'vox' => $service->routeVoiceTopic('improve Atlas Vox dictation'),
                'voice_realtime' => $service->routeVoiceTopic('fix the Voice Realtime LiveKit turn handling'),
                'mixed' => $service->routeVoiceTopic('reconcile Atlas Vox with the Voice Realtime surface'),
            ];

            $payload = [
                'ok' => true,
                'schema' => AtlasCartographyNomenclatureContractService::SCHEMA,
                'modal_layers' => $service->modalLayers(),
                'patamar_piece' => [
                    'declared' => $patamares['declared'],
                    'display' => $patamares['display'],
                    'next' => $patamares['patamar_next'],
                ],
                'vox_piece' => [
                    'patamar_declared' => $voxPatamares['declared'],
                    'patamar_display' => $voxPatamares['display'],
                    'versions' => $voxVersoes['versions'],
                    'version_note' => $voxVersoes['note'],
                ],
                'inference_attempt' => [
                    'verdict' => $inferenceAttempt['verdict'],
                    'rejected' => $inferenceAttempt['rejected'],
                    'legal' => $inferenceAttempt['legal'],
                ],
                'voice_routing' => $routing,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'schema' => AtlasCartographyNomenclatureContractService::SCHEMA,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }
    }
}
