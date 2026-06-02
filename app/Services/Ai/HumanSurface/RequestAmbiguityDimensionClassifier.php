<?php

declare(strict_types=1);

namespace App\Services\Ai\HumanSurface;

/**
 * Deterministic, pure classifier that names the single most relevant ambiguity
 * dimension of a raw natural-language request before it enters the human
 * surface clarification loop. No I/O, no clock, no randomness: every returned
 * field is computed from the request string via fixed lexical rules so the
 * result is reproducible and auditable.
 *
 * Resolution is a first-match priority cascade:
 *   missing_action > missing_target > competing_intents > unbounded_scope > none
 */
final class RequestAmbiguityDimensionClassifier
{
    /**
     * Verb families. A request whose verbs span >= 2 families is competing.
     *
     * @var array<string,array<int,string>>
     */
    private const VERB_FAMILIES = [
        'build' => ['implementa', 'refatora', 'corrige', 'cria', 'escreve'],
        'research' => ['pesquisa', 'investiga', 'analisa'],
    ];

    /**
     * Object / reference cues that signal a concrete target is named.
     *
     * @var array<int,string>
     */
    private const OBJECT_CUES = ['arquivo', 'teste', 'rota', 'o', 'a', 'em'];

    /**
     * Explicit unbounded-scope markers (besides the plural-without-path rule).
     *
     * @var array<int,string>
     */
    private const UNBOUNDED_MARKERS = ['tudo', 'geral'];

    private const CONFIDENCE_BASE = 0.6;

    private const CONFIDENCE_STEP = 0.1;

    private const CONFIDENCE_CAP = 0.95;

    /**
     * @return array{dimension: string, confidence: float, evidence: array<int,string>}
     */
    public function classify(string $request): array
    {
        $tokens = $this->tokenize($request);

        $verbTokens = $this->matchVerbTokens($tokens);
        $families = $this->matchedFamilies($tokens);
        $objectCues = $this->matchObjectCues($tokens);
        $hasPathToken = $this->hasPathToken($tokens);
        $unboundedTokens = $this->matchUnboundedTokens($tokens, $hasPathToken);

        $hasVerb = $verbTokens !== [];
        $hasObjectCue = $objectCues !== [];
        $isUnbounded = $unboundedTokens !== [];

        // (b) >= 1 object cue AND no verb -> missing_action (highest priority).
        if ($hasObjectCue && ! $hasVerb) {
            return $this->result('missing_action', $objectCues);
        }

        // (a) >= 1 verb AND no object cue, and the request is not unbounded.
        if ($hasVerb && ! $hasObjectCue && ! $isUnbounded) {
            return $this->result('missing_target', $verbTokens);
        }

        // (c) verbs drawn from >= 2 distinct families -> competing_intents.
        if (count($families) >= 2) {
            return $this->result('competing_intents', $verbTokens);
        }

        // (d) verb + unbounded marker AND no path token -> unbounded_scope.
        if ($hasVerb && $isUnbounded && ! $hasPathToken) {
            return $this->result('unbounded_scope', $this->mergeEvidence($verbTokens, $unboundedTokens));
        }

        // (e) else -> none (no ambiguity dimension dominates).
        return [
            'dimension' => 'none',
            'confidence' => self::CONFIDENCE_BASE,
            'evidence' => [],
        ];
    }

    /**
     * @param  array<int,string>  $evidence
     * @return array{dimension: string, confidence: float, evidence: array<int,string>}
     */
    private function result(string $dimension, array $evidence): array
    {
        return [
            'dimension' => $dimension,
            'confidence' => $this->confidence($evidence),
            'evidence' => $evidence,
        ];
    }

    /**
     * Confidence grows from a base with each corroborating cue, capped.
     *
     * @param  array<int,string>  $evidence
     */
    private function confidence(array $evidence): float
    {
        $corroborating = max(0, count($evidence) - 1);
        $value = self::CONFIDENCE_BASE + self::CONFIDENCE_STEP * $corroborating;

        return min(self::CONFIDENCE_CAP, round($value, 4));
    }

    /**
     * @return array<int,string>
     */
    private function tokenize(string $request): array
    {
        $lowered = mb_strtolower($request);
        $parts = preg_split('/\s+/', trim($lowered)) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            $clean = trim($part, ",;:!?()[]{}\"'");
            if ($clean !== '') {
                $tokens[] = $clean;
            }
        }

        return $tokens;
    }

    /**
     * @param  array<int,string>  $tokens
     * @return array<int,string>
     */
    private function matchVerbTokens(array $tokens): array
    {
        $verbs = [];
        foreach (self::VERB_FAMILIES as $family) {
            foreach ($family as $verb) {
                $verbs[] = $verb;
            }
        }

        return $this->retainInOrder($tokens, $verbs);
    }

    /**
     * @param  array<int,string>  $tokens
     * @return array<int,string>
     */
    private function matchedFamilies(array $tokens): array
    {
        $families = [];
        foreach (self::VERB_FAMILIES as $name => $family) {
            foreach ($tokens as $token) {
                if (in_array($token, $family, true)) {
                    $families[$name] = true;
                    break;
                }
            }
        }

        return array_keys($families);
    }

    /**
     * @param  array<int,string>  $tokens
     * @return array<int,string>
     */
    private function matchObjectCues(array $tokens): array
    {
        return $this->retainInOrder($tokens, self::OBJECT_CUES);
    }

    /**
     * Unbounded markers: explicit tudo/geral, or a plural token when no path
     * token scopes the request.
     *
     * @param  array<int,string>  $tokens
     * @return array<int,string>
     */
    private function matchUnboundedTokens(array $tokens, bool $hasPathToken): array
    {
        $matched = [];
        foreach ($tokens as $token) {
            if (in_array($token, $matched, true)) {
                continue;
            }

            if (in_array($token, self::UNBOUNDED_MARKERS, true)) {
                $matched[] = $token;

                continue;
            }

            if (! $hasPathToken && $this->isBarePlural($token)) {
                $matched[] = $token;
            }
        }

        return $matched;
    }

    private function isBarePlural(string $token): bool
    {
        if ($this->isPathToken($token)) {
            return false;
        }

        if (mb_strlen($token) < 3 || substr($token, -1) !== 's') {
            return false;
        }

        if (in_array($token, self::OBJECT_CUES, true)) {
            return false;
        }

        foreach (self::VERB_FAMILIES as $family) {
            if (in_array($token, $family, true)) {
                return false;
            }
        }

        return ctype_alpha($token);
    }

    /**
     * @param  array<int,string>  $tokens
     */
    private function hasPathToken(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($this->isPathToken($token)) {
                return true;
            }
        }

        return false;
    }

    private function isPathToken(string $token): bool
    {
        return str_contains($token, '/') || str_contains($token, '.php');
    }

    /**
     * Keep, in request order and de-duplicated, the tokens that belong to the
     * given lexicon.
     *
     * @param  array<int,string>  $tokens
     * @param  array<int,string>  $lexicon
     * @return array<int,string>
     */
    private function retainInOrder(array $tokens, array $lexicon): array
    {
        $kept = [];
        foreach ($tokens as $token) {
            if (in_array($token, $lexicon, true) && ! in_array($token, $kept, true)) {
                $kept[] = $token;
            }
        }

        return $kept;
    }

    /**
     * @param  array<int,string>  $primary
     * @param  array<int,string>  $secondary
     * @return array<int,string>
     */
    private function mergeEvidence(array $primary, array $secondary): array
    {
        $merged = $primary;
        foreach ($secondary as $token) {
            if (! in_array($token, $merged, true)) {
                $merged[] = $token;
            }
        }

        return $merged;
    }
}
