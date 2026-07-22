<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/**
 * QUATERNITY · INTENT INGEST — converts an {@see OperatorIntentMessage} into a structured
 * {@see OperatorIntentFact} (verb + normalized object + ordered explicit constraints) using a DETERMINISTIC
 * PT-BR/EN lexicon (regex + verb dictionary). NO LLM, NO network — same input bytes always yield the same fact.
 *
 * A message with no recognizable verb OR no extractable object is REJECTED with a {@see VagueIntentRejection}
 * naming the failing axis, so the operator gets an honest "sharpen this" signal instead of a fabricated guess.
 */
final class AtlasLoopOperatorIntentFactExtractor
{
    /** Verb keyword (PT-BR + EN) => canonical verb. Scanned in message order; the earliest match wins. */
    private const VERB_LEXICON = [
        'tira' => OperatorIntentVerb::REMOVE, 'tirar' => OperatorIntentVerb::REMOVE, 'tire' => OperatorIntentVerb::REMOVE,
        'remove' => OperatorIntentVerb::REMOVE, 'remover' => OperatorIntentVerb::REMOVE, 'remova' => OperatorIntentVerb::REMOVE,
        'exclui' => OperatorIntentVerb::REMOVE, 'excluir' => OperatorIntentVerb::REMOVE, 'delete' => OperatorIntentVerb::REMOVE, 'drop' => OperatorIntentVerb::REMOVE,
        'adiciona' => OperatorIntentVerb::ADD, 'adicionar' => OperatorIntentVerb::ADD, 'cria' => OperatorIntentVerb::ADD, 'criar' => OperatorIntentVerb::ADD,
        'add' => OperatorIntentVerb::ADD, 'create' => OperatorIntentVerb::ADD, 'build' => OperatorIntentVerb::ADD, 'construir' => OperatorIntentVerb::ADD,
        'muda' => OperatorIntentVerb::CHANGE, 'mudar' => OperatorIntentVerb::CHANGE, 'altera' => OperatorIntentVerb::CHANGE, 'alterar' => OperatorIntentVerb::CHANGE,
        'troca' => OperatorIntentVerb::CHANGE, 'trocar' => OperatorIntentVerb::CHANGE, 'change' => OperatorIntentVerb::CHANGE, 'update' => OperatorIntentVerb::CHANGE,
        'atualiza' => OperatorIntentVerb::CHANGE, 'atualizar' => OperatorIntentVerb::CHANGE, 'edita' => OperatorIntentVerb::CHANGE, 'editar' => OperatorIntentVerb::CHANGE,
        'foco' => OperatorIntentVerb::FOCUS, 'foca' => OperatorIntentVerb::FOCUS, 'focar' => OperatorIntentVerb::FOCUS, 'focus' => OperatorIntentVerb::FOCUS,
        'prioriza' => OperatorIntentVerb::FOCUS, 'priorizar' => OperatorIntentVerb::FOCUS,
        'proibido' => OperatorIntentVerb::FORBID, 'proibir' => OperatorIntentVerb::FORBID, 'forbid' => OperatorIntentVerb::FORBID,
        'bloqueia' => OperatorIntentVerb::FORBID, 'bloquear' => OperatorIntentVerb::FORBID,
        'escala' => OperatorIntentVerb::ESCALATE, 'escalar' => OperatorIntentVerb::ESCALATE, 'escalate' => OperatorIntentVerb::ESCALATE,
        'aumenta' => OperatorIntentVerb::ESCALATE, 'aumentar' => OperatorIntentVerb::ESCALATE,
        'pergunta' => OperatorIntentVerb::ASK, 'pergunte' => OperatorIntentVerb::ASK, 'ask' => OperatorIntentVerb::ASK,
        'observa' => OperatorIntentVerb::OBSERVE, 'observar' => OperatorIntentVerb::OBSERVE, 'observe' => OperatorIntentVerb::OBSERVE,
        'monitora' => OperatorIntentVerb::OBSERVE, 'monitorar' => OperatorIntentVerb::OBSERVE, 'watch' => OperatorIntentVerb::OBSERVE,
    ];

    /** Prepositions / articles skipped when isolating the object noun phrase. */
    private const STOPWORDS = [
        'do', 'da', 'dos', 'das', 'de', 'no', 'na', 'nos', 'nas', 'num', 'numa', 'em', 'ao', 'aos', 'à', 'às',
        'o', 'a', 'os', 'as', 'um', 'uma', 'por', 'pra', 'para',
        'the', 'an', 'to', 'from', 'in', 'on', 'of', 'for', 'with',
    ];

    public function extract(OperatorIntentMessage $message): OperatorIntentFact|VagueIntentRejection
    {
        $text = $this->normalize($message->rawText);

        [$objectText, $constraints] = $this->splitConstraints($text);

        [$verb, $verbIndex] = $this->findVerb($objectText);
        if ($verb === null) {
            // No verb to anchor an object on ⇒ both axes are vague.
            return VagueIntentRejection::both();
        }

        $object = $this->extractObject($objectText, $verbIndex);
        if ($object === '') {
            return VagueIntentRejection::objectMissing();
        }

        return new OperatorIntentFact($verb, $object, $constraints);
    }

    private function normalize(string $raw): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($raw)) ?? '');
    }

    /**
     * Pull explicit constraint clauses ("sem ...", "nunca ...", "até ...", "antes de ...") in order and return
     * the main clause (everything before the first constraint) for verb/object extraction.
     *
     * @return array{0:string, 1:list<string>}
     */
    private function splitConstraints(string $text): array
    {
        if (! preg_match_all('/\b(?:sem|nunca|at[eé]|antes\s+de)\b/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return [$text, []];
        }

        $starts = array_map(static fn (array $x): int => (int) $x[1], $m[0]);
        $constraints = [];
        foreach ($starts as $i => $start) {
            $end = $starts[$i + 1] ?? strlen($text);
            $clause = trim(substr($text, $start, $end - $start));
            if ($clause !== '') {
                $constraints[] = $clause;
            }
        }

        $objectText = trim(substr($text, 0, $starts[0]));

        return [$objectText, array_values($constraints)];
    }

    /**
     * @return array{0:?OperatorIntentVerb, 1:int}  the verb + its token index (-1 when absent)
     */
    private function findVerb(string $objectText): array
    {
        $tokens = $this->tokens($objectText);
        foreach ($tokens as $i => $token) {
            if (isset(self::VERB_LEXICON[$token])) {
                return [self::VERB_LEXICON[$token], $i];
            }
        }

        return [null, -1];
    }

    private function extractObject(string $objectText, int $verbIndex): string
    {
        $tokens = $this->tokens($objectText);
        $collected = [];
        for ($i = $verbIndex + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $isStop = in_array($token, self::STOPWORDS, true);
            if ($collected === []) {
                if ($isStop) {
                    continue; // skip leading prepositions/articles before the noun phrase begins
                }
                $collected[] = $token;

                continue;
            }
            if ($isStop) {
                break; // the noun phrase ends at the next preposition/connector
            }
            $collected[] = $token;
        }

        return implode(' ', $collected);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/u', $text) ?: [], static fn (string $t): bool => $t !== ''));
    }
}
