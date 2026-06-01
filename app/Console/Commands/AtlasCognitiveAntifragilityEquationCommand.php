<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveAntifragilityEquationService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas Cognitive Antifragility Equation decider.
 *
 * Demonstrates the doc's core contracts on the documented 2026-Q2 snapshot:
 * the eight-component weighted M (weights total 1.0), the weighted provider N,
 * the composed Total = N x M emitted under atlas.antifragility.measurement.v1,
 * the antifragility window under a 5x provider leap, and the "Regras para IA"
 * proposal filter.
 *
 * @see docs/engineering-knowledge-base/atlas-cognitive-antifragility-equation.md
 */
final class AtlasCognitiveAntifragilityEquationCommand extends Command
{
    protected $signature = 'atlas:aaeos:cognitive-antifragility-equation {--json : Machine-readable JSON output}';

    protected $description = 'Decide the Atlas Cognitive Antifragility Equation: weighted M components, provider N, Total = N x M, the provider-leap window and the proposal multiplier filter.';

    public function handle(AtlasCognitiveAntifragilityEquationService $service): int
    {
        try {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

            // Safe demonstration metrics that reproduce the documented snapshot M=1.4.
            $metrics = [
                AtlasCognitiveAntifragilityEquationService::COMPONENT_MEMORY => 1.0,
                AtlasCognitiveAntifragilityEquationService::COMPONENT_EVIDENCE => 1.0,
                AtlasCognitiveAntifragilityEquationService::COMPONENT_COMPOUNDING => 1.0,
                AtlasCognitiveAntifragilityEquationService::COMPONENT_SELF_CONSTRUCTION => 1.0,
                AtlasCognitiveAntifragilityEquationService::COMPONENT_MULTI_AGENT => 5.0,
                AtlasCognitiveAntifragilityEquationService::COMPONENT_MULTI_PROVIDER => 1.0,
                AtlasCognitiveAntifragilityEquationService::COMPONENT_SOVEREIGNTY => 1.0,
                AtlasCognitiveAntifragilityEquationService::COMPONENT_GOVERNANCE => 1.0,
            ];

            $providers = [
                ['id' => 'claude_code', 'score' => AtlasCognitiveAntifragilityEquationService::SNAPSHOT_N],
            ];

            $measurement = $service->measure(
                $providers,
                $metrics,
                $now,
                AtlasCognitiveAntifragilityEquationService::SNAPSHOT_TREND_30D,
            );

            $result = [
                'total_weight' => $service->totalWeight(),
                'measurement' => $measurement,
                'provider_leap_5x' => $service->applyProviderLeap(
                    AtlasCognitiveAntifragilityEquationService::SNAPSHOT_N,
                    2.0,
                    5.0,
                ),
                'proposal_multiplies' => $service->evaluateProposal(true, false),
                'proposal_point_opt' => $service->evaluateProposal(false, false),
            ];
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
