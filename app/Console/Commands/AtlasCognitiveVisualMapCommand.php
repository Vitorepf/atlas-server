<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveVisualMapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cognitive Plane Visual Map validator CLI.
 *
 *   php artisan atlas:aaeos:cognitive-visual-map [--json]
 *
 * With no options it validates a canonical, fully-compliant proposed diagram
 * (expected valid=true), demonstrates the "Conflito" precedence (principles
 * beats the image) and emits the canonical reference texts. Read-only,
 * deterministic — never touches a provider, DB or filesystem.
 *
 * @see docs/engineering-knowledge-base/cognitive/visual-map.md
 */
class AtlasCognitiveVisualMapCommand extends Command
{
    protected $signature = 'atlas:aaeos:cognitive-visual-map {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · validate a proposed Cognitive Plane diagram against the canonical visual map.';

    public function handle(AtlasCognitiveVisualMapService $service): int
    {
        try {
            $proposal = [
                'pillars' => AtlasCognitiveVisualMapService::PILLARS,
                'movements' => AtlasCognitiveVisualMapService::MOVEMENTS,
                'pipeline_stages' => AtlasCognitiveVisualMapService::PIPELINE_STAGES,
                'elements' => AtlasCognitiveVisualMapService::REQUIRED_ELEMENTS,
                'learning_gated' => true,
                'forbidden' => [],
                'title' => AtlasCognitiveVisualMapService::CANONICAL_TITLE,
                'footer' => AtlasCognitiveVisualMapService::CANONICAL_FOOTER,
            ];

            $result = [
                'diagram' => $service->validateDiagram($proposal),
                'conflict' => $service->resolveConflict('principles', 'image'),
                'texts' => $service->canonicalTexts(),
            ];

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'cognitive_visual_map_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
