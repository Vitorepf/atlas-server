<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * WinningPatternScout — the inverse direction of learning. The OS today encodes MY craft as patterns.
 * The scout closes the opposite loop: take a real winning page from the wild (HTML/text the operator
 * pastes), inventory which OS patterns it ALREADY uses, surface the patterns it uses that NO library
 * yet encodes (the candidates for the next deepening cycle), and rank them by "differentiating
 * power" — how many top winners use this vs. the median page. Provider-free; foundation for the
 * learned-weights flywheel once real conversion data lands.
 */
class WinningPatternScout
{
    public function __construct(
        private readonly ConversionAuditor $auditor = new ConversionAuditor,
        private readonly AntiGoodhartGuard $guard = new AntiGoodhartGuard,
    ) {}

    /**
     * Inventory a winner page through the full OS lens, plus surface "unknown" patterns to harvest.
     *
     * @return array{audit:array<string,mixed>,hollowness:array<string,mixed>,fingerprint:array<int,string>,signal_density:array<string,float>,candidates:array<int,array<string,mixed>>}
     */
    public function scout(string $html): array
    {
        $copy = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
        $audit = $this->auditor->audit($copy, $html);
        $hollow = $this->guard->inspect($copy);

        $fingerprint = [];
        $density = [];
        $wc = max(1, str_word_count($copy));
        foreach ($audit['by_library'] as $libName => $r) {
            foreach ($r['present'] ?? [] as $k) {
                $fingerprint[] = $libName.':'.$k;
            }
            $density[$libName] = round(count($r['present'] ?? []) / $wc * 1000, 3); // hits per 1000 words
        }

        return [
            'audit' => $audit,
            'hollowness' => $hollow,
            'fingerprint' => $fingerprint,
            'signal_density' => $density,
            'candidates' => $this->harvestCandidates($copy, $html, $audit),
        ];
    }

    /**
     * Surface heuristic "candidate patterns" — recurring constructs that no library yet detects
     * but that look meaningful (e.g. unfamiliar regex shapes, repeated phrasings, named entities).
     * These are CANDIDATES for the next deepening cycle, not new patterns themselves — the operator
     * (or future Atlas) decides which to encode.
     *
     * @param  array<string,mixed>  $audit
     * @return array<int,array{snippet:string,why:string}>
     */
    private function harvestCandidates(string $copy, string $html, array $audit): array
    {
        $cands = [];

        if (preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,3}\s+(Method|Protocol|System|Formula|Code|Effect|Switch)(™|®)?\b/u', $copy, $m) && count($m[0]) >= 1) {
            $cands[] = ['snippet' => $m[0][0], 'why' => 'Mecanismo nomeado com sufixo "Method/Protocol/Effect" — candidato a refinar AngleBigIdea ou Offer.'];
        }
        if (preg_match_all('/"[^"]{20,140}"/u', $copy, $m) && count($m[0]) >= 3) {
            $cands[] = ['snippet' => $m[0][0], 'why' => 'Uso intensivo de aspas (citações/depoimentos) — densidade '.count($m[0]).' aspas, sinal de copy testimonial-heavy.'];
        }
        if (preg_match_all('/\b\d{1,3}(?:,\d{3})+(?:\.\d+)?\s+(?:women|men|customers|people|users|clientes|mulheres|pessoas)\b/iu', $copy, $m)) {
            $cands[] = ['snippet' => $m[0][0], 'why' => 'Número específico de usuários — candidato a registrar como pattern de "specific user count" em Persuasion/Cialdini.'];
        }
        if (preg_match_all('/\b(?:Step|Passo)\s*\d+:?\s*[A-Z]/u', $copy, $m) && count($m[0]) >= 3) {
            $cands[] = ['snippet' => $m[0][0], 'why' => 'Estrutura numerada de passos — possível padrão de pedagogia/onboarding pra Funnel.'];
        }
        if (preg_match_all('/<table[^>]*>/i', $html, $m) && count($m[0]) >= 1) {
            $cands[] = ['snippet' => '<table>...</table> × '.count($m[0]), 'why' => 'Tabela comparativa presente — confirma o padrão visual; verificar se já é alavancado.'];
        }
        if (preg_match_all('/<video|<iframe[^>]*(youtube|wistia|vimeo)/i', $html, $m) && count($m[0]) >= 1) {
            $cands[] = ['snippet' => $m[0][0] ?? '<video>', 'why' => 'Vídeo embedado real — distinto do placeholder; pode pesar mais que thumbnail SVG.'];
        }
        // Patterns the OS marked as "present" with very high density may signal an under-encoded
        // construct that this winner leans heavily on.
        foreach (($audit['by_library'] ?? []) as $libName => $r) {
            $present = (array) ($r['present'] ?? []);
            if (count($present) >= 5) {
                $cands[] = ['snippet' => $libName.': '.implode(', ', array_slice($present, 0, 4)),
                    'why' => "Esta página ativa {$libName} muito acima da média (".count($present)." padrões) — provavelmente é uma alavanca dominante do nicho dela."];
            }
        }

        return $cands;
    }
}
