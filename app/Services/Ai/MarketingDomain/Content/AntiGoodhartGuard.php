<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * AntiGoodhartGuard — the inverse of the scorer. Catches copy that GAMES the libraries: text that
 * fires markers without persuading (buzzword soup, marker stuffing, vacuous claims, repetition,
 * generic placeholders, AI-tells, claim density without proof). The Conversion OS measures what
 * is present; this guard measures what is HOLLOW. Without it, the amplifier would optimize for
 * marker count instead of conversion — exactly the Goodhart's law trap the loop must escape.
 * Returns a 0-100 hollowness score + the specific red flags found. Deterministic.
 */
class AntiGoodhartGuard
{
    /**
     * @return array{hollowness:int,grade:string,flags:array<int,array{key:string,name:string,detail:string}>}
     */
    public function inspect(string $copy): array
    {
        $flags = [];
        $text = mb_strtolower($copy);
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $wc = count($words);

        // 1. Buzzword soup — too many empty hype words per N words
        $buzz = ['amazing', 'incredible', 'revolutionary', 'breakthrough', 'game-changer',
            'cutting-edge', 'next-level', 'world-class', 'transform', 'unlock',
            'incrível', 'revolucionário', 'inovador', 'transformador', 'desbloqueie'];
        $buzzHits = 0;
        foreach ($buzz as $b) {
            $buzzHits += substr_count($text, $b);
        }
        if ($wc > 0 && ($buzzHits / max(1, $wc)) * 1000 > 12) {
            $flags[] = ['key' => 'buzzword_soup', 'name' => 'Sopa de buzzwords', 'detail' => "{$buzzHits} buzzwords em {$wc} palavras (densidade alta)."];
        }

        // 2. Claim density without proof — "X% improvement / Y× better" repeated with no source
        $claimMatches = preg_match_all('/\b\d{1,3}\s*(?:%|×|x|times|vezes)(?=\s|[.,!?;:)\]]|$)/iu', $text);
        $proofWords = ['study', 'estudo', 'published', 'publicado', 'university', 'universidade', 'dr.', 'doctor', 'clinic', 'nih', 'fda', 'harvard'];
        $proofHits = 0;
        foreach ($proofWords as $p) {
            $proofHits += substr_count($text, $p);
        }
        if ($claimMatches >= 3 && $proofHits === 0) {
            $flags[] = ['key' => 'claims_without_proof', 'name' => 'Claims sem prova', 'detail' => "{$claimMatches} alegações numéricas sem nenhuma fonte/autoridade citada."];
        }

        // 3. Generic placeholders — "Lorem ipsum", "[YOUR HEADLINE]", "X" remnants
        if (preg_match('/\b(lorem ipsum|\[your |xxx|tbd|todo:|placeholder|sample text|your headline here)/iu', $text)) {
            $flags[] = ['key' => 'placeholder_residue', 'name' => 'Placeholder residual', 'detail' => 'Texto contém placeholders/lorem-ipsum não substituídos.'];
        }

        // 4. AI-tells — phrases that scream LLM output
        $aiTells = ['as an ai', 'i cannot', "i'm an ai", 'language model', 'no entanto, é importante notar', "it's worth noting that",
            'in conclusion', 'em conclusão', "let's delve into", 'vamos mergulhar', 'tapestry of', 'navigate the complexities'];
        $aiHits = [];
        foreach ($aiTells as $t) {
            if (str_contains($text, $t)) {
                $aiHits[] = $t;
            }
        }
        if ($aiHits !== []) {
            $flags[] = ['key' => 'ai_tells', 'name' => 'Sinais de IA', 'detail' => 'Frases que delatam LLM: '.implode(', ', array_slice($aiHits, 0, 3))];
        }

        // 5. Excessive repetition — same word ≥6× in a short copy is keyword stuffing
        $wordCount = array_count_values(array_filter($words, fn ($w) => mb_strlen($w) >= 5));
        $overused = array_filter($wordCount, fn ($c) => $c >= 6);
        if ($overused !== [] && $wc < 600) {
            $top = array_keys($overused);
            $flags[] = ['key' => 'keyword_stuffing', 'name' => 'Stuffing de palavra', 'detail' => 'Palavra repetida ≥6×: '.implode(', ', array_slice($top, 0, 3))];
        }

        // 6. Vague intensifiers without concrete object — "very effective", "really good", "muito bom"
        $vagueCount = preg_match_all('/\b(very|really|extremely|incredibly|muito|extremamente|incrivelmente)\s+(\w+)\b/iu', $text);
        if ($vagueCount >= 5) {
            $flags[] = ['key' => 'vague_intensifiers', 'name' => 'Intensificadores vagos', 'detail' => "{$vagueCount} usos de 'very/really/muito' — sintoma de copy sem cena concreta."];
        }

        // 7. CTA without object — "click here", "buy now" sem amarrar ao benefício
        if (preg_match('/\b(click here|clique aqui|learn more|saiba mais|buy now|compre agora)\b/iu', $text)
            && ! preg_match('/\b(watch the presentation|assista|see the protocol|veja o protocolo|see how|see why|veja como|veja por que)\b/iu', $text)) {
            $flags[] = ['key' => 'naked_cta', 'name' => 'CTA sem promessa', 'detail' => '"Click here / buy now" sem o benefício amarrado.'];
        }

        // 8. Adjective overload — adjective count > 1 per 8 words is anti-elite
        $adjLike = preg_match_all('/\b\w+(?:ful|ous|able|ible|tive|tical|tical|ável|ível|oso|osa|ante)\b/u', $text);
        if ($wc > 50 && ($adjLike / max(1, $wc)) > 0.125) {
            $flags[] = ['key' => 'adjective_overload', 'name' => 'Sobrecarga de adjetivos', 'detail' => "{$adjLike} adjetivos em {$wc} palavras — copy de elite mostra com substantivo+verbo, não adjetivo."];
        }

        // Hollowness score: each flag adds weight; cap at 100
        $weights = [
            'buzzword_soup' => 20, 'claims_without_proof' => 25, 'placeholder_residue' => 40,
            'ai_tells' => 30, 'keyword_stuffing' => 18, 'vague_intensifiers' => 14,
            'naked_cta' => 10, 'adjective_overload' => 12,
        ];
        $hollowness = 0;
        foreach ($flags as $f) {
            $hollowness += $weights[$f['key']] ?? 10;
        }
        $hollowness = min(100, $hollowness);

        return ['hollowness' => $hollowness, 'grade' => $this->grade($hollowness), 'flags' => $flags];
    }

    private function grade(int $hollowness): string
    {
        return match (true) {
            $hollowness === 0 => 'substantive',
            $hollowness < 25 => 'mostly_solid',
            $hollowness < 50 => 'thin',
            $hollowness < 75 => 'hollow',
            default => 'fraudulent',
        };
    }
}
