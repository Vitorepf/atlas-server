<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver;

use RuntimeException;

final class AtlasLoopIntentAmbiguityClarifierProposer
{
    /**
     * @param  array{intent_id:string,text:string,enumerated_tokens?:list<string>}  $capturedIntent
     * @param  list<array{ambiguity_finding_id:string,dimension:string,source_span:string}>  $ambiguityFindings
     * @param  list<array{dimension?:string,resolution?:string,value?:string}>  $ledgerSnapshot
     * @return array<string,array<string,mixed>>
     */
    public function propose(array $capturedIntent, array $ambiguityFindings, array $ledgerSnapshot): array
    {
        $intentId = (string) ($capturedIntent['intent_id'] ?? '');
        $intentText = (string) ($capturedIntent['raw_text'] ?? $capturedIntent['text'] ?? '');
        $enumeratedTokens = array_values(array_filter((array) ($capturedIntent['enumerated_tokens'] ?? []), 'is_string'));

        $packets = [];
        foreach ($ambiguityFindings as $finding) {
            $findingId = (string) ($finding['ambiguity_finding_id'] ?? '');
            $dimension = (string) ($finding['dimension'] ?? '');
            $span = (string) ($finding['source_span'] ?? '');

            if ($span === '' || strpos($intentText, $span) === false) {
                throw new RuntimeException('ambiguity_source_span_not_found_verbatim');
            }

            $packetId = hash('sha256', $intentId.'|'.$findingId);
            $packets[$packetId] = [
                'ambiguity_finding_id' => $findingId,
                'candidates' => $this->candidateResolutions($dimension, $ledgerSnapshot, $enumeratedTokens),
                'dimension' => $dimension,
                'i_do_not_know' => 'I do not know',
                'question' => $this->canonicalQuestion($dimension),
                'span' => $span,
            ];
        }

        ksort($packets, SORT_STRING);

        return $packets;
    }

    /**
     * @param  list<array{dimension?:string,resolution?:string,value?:string}>  $ledgerSnapshot
     * @param  list<string>  $enumeratedTokens
     * @return list<string>
     */
    private function candidateResolutions(string $dimension, array $ledgerSnapshot, array $enumeratedTokens): array
    {
        $candidates = [];

        foreach ($ledgerSnapshot as $entry) {
            if ((string) ($entry['dimension'] ?? '') !== $dimension) {
                continue;
            }

            $value = (string) ($entry['resolution'] ?? $entry['value'] ?? '');
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        foreach ($enumeratedTokens as $token) {
            $trimmed = trim($token);
            if ($trimmed !== '') {
                $candidates[] = $trimmed;
            }
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates, SORT_STRING);

        return $candidates;
    }

    private function canonicalQuestion(string $dimension): string
    {
        return match ($dimension) {
            'vague-term' => 'What concrete meaning should this vague term resolve to?',
            'dangling-verb' => 'What concrete action should this dangling verb resolve to?',
            'structural-sparsity' => 'What missing structural detail should be made explicit?',
            'scope-undefined' => 'What concrete scope boundary should apply here?',
            'success-criterion-missing' => 'What explicit success criterion should apply here?',
            default => 'What explicit clarification should resolve this ambiguity?',
        };
    }
}
