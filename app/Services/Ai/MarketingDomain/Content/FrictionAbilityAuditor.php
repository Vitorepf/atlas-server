<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * FrictionAbilityAuditor — the Fogg Ability axis (B = Motivation × Ability × Prompt), the one the OS was
 * missing entirely.
 *
 * The Conversion OS already saturates Motivation (desire/proof/offer) and Prompt (CTA clarity via
 * DecisionClarityAuditor), but conversion dies at the ACTION when perceived EFFORT/RISK to act is high and
 * nothing lowers it. This audits the ACTION friction (distinct from the offer's use-effort lever in
 * ValueEquationAuditor, which is about how hard the PRODUCT is): does the page reduce the cost of taking
 * the next step — instant access, no credit card, cancel anytime, takes 30 seconds, one click, done-for-you
 * — or does it pile on action friction (fill out a long form, create an account, schedule a call, wait for
 * approval)? On a page that already maxes motivation, lowering friction usually beats adding more desire.
 *
 * Structural truth: presence of ability signals and presence of friction-raisers are FACTS, not a score.
 * Provider-free, niche-agnostic, no moral brake.
 */
class FrictionAbilityAuditor
{
    /** Signals that LOWER the cost of acting (Fogg "make it tiny"). */
    private const ABILITY = [
        'instant access', 'instantly', 'no credit card', 'no card required', 'cancel anytime', 'cancel any time',
        'no commitment', 'no obligation', 'risk-free', 'free trial', 'one click', '1 click', 'one-click',
        'takes 30 seconds', 'takes seconds', 'in seconds', 'in under a minute', 'two minutes', '2 minutes',
        'done for you', 'done-for-you', 'no experience', 'no setup', 'no tech', 'we set it up', 'ready to go',
        'free shipping', 'ships free', 'get started in', 'sign up in', 'no contracts', 'skip the',
        'acesso imediato', 'sem cartão', 'cancele quando quiser', 'sem compromisso', 'sem risco', 'em segundos',
        'frete grátis', 'pronto pra usar', 'sem experiência', 'um clique',
    ];

    /** Language that RAISES the cost/risk of acting right at the ask. */
    private const FRICTION = [
        'fill out the form', 'fill out this form', 'complete the form', 'fill in the form', 'long form',
        'create an account', 'register an account', 'schedule a call', 'book a call', 'apply and wait',
        'wait for approval', 'fill out the application', 'complete the survey', 'verify your identity',
        'preencha o formulário', 'crie uma conta', 'agende uma ligação', 'aguarde aprovação', 'preencha o cadastro',
    ];

    /**
     * @return array{ability_signals:array<int,string>,friction_flags:array<int,string>,addresses_friction:bool,note:string}
     */
    public function audit(string $copy): array
    {
        $text = mb_strtolower($copy);
        $ability = array_values(array_filter(self::ABILITY, static fn ($s) => str_contains($text, $s)));
        $friction = array_values(array_filter(self::FRICTION, static fn ($s) => str_contains($text, $s)));

        $addresses = $ability !== [];
        $note = match (true) {
            $friction !== [] && ! $addresses => 'Fricção de AÇÃO alta e NADA a reduz ('.implode(', ', $friction).') — a venda morre no esforço de agir. Adicionar "acesso imediato / sem cartão / cancele quando quiser / leva 30s".',
            $friction !== [] => 'Reduz fricção ('.implode(', ', $ability).') MAS ainda exige esforço ('.implode(', ', $friction).') — aliviar/adiar o passo pesado.',
            ! $addresses => 'A página não REDUZ a fricção de agir (nenhum sinal de baixo-esforço). Numa página que já satura desejo, "make-it-tiny" costuma render mais que +motivação.',
            default => 'Fricção de ação endereçada: '.implode(', ', $ability).'.',
        };

        return [
            'ability_signals' => $ability,
            'friction_flags' => $friction,
            'addresses_friction' => $addresses,
            'note' => $note,
        ];
    }
}
