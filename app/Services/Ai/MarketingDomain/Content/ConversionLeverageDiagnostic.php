<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * ConversionLeverageDiagnostic — the 1→25 roadmap for a SINGLE funnel.
 *
 * The operator's goal: take a funnel that converts 1/100 and lift it to 25/100. That leap never comes from
 * polishing everything a little; it comes from fixing the ONE highest-leverage thing that is currently
 * weak. This composes the OS's honest substance instruments (named mechanism, concrete proof, the Value
 * Equation, watch-through) into a per-funnel diagnosis: each lever's substance STATUS (fact, from the
 * honest auditors — not a vocabulary proxy), ranked by the established direct-response leverage order, and
 * the single BOTTLENECK costing the most conversion right now, with the concrete fix. It does not invent a
 * scalar "score"; it surfaces the biggest unmet lever so the next build targets the 25x, not a 1.01x polish.
 * Provider-free, niche-agnostic, no moral gate.
 */
class ConversionLeverageDiagnostic
{
    public function __construct(
        private readonly ProofSubstanceAuditor $proof = new ProofSubstanceAuditor,
        private readonly ValueEquationAuditor $value = new ValueEquationAuditor,
        private readonly WatchThroughLeakDetector $watch = new WatchThroughLeakDetector,
        private readonly MechanismNameForge $forge = new MechanismNameForge,
        private readonly FrictionAbilityAuditor $friction = new FrictionAbilityAuditor,
        private readonly ProofAdjacencyAuditor $believability = new ProofAdjacencyAuditor,
    ) {}

    /**
     * @return array{levers:array<int,array<string,mixed>>,bottleneck:?array<string,mixed>,roadmap:array<int,string>,summary:string}
     */
    public function diagnose(AiMarketingVslAsset $asset, string $copy): array
    {
        $levers = [
            $this->namedMechanism($asset, $copy),
            $this->proofLever($copy),
            $this->offerLever($copy),
            $this->watchThroughLever($copy),
            $this->frictionLever($copy),
            $this->believabilityLever($copy),
        ];

        // Bottleneck = the highest-leverage lever that is WEAK. Ties broken by the DR tier.
        $weak = array_values(array_filter($levers, static fn ($l) => $l['status'] === 'weak'));
        usort($weak, static fn ($a, $b) => $b['tier'] <=> $a['tier']);
        $bottleneck = $weak[0] ?? null;

        $summary = $bottleneck === null
            ? 'Os 4 levers de alavancagem-alta estão fortes — o próximo ganho está em tráfego/message-match/awareness (fora da página) ou em testar variações de big-idea.'
            : 'GARGALO #1 (lever '.$bottleneck['tier'].', eixo '.$bottleneck['eixo'].'): '.$bottleneck['finding'].' → '.$bottleneck['fix'];

        return [
            'levers' => $levers,
            'bottleneck' => $bottleneck,
            'roadmap' => array_map(static fn ($l) => $l['key'], $weak),
            'summary' => $summary,
        ];
    }

    /** Eixo 2 — a NAMED, proprietary mechanism is the #1 reopener of a saturated market. */
    private function namedMechanism(AiMarketingVslAsset $asset, string $copy): array
    {
        $named = trim((string) $asset->mechanism_name) !== '' && str_contains(mb_strtolower($copy), mb_strtolower(trim((string) $asset->mechanism_name)));
        $patterned = (bool) preg_match('/\bthe\s+(?:\d-)?[\w-]+\s+(?:method|protocol|formula|system|switch|reset|ritual|rule|sequence|loop|code|m[ée]todo|protocolo|f[óo]rmula|sistema|ciclo|gatilho)\b/iu', $copy);
        $strong = $named || $patterned;
        $suggest = $this->forge->forge($asset)['best'] ?? 'um mecanismo nomeado';

        return [
            'key' => 'named_mechanism', 'eixo' => 2, 'tier' => 5,
            'status' => $strong ? 'strong' : 'weak',
            'finding' => $strong ? 'a página lidera com um mecanismo NOMEADO' : 'a página não nomeia o mecanismo — vende como commodity',
            'fix' => $strong ? '' : 'forjar e liderar com um mecanismo proprietário (ex.: "'.$suggest.'") — é o maior reabridor de mercado saturado',
        ];
    }

    /** Eixo 7 — proof is the #1 conversion lever; vague proof converts near zero. */
    private function proofLever(string $copy): array
    {
        $p = $this->proof->audit($copy);

        return [
            'key' => 'proof', 'eixo' => 7, 'tier' => 5,
            'status' => $p['has_concrete'] ? 'strong' : 'weak',
            'finding' => $p['has_concrete'] ? 'prova concreta presente ('.implode(', ', $p['concrete']).')' : 'sem prova concreta — '.$p['note'],
            'fix' => $p['has_concrete'] ? '' : 'injetar prova concreta: transformação real/before-after, autoridade credenciada, número AMARRADO a resultado',
        ];
    }

    /** Eixo 5 — a grand-slam offer (Value Equation). A high-leverage gap (proof/effort) costs the most. */
    private function offerLever(string $copy): array
    {
        $v = $this->value->audit($copy);
        $highGap = array_values(array_filter($v['gaps'], static fn ($g) => $g['high_leverage']));
        $strong = $highGap === [];

        return [
            'key' => 'offer', 'eixo' => 5, 'tier' => 4,
            'status' => $strong ? 'strong' : 'weak',
            'finding' => $strong ? 'oferta cobre as alavancas de alta-leverage' : 'oferta deixa alavanca crítica aberta: '.implode(', ', array_map(static fn ($g) => $g['key'], $highGap)),
            'fix' => $strong ? '' : 'fechar a(s) alavanca(s): '.implode(', ', array_map(static fn ($g) => $g['asks'], $highGap)),
        ];
    }

    /** Believability (the masters' unanimous lever) — a bold claim with no EXTERNAL proof beside it dies. */
    private function believabilityLever(string $copy): array
    {
        $a = $this->believability->audit($copy);
        $orphans = count($a['orphan_claims']);

        return [
            'key' => 'believability', 'eixo' => 7, 'tier' => 5,
            'status' => $a['has_orphan_claim'] ? 'weak' : 'strong',
            'finding' => $a['has_orphan_claim'] ? $orphans.' claim(s) sem prova externa ao lado — '.$a['note'] : 'todo claim forte tem prova adjacente',
            'fix' => $a['has_orphan_claim'] ? 'encaixar autoridade/número-de-gente/ratio/demo JUNTO de cada claim órfão' : '',
        ];
    }

    /** Fogg Ability — a page that maxes desire but never lowers action friction leaves money on the table. */
    private function frictionLever(string $copy): array
    {
        $f = $this->friction->audit($copy);

        return [
            'key' => 'friction', 'eixo' => 0, 'tier' => 3,
            'status' => $f['addresses_friction'] ? 'strong' : 'weak',
            'finding' => $f['addresses_friction'] ? 'fricção de ação reduzida ('.implode(', ', $f['ability_signals']).')' : 'nada reduz a fricção de agir — '.$f['note'],
            'fix' => $f['addresses_friction'] ? '' : 'adicionar sinais de baixo-esforço no CTA: acesso imediato / sem cartão / cancele quando quiser / leva 30s',
        ];
    }

    /** Eixo 3 — watch-through: a premature reveal or early hard-CTA collapses the VSL. */
    private function watchThroughLever(string $copy): array
    {
        $flaws = $this->watch->detect($copy)['flaws'];
        $strong = $flaws === [];

        return [
            'key' => 'watch_through', 'eixo' => 3, 'tier' => 3,
            'status' => $strong ? 'strong' : 'weak',
            'finding' => $strong ? 'sem vazamento de watch-through' : 'vazamento de watch-through: '.implode(', ', array_map(static fn ($f) => is_array($f) ? ($f['type'] ?? 'flaw') : (string) $f, $flaws)),
            'fix' => $strong ? '' : 'segurar o reveal do mecanismo até perto do CTA e deixar UM único CTA no fim',
        ];
    }
}
