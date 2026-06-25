<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * LeadForge — the bridge's opening (lead), forged in an elite direct-response voice, not the weak LLM.
 * The lead is what decides if the reader keeps reading: it calls out the avatar by identity, agitates
 * the real pain with a concrete scene, removes the blame onto the common enemy, then plants the named
 * mechanism as the hidden reason and opens a loop only the VSL closes. Parameterized by the VSL's
 * ammunition (avatar pains, failed solutions, enemy, mechanism, number). Deterministic.
 */
class LeadForge
{
    public function forge(AiMarketingVslAsset $asset, array $opts = []): string
    {
        $pt = ($opts['lang'] ?? $this->lang($asset)) === 'pt';
        $who = $this->avatar($asset, $pt);
        $failed = $this->failedList($asset, $pt);
        $mech = $this->mechanism($asset);
        // The p4 slot prepends an article ("o"/"the") before the quoted mechanism; strip a leading article
        // from the name so "O Reset Hormonal" doesn't become 'o "O Reset Hormonal"' (double article).
        $mechBare = (string) preg_replace('/^(?:o|a|os|as|the)\s+/iu', '', $mech);
        $enemy = ! empty(($asset->persuasion_devices ?? [])['conspiracy']);

        if ($pt) {
            $p1 = "Se você é {$who} e sente que o próprio corpo virou seu inimigo, você não está imaginando coisas.";
            $p2 = "Você já cortou doce, tentou {$failed}, se arrastou pra mais uma caminhada — e mesmo assim viu a balança travada, a roupa apertando e cada espelho virar uma má notícia. Depois de tantas tentativas, começa a parecer pessoal.";
            $p3 = $enemy
                ? "Mas e se o verdadeiro motivo nunca foi falta de disciplina? E se a indústria que lucra com injeções de milhares por mês tem todo o interesse em você nunca descobrir o que realmente trava a sua queima de gordura?"
                : 'Mas e se o verdadeiro motivo nunca foi falta de disciplina — e sim três hormônios que pararam de trabalhar juntos?';
            $p4 = "Na apresentação acima, mulheres descrevem o “{$mech}” como a primeira coisa que finalmente fez o corpo delas voltar a cooperar — sem agulha, sem passar fome. O porquê exato está no vídeo.";
        } else {
            $p1 = "If you're {$who} and it feels like your own body has turned against you, you are not imagining it.";
            $p2 = "You've cut out dessert, tried {$failed}, dragged yourself through one more walk — and still watched the scale sit there, your clothes get tighter, and every mirror start to feel like bad news. After enough tries, it starts to feel personal.";
            $p3 = $enemy
                ? 'But what if the real reason was never willpower? And what if the people making billions on $1,000-a-month injections have every reason to keep you from finding out what actually stalls your fat burning?'
                : 'But what if the real reason was never willpower — but three fat-burning hormones that quietly stopped working together?';
            $p4 = "In the presentation above, women describe the “{$mech}” as the first thing that finally made their bodies cooperate again — no needle, no starving. The exact reason why is in the video.";
        }

        return $p1."\n\n".$p2."\n\n".$p3."\n\n".$p4;
    }

    private function avatar(AiMarketingVslAsset $asset, bool $pt): string
    {
        $blob = mb_strtolower(json_encode($asset->avatar, JSON_UNESCAPED_UNICODE).' '.$asset->niche);
        $woman = (bool) preg_match('/\b(women|woman|mulher|female)\b/u', $blob);
        $man = (bool) preg_match('/\b(men|man|homem|male)\b/u', $blob);
        $age = preg_match('/\b([456]0)\b/u', $blob, $m) ? $m[1] : '40';
        if ($pt) {
            $base = $woman ? 'uma mulher' : ($man ? 'um homem' : 'alguém');

            return "{$base} acima dos {$age}";
        }
        $base = $woman ? 'a woman' : ($man ? 'a man' : 'someone');

        return "{$base} over {$age}";
    }

    private function failedList(AiMarketingVslAsset $asset, bool $pt): string
    {
        $blob = mb_strtolower((string) $asset->transcript.' '.json_encode($asset->avatar, JSON_UNESCAPED_UNICODE));
        $drugs = [];
        foreach (['Ozempic', 'Mounjaro', 'Wegovy'] as $d) {
            if (str_contains($blob, mb_strtolower($d))) {
                $drugs[] = $d;
            }
        }
        if ($drugs !== []) {
            return ($pt ? 'até ' : 'even ').implode($pt ? ' e ' : ' and ', array_slice($drugs, 0, 2));
        }

        return $pt ? 'mais uma dieta e mais um suplemento' : 'one more diet and one more supplement';
    }

    private function mechanism(AiMarketingVslAsset $asset): string
    {
        $m = trim((string) preg_replace('/\s*\(.*$/u', '', (string) $asset->mechanism_name));

        return $m !== '' ? mb_strimwidth($m, 0, 40, '') : 'protocol';
    }

    private function lang(AiMarketingVslAsset $asset): string
    {
        $geo = mb_strtolower((string) $asset->target_geo.' '.$asset->language);

        return (str_contains($geo, 'pt') || str_contains($geo, 'br') || str_contains($geo, 'portug')) ? 'pt' : 'en';
    }
}
