<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * EmailFollowupForge — a re-engagement email sequence for the leads who clicked, watched part of the
 * VSL, but didn't buy. Each email reopens the unclosed loop with a different lever (curiosity →
 * authority + enemy → scarcity), in an elite human voice, parameterized by the VSL's ammunition
 * (mechanism, authority, number, enemy). Deterministic; no LLM. Subjects kept short for inbox display.
 */
class EmailFollowupForge
{
    /**
     * @param  array<string,mixed>  $opts  lang
     * @return array<int,array{subject:string,preview:string,body:string}>
     */
    public function forge(AiMarketingVslAsset $asset, array $opts = []): array
    {
        $pt = ($opts['lang'] ?? $this->lang($asset)) === 'pt';
        $mech = $this->mechanism($asset);
        $auth = $this->authority($asset);
        $num = $this->number($asset);

        return $pt ? $this->ptSequence($mech, $auth, $num) : $this->enSequence($mech, $auth, $num);
    }

    /**
     * @return array<int,array{subject:string,preview:string,body:string}>
     */
    private function enSequence(string $mech, string $auth, string $num): array
    {
        $numLine = $num !== '' ? "women losing {$num} without a single injection" : 'women losing weight without a single injection';
        $authLine = $auth !== '' ? "{$auth} reportedly uses it" : 'doctors are quietly recommending it';

        return [
            [
                'subject' => 'you left before the important part',
                'preview' => 'the part that explains why nothing worked before…',
                'body' => "You watched part of the presentation — then life pulled you away.\n\nI get it. But you left right before the part that actually matters: WHY your body stopped letting go of fat after 40, and what the \"{$mech}\" does about it in the morning, before coffee.\n\nThat one piece is what made the difference for {$numLine}.\n\n→ Pick it back up here (it only takes a few minutes):\n[WATCH THE REST]\n\nP.S. This isn't another diet or another shot. It's the opposite of both.",
            ],
            [
                'subject' => "why {$auth} skips Ozempic",
                'preview' => 'no needles, no \$1,000-a-month — and better results',
                'body' => "Quick question: why would someone who could afford any weight-loss shot on earth choose drops you take at home instead?\n\nBecause {$authLine} — and the people making billions on monthly injections really don't want that question asked out loud.\n\nThe \"{$mech}\" works with three fat-burning hormones at once, no needle required. The full explanation is still up — for now.\n\n→ [SEE WHY IT WORKS]\n\nThe injection industry has every reason to get this taken down.",
            ],
            [
                'subject' => 'this comes down tonight',
                'preview' => 'last chance to watch before it is pulled',
                'body' => "I'll keep this short.\n\nThe presentation that shows the \"{$mech}\" — the one {$numLine} — is being taken down.\n\nIf you've been on the fence, this is the moment. Watch it now, decide for yourself, and if it's not for you, you've lost nothing but a few minutes.\n\n→ [WATCH BEFORE IT IS GONE]\n\nAfter tonight I can't promise this link still works.",
            ],
        ];
    }

    /**
     * @return array<int,array{subject:string,preview:string,body:string}>
     */
    private function ptSequence(string $mech, string $auth, string $num): array
    {
        $numLine = $num !== '' ? "mulheres perdendo {$num} sem uma única injeção" : 'mulheres emagrecendo sem uma única injeção';
        $authLine = $auth !== '' ? "{$auth} usa isso" : 'médicos estão recomendando baixinho';

        return [
            [
                'subject' => 'você saiu antes da parte importante',
                'preview' => 'a parte que explica por que nada funcionou antes…',
                'body' => "Você assistiu parte da apresentação — e aí a vida chamou.\n\nEu entendo. Mas você saiu bem antes da parte que importa: POR QUE o seu corpo parou de soltar gordura depois dos 40, e o que o \"{$mech}\" faz sobre isso de manhã, antes do café.\n\nEsse detalhe foi o que mudou tudo para {$numLine}.\n\n→ Continue de onde parou (leva poucos minutos):\n[ASSISTIR O RESTO]\n\nP.S. Não é mais uma dieta nem mais uma injeção. É o oposto das duas.",
            ],
            [
                'subject' => "por que {$auth} evita o Ozempic",
                'preview' => 'sem agulha, sem milhares por mês — e resultado melhor',
                'body' => "Pergunta rápida: por que alguém que poderia pagar qualquer injeção do mundo escolheria gotas que se toma em casa?\n\nPorque {$authLine} — e quem lucra bilhões com injeção mensal realmente não quer essa pergunta no ar.\n\nO \"{$mech}\" age em três hormônios da queima de gordura ao mesmo tempo, sem agulha. A explicação completa ainda está no ar — por enquanto.\n\n→ [VER POR QUE FUNCIONA]\n\nA indústria da injeção tem todo motivo pra derrubar isso.",
            ],
            [
                'subject' => 'isso sai do ar hoje à noite',
                'preview' => 'última chance de assistir antes de remover',
                'body' => "Vou ser breve.\n\nA apresentação que mostra o \"{$mech}\" — a mesma de {$numLine} — está sendo removida.\n\nSe você estava em dúvida, é agora. Assista, decida por você mesma, e se não for pra você, perdeu só alguns minutos.\n\n→ [ASSISTIR ANTES QUE SAIA]\n\nDepois de hoje não posso garantir que esse link ainda funciona.",
            ],
        ];
    }

    private function mechanism(AiMarketingVslAsset $asset): string
    {
        $m = trim((string) preg_replace('/\s*\(.*$/u', '', (string) $asset->mechanism_name));

        return $m !== '' ? mb_strimwidth($m, 0, 40, '') : 'protocol';
    }

    private function authority(AiMarketingVslAsset $asset): string
    {
        $devices = is_array($asset->persuasion_devices) ? $asset->persuasion_devices : [];
        foreach ((array) ($devices['authority'] ?? []) as $a) {
            $a = trim((string) (is_array($a) ? ($a['name'] ?? reset($a)) : $a));
            $a = trim((string) preg_replace('/\s*[\(\[,:\-—].*$/u', '', $a));
            if ($a !== '' && str_word_count($a) <= 3 && preg_match('/^\p{Lu}/u', $a)
                && ! preg_match('/\b(fda|pharma|farma|gov|study|estudo|news|tv)\b/iu', $a)) {
                return mb_strimwidth($a, 0, 22, '');
            }
        }

        return '';
    }

    private function number(AiMarketingVslAsset $asset): string
    {
        $blob = json_encode($asset->metrics, JSON_UNESCAPED_UNICODE);
        if (preg_match_all('/(\d{2,3})\s*(lbs?|pounds|libras|kg|quilos)/iu', (string) $blob, $m)) {
            foreach ($m[1] as $i => $n) {
                if ((int) $n >= 30 && (int) $n <= 90) {
                    $metric = stripos($m[2][$i], 'kg') !== false || stripos($m[2][$i], 'quil') !== false;

                    return $n.($metric ? ' kg' : ' lbs');
                }
            }
        }

        return '';
    }

    private function lang(AiMarketingVslAsset $asset): string
    {
        $geo = mb_strtolower((string) $asset->target_geo.' '.$asset->language);

        return (str_contains($geo, 'pt') || str_contains($geo, 'br') || str_contains($geo, 'portug')) ? 'pt' : 'en';
    }
}
