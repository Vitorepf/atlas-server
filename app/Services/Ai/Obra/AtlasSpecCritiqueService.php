<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use Throwable;

/**
 * WO-17-T2 — deterministic adversarial spec critique (P3, NO LLM).
 *
 * On the #8, 10 refutations only surfaced DURING execution — the late collisions the
 * baseline (T0.4) counts. This is the event-driven check that fires PER SPEC (immune
 * to any scheduler): armed with the brain's refutations + governed decisions, it
 * matches a spec's text against them BEFORE a line is written and flags "isto já foi
 * refutado / contradiz uma decisão". Query-aware recall (T0.2 forwarded the question)
 * makes the match viable. Deterministic + provider-safe + fail-open (a brain outage
 * yields a clean-but-honest "unmeasured", never a throw).
 */
final class AtlasSpecCritiqueService
{
    public const SCHEMA = 'atlas.obra.spec_critique.v1';

    public function __construct(
        private readonly AtlasHybridMemoryRetrievalService $memory,
    ) {}

    /**
     * @return array<string,mixed> {schema, verdict, refutations, decisions, concerns_count}
     */
    public function critique(string $specText, string $workspace = 'atlas-server'): array
    {
        $specText = trim($specText);
        if ($specText === '') {
            return $this->result('empty', [], []);
        }

        $refutations = $this->recallTitles($specText, $workspace, 'refutation_memory', 5);
        $decisions = $this->recallTitles($specText, $workspace, 'decision', 5);

        // A refutation that shares strong lexical signal with the spec is a CONCERN:
        // the spec is about to walk into something the brain already refuted.
        $specTokens = $this->tokens($specText);
        $concerns = array_values(array_filter(
            $refutations,
            fn (string $title): bool => $this->overlaps($specTokens, $this->tokens($title)),
        ));

        $verdict = $concerns !== [] ? 'concerns_found' : ($refutations === [] && $decisions === [] ? 'no_brain_signal' : 'governed');

        return $this->result($verdict, $refutations, $decisions, $concerns);
    }

    /**
     * @param  list<string>  $refutations
     * @param  list<string>  $decisions
     * @param  list<string>  $concerns
     * @return array<string,mixed>
     */
    private function result(string $verdict, array $refutations, array $decisions, array $concerns = []): array
    {
        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'refutations' => $refutations,
            'decisions' => $decisions,
            'concerns' => $concerns,
            'concerns_count' => count($concerns),
        ];
    }

    /** @return list<string> */
    private function recallTitles(string $query, string $workspace, string $type, int $limit): array
    {
        try {
            $recall = $this->memory->recall(
                $query,
                ['workspace' => $workspace],
                ['memory_type' => $type],
                ['limit' => $limit, 'requester' => 'spec_critique', 'include_verbatim' => false, 'include_semantic' => false, 'include_compounding' => false],
            );

            return array_values(array_filter(array_map(
                static fn ($row): string => trim((string) (is_array($row) ? ($row['title'] ?? '') : '')),
                (array) ($recall['recall'] ?? []),
            ), static fn (string $t): bool => $t !== ''));
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/[^A-Za-z0-9:_]+/', mb_strtolower($text)) ?: [],
            static fn (string $t): bool => mb_strlen($t) >= 4,
        )));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function overlaps(array $a, array $b): bool
    {
        if ($b === []) {
            return false;
        }
        $shared = count(array_intersect($a, $b));

        // A meaningful collision: at least 2 shared salient tokens (a single common
        // word is noise; two is a real thematic overlap with a refutation).
        return $shared >= 2;
    }
}
