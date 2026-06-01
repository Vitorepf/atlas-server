<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasStateOfArtResearchMapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Exercises the State Of Art Research Map runtime: takes a sample external
 * technique, classifies its research area against the documented posture table,
 * evaluates the seven-item Research Promotion Rule and screens it against the six
 * Guardrails, then prints whether the technique is implementable or stays as
 * research.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/state-of-art-research-map.md
 */
class AtlasStateOfArtResearchMapCommand extends Command
{
    protected $signature = 'atlas:aaeos:state-of-art-research-map {--json}';

    protected $description = 'Resolve a research-map technique: classify posture, evaluate the 7-item promotion rule, screen guardrails.';

    public function handle(AtlasStateOfArtResearchMapService $map): int
    {
        try {
            // A realistic candidate: a long-contextual RAG technique that has done
            // most of its homework but is still missing an internal measurement.
            $result = $map->promote(
                area: 'long_contextual_rag',
                promotionChecklist: [
                    'ap_or_explicit_inclusion' => true,
                    'provider_safe_contract' => true,
                    'deterministic_fallback' => true,
                    'internal_measurement' => false,
                    'failure_modes' => true,
                    'evidence_replay' => true,
                    'gates_green' => true,
                ],
                guardrailFlags: [
                    'huge_window_masks_retrieval' => false,
                    'kv_compression_as_audit_memory' => false,
                    'raw_transcript_as_memory' => false,
                    'vector_before_privacy_filters' => false,
                    'abstractive_compress_without_original' => false,
                    'external_measure_as_proof' => false,
                ],
            );

            $payload = $result + ['generated_at' => now()->toJSON()];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('area', (string) ($result['area']['area'] ?? '-'));
            $this->components->twoColumnDetail('posture', (string) ($result['area']['posture'] ?? '-'));
            $this->components->twoColumnDetail('promotion items', $result['promotion']['satisfied_count'].'/'.$result['promotion']['required_count']);
            $this->components->twoColumnDetail('guardrails clear', $result['guardrails']['allowed'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('status', $result['status']);
            $this->components->twoColumnDetail('implementable', $result['implementable'] ? 'yes' : 'no');

            if ($result['blocking_reasons'] !== []) {
                $this->warn('blocking: '.implode(', ', $result['blocking_reasons']));
            } else {
                $this->info('no blocking reasons');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema' => AtlasStateOfArtResearchMapService::SCHEMA_VERSION,
                'ok' => false,
                'error' => $e->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
