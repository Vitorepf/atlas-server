<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ROLE-TOKEN SEMANTIC DISAMBIGUATOR — kills the false-twin bug where the replenisher's orphan-wiring search
 * matched two SEMANTICALLY DIFFERENT classes that merely share a role suffix (TransferGate vs ResourceGate —
 * both end in 'gate'), pointing a worker at the wrong integration site.
 *
 * A candidate sibling is accepted ONLY when it shares AT LEAST TWO of three independent signals with the
 * orphan: (a) a shared docblock semantic token (role suffixes stripped), (b) the same namespace tail (beyond
 * the role token), (c) an overlapping subsystem root between their caller patterns. One shared signal (e.g.
 * just the role token's namespace) is NOT enough — that is exactly the collapse the bug exploited.
 */
final class AtlasLoopCortexRoleTokenSemanticDisambiguator
{
    /** Function words + generic role suffixes — never count as a distinguishing semantic token. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'its', 'into', 'from', 'when', 'what', 'which', 'are',
        'was', 'has', 'but', 'not', 'all', 'any', 'can', 'may', 'per', 'via', 'use', 'used', 'uses', 'only',
        'gate', 'service', 'bridge', 'ledger', 'repository', 'reader', 'writer', 'manager', 'handler', 'factory',
    ];

    /**
     * @param  array{fqcn?:string, docblock?:string, subsystem_root?:string, caller_subsystem_roots?:list<string>}  $orphan
     * @param  array{fqcn?:string, docblock?:string, subsystem_root?:string, caller_subsystem_roots?:list<string>}  $sibling
     */
    public function accepts(array $orphan, array $sibling): bool
    {
        return $this->signalCount($orphan, $sibling) >= 2;
    }

    /**
     * @param  array<string,mixed>  $orphan
     * @param  array<string,mixed>  $sibling
     */
    public function signalCount(array $orphan, array $sibling): int
    {
        $count = 0;

        // (a) shared docblock semantic token.
        if (array_intersect(
            $this->semanticTokens((string) ($orphan['docblock'] ?? '')),
            $this->semanticTokens((string) ($sibling['docblock'] ?? '')),
        ) !== []) {
            $count++;
        }

        // (b) identical namespace tail (beyond the role token).
        $orphanNs = $this->namespaceTail((string) ($orphan['fqcn'] ?? ''));
        if ($orphanNs !== '' && $orphanNs === $this->namespaceTail((string) ($sibling['fqcn'] ?? ''))) {
            $count++;
        }

        // (c) overlapping subsystem root between caller patterns / locations.
        if (array_intersect($this->roots($orphan), $this->roots($sibling)) !== []) {
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string,mixed>  $descriptor
     * @return list<string>
     */
    private function roots(array $descriptor): array
    {
        $roots = [];
        $own = trim((string) ($descriptor['subsystem_root'] ?? ''));
        if ($own !== '') {
            $roots[] = $own;
        }
        foreach ((array) ($descriptor['caller_subsystem_roots'] ?? []) as $root) {
            $root = trim((string) $root);
            if ($root !== '') {
                $roots[] = $root;
            }
        }

        return array_values(array_unique($roots));
    }

    private function namespaceTail(string $fqcn): string
    {
        $parts = array_values(array_filter(explode('\\', trim($fqcn, '\\')), static fn (string $p): bool => $p !== ''));
        if (count($parts) < 2) {
            return '';
        }
        array_pop($parts); // drop the class name; keep the namespace

        return implode('\\', $parts);
    }

    /**
     * @return list<string>
     */
    private function semanticTokens(string $text): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $text) ?? $text;
        preg_match_all('/[a-z]+/', strtolower($spaced), $matches);

        $set = [];
        foreach ($matches[0] as $token) {
            if (strlen($token) >= 4 && ! in_array($token, self::STOPWORDS, true)) {
                $set[$token] = true;
            }
        }

        return array_keys($set);
    }
}
