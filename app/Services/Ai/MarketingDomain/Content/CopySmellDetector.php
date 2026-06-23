<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * CopySmellDetector — caça smells ESPECÍFICOS de VSL/affiliate copy que arruínam página inteira,
 * complementando o piso genérico do AntiGoodhartGuard. Cada smell tem alto custo de conversão e é
 * detectável por marker. Provider-free, additive ao audit. Anti-Goodhart: contagem de smells não é
 * métrica — cada smell vem com lever de correção concreta.
 */
class CopySmellDetector
{
    /**
     * @return array{n:int,smells:array<int,array{key:string,severity:string,evidence:string,fix:string}>}
     */
    public function inspect(string $copy): array
    {
        $t = mb_strtolower($copy);
        $smells = [];

        if (preg_match('/\b(learn more|saiba mais|click here|clique aqui|find out more)\b/iu', $t, $m)) {
            $smells[] = ['key' => 'weak_cta', 'severity' => 'critical', 'evidence' => $m[0],
                'fix' => 'CTA precisa prometer o PRÓXIMO passo concreto: "Watch the free presentation" / "See the mechanism in the video", não "learn more".'];
        }

        $hasGuarantee = preg_match('/\b(money[- ]back|reembolso|guarantee|garantia|risk[- ]free|sem risco)\b/iu', $t);
        $hasNumberedGuarantee = preg_match('/\b(30|60|90|180|365)[- ]day/iu', $t);
        if ($hasGuarantee && ! $hasNumberedGuarantee) {
            $smells[] = ['key' => 'numberless_guarantee', 'severity' => 'high', 'evidence' => 'guarantee sem prazo',
                'fix' => 'Garantia sem número soa falsa: "60-day money-back guarantee" >> "money-back guarantee".'];
        }
        if (! $hasGuarantee) {
            $smells[] = ['key' => 'missing_guarantee', 'severity' => 'critical', 'evidence' => 'sem money-back/garantia',
                'fix' => 'Adicionar "60-day money-back guarantee" elimina objeção de risco — sem isso, marido cético vetar.'];
        }

        if (preg_match('/^(are you tired of|tired of|cansado de|você está cansad[oa] de)\b/iu', $t)) {
            $smells[] = ['key' => 'generic_hook', 'severity' => 'high', 'evidence' => 'abre com "are you tired of…"',
                'fix' => 'Abrir com callout específico ou stat chocante; "are you tired of" foi queimado em 2018.'];
        }

        if (preg_match_all('/\b(many people|muitas pessoas|thousands of|milhares de|people just like you|gente como você)\b/iu', $t, $m)) {
            if (count($m[0]) >= 1 && ! preg_match('/\b\d{2,}\s+(women|men|people|mulheres|homens|pessoas)/iu', $t)) {
                $smells[] = ['key' => 'vague_proof', 'severity' => 'high', 'evidence' => $m[0][0],
                    'fix' => 'Prova vaga não converte: trocar "many people" por número específico — "12,847 women over 40 in 90 days".'];
            }
        }

        $hasResult = preg_match('/\b(lose|lost|perdi|perd[ie]u|drop|dropp|burn|burned)\s+\d+/iu', $t);
        $hasTimeFrame = preg_match('/\b(in|em)\s+\d+\s+(days?|weeks?|months?|dias?|semanas?|meses?)\b/iu', $t);
        if ($hasResult && ! $hasTimeFrame) {
            $smells[] = ['key' => 'unbounded_promise', 'severity' => 'medium', 'evidence' => 'promete resultado sem prazo',
                'fix' => 'Resultado sem prazo soa exagerado; "in 90 days" / "em 60 dias" ancora credibilidade.'];
        }

        if (preg_match('/\bbuy now\b/iu', $t) && ! preg_match('/\b(watch|see|veja|assista)\s+(the|first|antes|primeiro)/iu', $t)) {
            $smells[] = ['key' => 'premature_buy_cta', 'severity' => 'critical', 'evidence' => 'buy now sem watch-first',
                'fix' => 'Bridge page nunca deve mandar "buy now" — sempre "watch the free presentation first", o VSL vende.'];
        }

        if (preg_match('/\$\d{1,3}(?:[.,]\d{2,3})?\b/u', $t, $priceMatch)) {
            if (! preg_match('/\b(normally|usually|was|de|por apenas|originalmente|sale|today only)\b/iu', $t)) {
                $smells[] = ['key' => 'unanchored_price', 'severity' => 'medium', 'evidence' => $priceMatch[0],
                    'fix' => 'Preço solto sem ancora ($1,000 → today only $49) perde poder; ancora "normally $X, today only $Y".'];
            }
        }

        if (preg_match_all('/\bjust\b/iu', $t, $jm) && count($jm[0]) >= 3) {
            $smells[] = ['key' => 'just_overuse', 'severity' => 'low', 'evidence' => '"just" '.count($jm[0]).'×',
                'fix' => '"Just" repetido soa amador ("just take it" / "just imagine"); cortar 2/3.'];
        }

        return ['n' => count($smells), 'smells' => $smells];
    }
}
