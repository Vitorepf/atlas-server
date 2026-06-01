<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiFlowVisualMapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Flow Visual Map validator CLI.
 *
 *   php artisan atlas:aaeos:ai-flow-visual-map
 *     [--order=surface,atlas-input,...]   // comma-separated proposed stage ids
 *     [--domains=programming,blackink,...] // nodes drawn as domains (audit)
 *     [--json]
 *
 * With no options it validates the canonical V3 flow (expected valid=true) and
 * audits a sample Domain Plane. Read-only, deterministic.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
 */
class AtlasAiFlowVisualMapCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-flow-visual-map
        {--order= : comma-separated proposed stage ids to validate against the canonical V3 order}
        {--domains= : comma-separated nodes drawn as domains, audited against the Domain Plane spec}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · validate a proposed Atlas AI flow diagram against the canonical V3 visual map.';

    public function handle(AtlasAiFlowVisualMapService $service): int
    {
        try {
            $order = $this->csv($this->option('order'));
            if ($order === []) {
                $order = AtlasAiFlowVisualMapService::CANONICAL_FLOW;
            }

            $domains = $this->csv($this->option('domains'));
            if ($domains === []) {
                $domains = array_merge(
                    AtlasAiFlowVisualMapService::READY_DOMAINS,
                    ['blackink', 'python'], // intentionally include misplacements
                );
            }

            $result = [
                'flow' => $service->validateFlow($order),
                'domain_plane' => $service->auditDomainPlane($domains),
            ];

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'ai_flow_visual_map_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function csv(mixed $opt): array
    {
        if (! is_string($opt) || trim($opt) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $opt)),
            static fn ($v) => $v !== '',
        ));
    }
}
