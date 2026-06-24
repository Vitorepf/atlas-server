<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * MarketSophisticationRouter — Schwartz's market-sophistication ladder (1-5), crystallized.
 *
 * Awareness is about the READER; sophistication is about the MARKET — how many times this audience has
 * heard the pitch. The same claim that wins a fresh market (level 1) is dead in a burned-out one (level
 * 5). The ladder dictates the move:
 *   1 new market      → state the claim plainly (be first).
 *   2 claims escalate → enlarge the claim (biggest promise wins).
 *   3 mechanism       → introduce HOW it works (name the mechanism).
 *   4 new mechanism   → a UNIQUE/proprietary mechanism (differentiate the how) — feeds MechanismNameForge.
 *   5 exhausted       → stop arguing; IDENTIFY (become the reader), experience, new category, contrarian.
 *
 * Tells the engine WHEN to forge a named mechanism (3-4) vs lead with identification/story (5). Pairs
 * with AwarenessAggressionRouter. Deterministic, niche-agnostic, provider-free.
 */
class MarketSophisticationRouter
{
    private const LADDER = [
        1 => ['strategy' => 'direct_claim', 'forge_mechanism' => false,
            'moves' => ['lead_promise', 'hook_callout_specific'],
            'why' => 'Mercado novo: ninguém fez essa promessa ainda. Diga o benefício direto e seja o primeiro.'],
        2 => ['strategy' => 'enlarge_claim', 'forge_mechanism' => false,
            'moves' => ['lead_proclamation', 'hook_shocking_stat', 'specificity'],
            'why' => 'A promessa já foi feita: agora vence quem promete MAIOR e mais específico.'],
        3 => ['strategy' => 'introduce_mechanism', 'forge_mechanism' => true,
            'moves' => ['mechanism_tease', 'hidden_cause', 'authority_borrowing'],
            'why' => 'Promessas grandes saturaram: o COMO (mecanismo) volta a convencer. Nomeie o mecanismo.'],
        4 => ['strategy' => 'new_unique_mechanism', 'forge_mechanism' => true,
            'moves' => ['mechanism_tease', 'contrarian_truth', 'forbidden_discovery'],
            'why' => 'O mecanismo comum saturou: precisa de um mecanismo NOVO/proprietário e diferenciado.'],
        5 => ['strategy' => 'identify_and_experience', 'forge_mechanism' => false,
            'moves' => ['hook_story_open', 'identity_threat', 'common_enemy', 'new_category'],
            'why' => 'Mercado exausto, cético de tudo: pare de argumentar o mecanismo — IDENTIFIQUE-SE (vire o leitor), história, nova categoria, inimigo comum.'],
    ];

    public function normalize(int|string $level): int
    {
        if (is_int($level)) {
            return max(1, min(5, $level));
        }
        $l = mb_strtolower(trim($level));
        if (preg_match('/[1-5]/', $l, $m)) {
            return (int) $m[0];
        }

        return match (true) {
            str_contains($l, 'exhaust') || str_contains($l, 'satur') || str_contains($l, 'burned') => 5,
            str_contains($l, 'new market') || str_contains($l, 'novo') || str_contains($l, 'virgin') => 1,
            str_contains($l, 'mechanism') || str_contains($l, 'mecanismo') => 3,
            default => 3, // sane mid-default for cold affiliate markets (usually mechanism-stage)
        };
    }

    /**
     * @return array{level:int,strategy:string,forge_mechanism:bool,moves:array<int,string>,why:string}
     */
    public function strategy(int|string $level): array
    {
        $n = $this->normalize($level);

        return ['level' => $n] + self::LADDER[$n];
    }

    /** At level 3-4 the named mechanism is the move; elsewhere it is not the lead. */
    public function shouldForgeMechanism(int|string $level): bool
    {
        return self::LADDER[$this->normalize($level)]['forge_mechanism'];
    }
}
