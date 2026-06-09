<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiLayer0GlossaryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Layer 0 Constitution and Glossary CLI.
 *
 *   php artisan atlas:aaeos:ai-layer-0-glossary
 *     [--term=Atlas AI]        // classify a canonical glossary term
 *     [--used-as=provider]     // with --term: detect a forbidden conflation
 *     [--legacy=CLAUDE.md]     // classify a legacy term -> documented treatment
 *     [--subject=identity and canonical terms] // resolve the authority doc
 *     [--json]
 *
 * Read-only, deterministic. With no flags it emits the full Layer 0 registry
 * (authority table + 7 invariants + glossary + legacy terms + promotion rules).
 *
 * @see docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md
 */
class AtlasAiLayer0GlossaryCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-layer-0-glossary
        {--term= : canonical glossary term to classify}
        {--used-as= : with --term, the concept it is being used as (conflation check)}
        {--legacy= : legacy term to classify into its documented treatment}
        {--subject= : governance subject to resolve to its canonical authority doc}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI · Layer 0 constitution + glossary (authority routing, term classification, conflation guard, promotion gate).';

    public function handle(AtlasAiLayer0GlossaryService $service): int
    {
        try {
            $result = $this->buildResult($service);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'ai_layer_0_glossary_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildResult(AtlasAiLayer0GlossaryService $service): array
    {
        $term = $this->stringOption('term');
        $usedAs = $this->stringOption('used-as');
        $requestedLegacyTerm = $this->stringOption('legacy');
        $subject = $this->stringOption('subject');

        if ($term !== '' && $usedAs !== '') {
            return ['kind' => 'conflation', 'data' => $service->detectConflation($term, $usedAs)];
        }

        if ($term !== '') {
            return ['kind' => 'term', 'data' => $service->classifyTerm($term)];
        }

        if ($requestedLegacyTerm !== '') {
            return ['kind' => 'legacy_term', 'data' => $service->classifyLegacyTerm($requestedLegacyTerm)];
        }

        if ($subject !== '') {
            return [
                'kind' => 'authority',
                'data' => [
                    'subject' => $subject,
                    'authority' => $service->authorityFor($subject),
                ],
            ];
        }

        return ['kind' => 'registry', 'data' => $service->registry()];
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
