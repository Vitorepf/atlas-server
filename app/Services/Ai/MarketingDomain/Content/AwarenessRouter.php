<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Services\Ai\MarketingDomain\Knowledge\AwarenessSophisticationLibrary;

/**
 * AwarenessRouter — turns the routing meta-layer into action. Given a traffic awareness level and a
 * market sophistication stage, it prescribes the construction (lead type, opening move, which big-idea
 * angles fit, proof emphasis, CTA style). It also DETECTS which level a piece of copy is written for
 * (via the library markers) and flags a mismatch with the target — because elite copy at the wrong
 * awareness level converts at zero. Deterministic.
 */
class AwarenessRouter
{
    private const AWARENESS = ['unaware', 'problem_aware', 'solution_aware', 'product_aware', 'most_aware'];

    private const SOPHISTICATION = ['stage1_first', 'stage2_bigger', 'stage3_mechanism', 'stage4_bigger_mechanism', 'stage5_identity'];

    public function __construct(private readonly AwarenessSophisticationLibrary $library = new AwarenessSophisticationLibrary) {}

    /**
     * Prescription for a (awareness, sophistication) pair.
     *
     * @return array{awareness:string,sophistication:string,lead_type:string,opening:string,angles:array<int,string>,proof_emphasis:string,cta_style:string}
     */
    public function route(string $awareness, ?string $sophistication = null): array
    {
        $aw = in_array($awareness, self::AWARENESS, true) ? $awareness : $this->normalizeAwareness($awareness);
        $so = in_array((string) $sophistication, self::SOPHISTICATION, true) ? (string) $sophistication : 'stage3_mechanism';

        $byAwareness = [
            'unaware' => ['lead' => 'story', 'open' => 'Abra com história/identificação; não nomeie o produto cedo.', 'angles' => ['transformation_story', 'hidden_cause'], 'proof' => 'low', 'cta' => 'curiosity'],
            'problem_aware' => ['lead' => 'problem-agitation', 'open' => 'Agite a dor com cena visceral; revele a causa oculta.', 'angles' => ['hidden_cause', 'common_enemy', 'ticking_threat'], 'proof' => 'medium', 'cta' => 'learn'],
            'solution_aware' => ['lead' => 'mechanism', 'open' => 'Lidere com o mecanismo único; diferencie das outras soluções.', 'angles' => ['new_opportunity', 'contrarian_truth', 'shortcut_secret', 'third_option'], 'proof' => 'high', 'cta' => 'watch'],
            'product_aware' => ['lead' => 'offer-proof', 'open' => 'Lidere com oferta, prova e diferenciação direta.', 'angles' => ['us_vs_them', 'forbidden_discovery'], 'proof' => 'high', 'cta' => 'claim'],
            'most_aware' => ['lead' => 'direct-cta', 'open' => 'Oferta direta + escassez; vá pro CTA.', 'angles' => ['ticking_threat'], 'proof' => 'low', 'cta' => 'order'],
        ];
        $p = $byAwareness[$aw];

        // Sophistication modulates how much the mechanism carries the message.
        $angles = $p['angles'];
        if ($so === 'stage4_bigger_mechanism' && ! in_array('new_opportunity', $angles, true)) {
            array_unshift($angles, 'new_opportunity');
        }
        if ($so === 'stage5_identity') {
            array_unshift($angles, 'us_vs_them');
        }

        return [
            'awareness' => $aw,
            'sophistication' => $so,
            'lead_type' => $p['lead'],
            'opening' => $p['open'],
            'angles' => array_values(array_unique($angles)),
            'proof_emphasis' => $p['proof'],
            'cta_style' => $p['cta'],
        ];
    }

    /**
     * Infer the dominant awareness level + sophistication stage a copy is written for.
     *
     * @return array{awareness:string,sophistication:string,awareness_hits:array<string,int>}
     */
    public function detect(string $copy): array
    {
        $text = mb_strtolower($copy);
        $awHits = [];
        $soHits = [];
        foreach ($this->library->all() as $p) {
            $n = 0;
            foreach ($p['markers'] as $m) {
                if ($m !== '' && str_contains($text, mb_strtolower($m))) {
                    $n++;
                }
            }
            if ($p['category'] === 'awareness') {
                $awHits[$p['key']] = $n;
            } else {
                $soHits[$p['key']] = $n;
            }
        }

        return [
            'awareness' => $this->dominant($awHits, 'problem_aware'),
            'sophistication' => $this->dominant($soHits, 'stage3_mechanism'),
            'awareness_hits' => $awHits,
        ];
    }

    /**
     * Does the copy match the target awareness? (the construction-vs-traffic alignment check)
     *
     * @return array{aligned:bool,target:string,detected:string,note:string}
     */
    public function match(string $copy, string $targetAwareness): array
    {
        $target = in_array($targetAwareness, self::AWARENESS, true) ? $targetAwareness : $this->normalizeAwareness($targetAwareness);
        $detected = $this->detect($copy)['awareness'];

        return [
            'aligned' => $detected === $target,
            'target' => $target,
            'detected' => $detected,
            'note' => $detected === $target
                ? 'Copy calibrada para o nível do tráfego.'
                : "Copy escrita para '{$detected}' mas o tráfego é '{$target}' — recalibrar o lead (ver route()).",
        ];
    }

    /**
     * @param  array<string,int>  $hits
     */
    private function dominant(array $hits, string $default): string
    {
        arsort($hits);
        $top = array_key_first($hits);

        return ($top !== null && $hits[$top] > 0) ? $top : $default;
    }

    private function normalizeAwareness(string $raw): string
    {
        $r = mb_strtolower($raw);

        return match (true) {
            str_contains($r, 'unaware') || str_contains($r, 'inconsc') => 'unaware',
            str_contains($r, 'most') || str_contains($r, 'total') => 'most_aware',
            str_contains($r, 'product') || str_contains($r, 'produto') => 'product_aware',
            str_contains($r, 'solution') || str_contains($r, 'soluç') => 'solution_aware',
            default => 'problem_aware',
        };
    }
}
