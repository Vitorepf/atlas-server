<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * MechanismTeaseForge — the deterministic safety net for the bridge's #1 conversion line: the mechanism
 * tease (the curiosity gap that earns the press-play). Headline, lead, proof and big-idea all have forges
 * that override a weak LLM line; the tease was the ONLY conversion-critical slot with no forge, so it
 * depended entirely on the weak provider. This forges a STRUCTURALLY-SOUND tease — it opens a loop and
 * pulls hard to the video, names NO product/ingredient (spoiler-safe) and uses NO reveal marker (it
 * promises the answer "in the presentation above", it never gives it). Provider-free, deterministic;
 * the composer overrides ONLY when the model's tease is missing/too thin, so a rich model tease survives.
 */
class MechanismTeaseForge
{
    /** Reveal markers a TEASE must never contain (it promises the answer, it does not give it). */
    private const REVEAL = [
        'here\'s how', 'here is how', 'the secret is', 'the answer is', 'it\'s called', 'it is called',
        'the mechanism is', 'works by', 'o segredo é', 'a resposta é', 'se chama', 'chama-se',
        'o mecanismo é', 'veja como', 'funciona porque',
    ];

    public function forge(AiMarketingVslAsset $asset, array $opts = []): string
    {
        $pt = ($opts['lang'] ?? $this->lang($asset)) === 'pt';

        // Spoiler-safe by construction: the tease is built around the PROBLEM/trigger + a pull to the
        // video, never the coined product/mechanism NAME (that is the VSL's reveal) and never the recipe.
        return $pt
            ? 'O que muda o jogo não é mais esforço nem mais uma dieta — é um detalhe que quase todo mundo '
                .'ignora e que muda a forma como o corpo responde. A apresentação gratuita acima mostra '
                .'exatamente o que é e o passo a passo simples pra aplicar em casa, em linguagem direta. '
                .'Vale assistir antes que saia do ar — quando você vê, não dá mais pra desver.'
            : 'What changes the game isn\'t more effort or another diet — it\'s the one detail almost '
                .'everyone overlooks, the thing that quietly decides how your body responds. The free '
                .'presentation above shows exactly what it is and the simple at-home steps to use it, in '
                .'plain language. Worth watching before it is taken down — once you see it, you can\'t unsee it.';
    }

    /** Does this tease contain a literal reveal? (used by the composer to decide whether to override). */
    public function reveals(string $tease): bool
    {
        $t = mb_strtolower($tease);
        foreach (self::REVEAL as $m) {
            if (str_contains($t, $m)) {
                return true;
            }
        }

        return false;
    }

    private function lang(AiMarketingVslAsset $asset): string
    {
        $geo = mb_strtolower((string) $asset->target_geo.' '.$asset->language);

        return (str_contains($geo, 'pt') || str_contains($geo, 'br') || str_contains($geo, 'portug')) ? 'pt' : 'en';
    }
}
