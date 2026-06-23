<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * AggressionAmplifier — the closed loop. It audits a bridge across all 9 Conversion-OS libraries,
 * picks the top missing high-leverage patterns, generates the concrete copy snippet for each one (in
 * elite direct-response voice, grounded in the asset's ammunition), injects each snippet into the right
 * bridge slot (kicker, mechanism_tease, body_sections, ps, objection_flips, cta_blocks), then re-audits
 * to prove the lift. This is the verifier-driven amplifier — measure → inject what's missing → re-measure
 * until killer. Deterministic; the snippets are MINE, not the LLM's.
 */
class AggressionAmplifier
{
    public function __construct(
        private readonly ConversionAuditor $auditor = new ConversionAuditor,
        private readonly AntiGoodhartGuard $guard = new AntiGoodhartGuard,
    ) {}

    /**
     * @return array{bridge:array<string,mixed>,before:array<string,mixed>,after:array<string,mixed>,injected:array<int,string>,iterations:int,rejected:array<int,string>,hollowness:int}
     */
    public function amplify(array $bridge, AiMarketingVslAsset $asset, array $opts = []): array
    {
        $targetGrade = (string) ($opts['until'] ?? 'killer');
        $maxIters = (int) ($opts['max_iterations'] ?? 3);
        $niche = (string) ($opts['niche'] ?? '');     // when set + auditor has hybrid scorer wired → learned weights kick in
        $pageKind = (string) ($opts['page_kind'] ?? 'bridge');

        $before = $this->auditor->audit($this->copyOf($bridge), '', $niche, $pageKind);
        $current = $bridge;
        $injected = [];
        $rejected = [];

        for ($i = 1; $i <= $maxIters; $i++) {
            $audit = $i === 1 ? $before : $this->auditor->audit($this->copyOf($current), '', $niche, $pageKind);
            if ($this->meets($audit['grade'], $targetGrade)) {
                break;
            }
            $applied = false;
            foreach ($audit['top_missing'] as $missing) {
                $snippet = $this->snippet($missing['key'], $asset);
                if ($snippet === null || in_array($missing['key'], $injected, true)) {
                    continue;
                }
                $candidate = $this->inject($current, $snippet);
                // Anti-Goodhart gate: reject the injection if it raises hollowness materially —
                // we never trade real persuasion for marker stuffing.
                $hollowBefore = $this->guard->inspect($this->copyOf($current))['hollowness'];
                $hollowAfter = $this->guard->inspect($this->copyOf($candidate))['hollowness'];
                if ($hollowAfter - $hollowBefore > 15) {
                    $rejected[] = $missing['key'];

                    continue;
                }
                $current = $candidate;
                $injected[] = $missing['key'];
                $applied = true;
                if (count($injected) >= 5 * $i) {
                    break;
                }
            }
            if (! $applied) {
                break;
            }
        }

        // Last pass: persona-driven fixes. The auditor already simulated the niche's panel; if the
        // audience score is low, inject the targeted snippet for the worst-rejecting persona's "what
        // she needs next" — that goes deeper than marker absence (which the main loop already covered).
        $current = $this->personaFix($current, $asset, $niche);

        $after = $this->auditor->audit($this->copyOf($current), '', $niche, $pageKind);
        $hollowness = $this->guard->inspect($this->copyOf($current))['hollowness'];

        return ['bridge' => $current, 'before' => $before, 'after' => $after, 'injected' => $injected,
            'iterations' => $i, 'rejected' => $rejected, 'hollowness' => $hollowness];
    }

    /**
     * Use the PersonaSimulator output baked into the audit to apply 1 targeted fix per the worst
     * persona's "what she needs next". Cheap, deterministic, additive — only fires when audience<60.
     *
     * @param  array<string,mixed>  $bridge
     * @return array<string,mixed>
     */
    private function personaFix(array $bridge, AiMarketingVslAsset $asset, string $niche = ''): array
    {
        $audit = $this->auditor->audit($this->copyOf($bridge), '', $niche);
        if (($audit['audience_score'] ?? 100) >= 70) {
            return $bridge;
        }
        $personas = (array) ($audit['personas'] ?? []);
        if ($personas === []) {
            return $bridge;
        }

        // Multi-persona injection by slot: each rejecting persona's fix lands in the slot best suited
        // to her. Health personas (no 'slot' field) use the hand-tuned map below — byte-identical.
        // Niche personas (finance/relationship/generic) carry their own 'slot' + 'fix_heading'.
        // We only inject when the persona is actually rejecting (will_close >= 0.3) AND we leave
        // existing operator content alone (only append/fill empty slots, never overwrite).
        $slotMap = [
            'busy_mom_no_time' => 'kicker',           // top of page = first thing she sees
            'ex_ozempic_buyer' => 'body_sections',    // mid-body explanation/comparison
            'woman_40_diet_fatigue' => 'ps',          // bottom emotional close
            'skeptic_husband' => 'body_sections',     // body-section that hammers guarantee + proof
            'early_adopter' => 'body_sections',       // body-section with technical depth
        ];
        $applied = 0;
        foreach ($personas as $name => $p) {
            if (($p['will_close'] ?? 0.0) < 0.3) {
                continue;
            }
            $slot = (string) ($p['slot'] ?? $slotMap[$name] ?? 'ps');
            $fixText = (string) $p['what_she_needs_next'];
            if ($fixText === '') {
                continue;
            }
            if ($slot === 'kicker' && empty($bridge['kicker'])) {
                $bridge['kicker'] = mb_strimwidth($fixText, 0, 80, '');
                $applied++;
            } elseif ($slot === 'body_sections') {
                $sections = is_array($bridge['body_sections'] ?? null) ? $bridge['body_sections'] : [];
                $headingForPersona = (string) ($p['fix_heading'] ?? match ($name) {
                    'ex_ozempic_buyer' => 'Why this beats the injection',
                    'skeptic_husband' => 'Why this is zero-risk',
                    'early_adopter' => 'The mechanism, in detail',
                    default => 'For you specifically',
                });
                $headings = array_map(fn ($s) => is_array($s) ? (string) ($s['heading'] ?? '') : '', $sections);
                if (! in_array($headingForPersona, $headings, true)) {
                    $sections[] = ['heading' => $headingForPersona, 'body' => $fixText];
                    $bridge['body_sections'] = $sections;
                    $applied++;
                }
            } elseif ($slot === 'ps' && empty($bridge['ps'])) {
                $bridge['ps'] = 'P.S. '.$fixText;
                $applied++;
            }
        }

        return $bridge;
    }

    /**
     * Concrete copy snippets per pattern key — written in elite voice, parameterized by the asset.
     * Returns ['slot' => ..., 'value' => ..., 'mode' => 'set'|'append'|'prepend_section'] or null when
     * the pattern has no canned injection (still surfaced for human attention).
     *
     * @return array{slot:string,value:mixed,mode:string}|null
     */
    private function snippet(string $key, AiMarketingVslAsset $asset): ?array
    {
        $mech = $this->mechanism($asset);
        $auth = $this->authority($asset);
        $num = $this->number($asset);
        $enemy = $this->enemy($asset);

        return match ($key) {
            // ── Angle ───────────────────────────────────────────────────────────────────────────
            'hidden_cause' => ['slot' => 'body_sections', 'mode' => 'prepend_section', 'value' => [
                'heading' => 'The real reason nothing has worked',
                'body' => "It was never your willpower. The hidden cause is a $mech your body stopped firing — and no diet on earth turns it back on.",
            ]],
            'common_enemy' => ['slot' => 'body_sections', 'mode' => 'prepend_section', 'value' => [
                'heading' => 'Why this story keeps getting taken down',
                'body' => $enemy.' has every reason to keep you from finding this out — there is roughly $200 billion a year on the line.',
            ]],
            'forbidden_discovery' => ['slot' => 'kicker', 'mode' => 'set', 'value' => 'LEAKED · BEFORE IT IS PULLED'],
            'contrarian_truth' => ['slot' => 'body_sections', 'mode' => 'prepend_section', 'value' => [
                'heading' => 'Everything you were told is wrong',
                'body' => 'It was never about eating less or moving more. The truth is the opposite of what you have been sold for 20 years.',
            ]],

            // ── Persuasion / urgency ───────────────────────────────────────────────────────────
            'open_loop' => ['slot' => 'cta_blocks', 'mode' => 'append', 'value' => [
                'label' => 'See the reason in the video', 'sub' => 'The exact mechanism is explained inside.',
            ]],
            'forbidden_knowledge' => ['slot' => 'kicker', 'mode' => 'set', 'value' => 'WATCH BEFORE IT IS TAKEN DOWN'],
            'scarcity' => ['slot' => 'cta_blocks', 'mode' => 'append', 'value' => [
                'label' => 'Watch the free presentation', 'sub' => 'It may be removed without notice — it has been pulled twice already.',
            ]],
            'ticking_threat' => ['slot' => 'cta_blocks', 'mode' => 'append', 'value' => [
                'label' => 'Watch before it is too late', 'sub' => 'Every day you wait, the harder it gets.',
            ]],

            // ── Cognitive bias ──────────────────────────────────────────────────────────────────
            'anchoring' => ['slot' => 'body_sections', 'mode' => 'prepend_section', 'value' => [
                'heading' => 'What this would normally cost',
                'body' => 'A single month of the injection alternative runs around $1,000. The protocol in the video is a fraction of that — without the needle.',
            ]],
            'loss_aversion' => ['slot' => 'ps', 'mode' => 'set', 'value' => 'P.S. Every day you wait is another day in the same body. The page may not be here tomorrow.'],
            'sunk_cost' => ['slot' => 'body_sections', 'mode' => 'prepend_section', 'value' => [
                'heading' => 'After everything you have already tried',
                'body' => 'You have invested years, money, hope. Walking away now is what the industry counts on.',
            ]],
            'framing' => ['slot' => 'mechanism_tease', 'mode' => 'set', 'value' => 'Not 1 hormone like the shots — all 3, at home, in seconds.'],

            // ── Offer architecture ─────────────────────────────────────────────────────────────
            'risk_reversal_strong' => ['slot' => 'body_sections', 'mode' => 'prepend_section', 'value' => [
                'heading' => '60-day money-back, no questions',
                'body' => 'Try it for 60 full days. If it is not for you, every dollar back — and you keep the bonuses.',
            ]],
            'better_than_free' => ['slot' => 'body_sections', 'mode' => 'prepend_section', 'value' => [
                'heading' => 'Better than a refund',
                'body' => 'If it does not work, you keep every bonus — the equivalent of being paid to try.',
            ]],
            'scarcity_quantity' => ['slot' => 'cta_blocks', 'mode' => 'append', 'value' => [
                'label' => 'Only 100 spots left', 'sub' => 'First-action bonus included for the first 100.',
            ]],
            'fast_action_bonus' => ['slot' => 'body_sections', 'mode' => 'prepend_section', 'value' => [
                'heading' => 'Fast-action bonus',
                'body' => 'The first 100 buyers today also get the founder kit on top of everything else.',
            ]],

            // ── Objection ──────────────────────────────────────────────────────────────────────
            'price_too_high' => ['slot' => 'objection_flips', 'mode' => 'append', 'value' => [
                'objection' => "It's expensive", 'flip' => 'Less than the cost of a single injection — and you keep using it long after that bottle ran out.',
            ]],
            'wont_work_for_me' => ['slot' => 'objection_flips', 'mode' => 'append', 'value' => [
                'objection' => "It won't work for me", 'flip' => 'It is built specifically for women over 40 — the body that the diets were never designed for.',
            ]],
            'tried_everything' => ['slot' => 'objection_flips', 'mode' => 'append', 'value' => [
                'objection' => "I've tried everything", 'flip' => 'That is exactly why this works — everything you tried fixed the wrong thing.',
            ]],
            'is_it_scam' => ['slot' => 'objection_flips', 'mode' => 'append', 'value' => [
                'objection' => 'Is this a scam?', 'flip' => 'Fair question. Watch the presentation, see the science, and decide. 60-day money-back if it is not for you.',
            ]],
            'do_it_later' => ['slot' => 'ps', 'mode' => 'set', 'value' => 'P.S. "Later" is what the industry counts on. Tomorrow is the same as today. The page may not be.'],

            // ── Hook / Lead ─────────────────────────────────────────────────────────────────────
            'hook_callout_specific' => ['slot' => 'kicker', 'mode' => 'set', 'value' => 'FOR WOMEN OVER 40 WHO HAVE TRIED EVERYTHING'],
            'hook_warning' => ['slot' => 'kicker', 'mode' => 'set', 'value' => 'WARNING · READ BEFORE YOU SPEND ANOTHER DOLLAR ON INJECTIONS'],
            'lead_secret' => ['slot' => 'mechanism_tease', 'mode' => 'set', 'value' => 'The secret the wealthy use instead of injections.'],
            'lead_proclamation' => ['slot' => 'mechanism_tease', 'mode' => 'set', 'value' => 'The biggest breakthrough in metabolic health in a decade.'],

            // ── Narrative voice ─────────────────────────────────────────────────────────────────
            'rule_of_three' => ['slot' => 'mechanism_tease', 'mode' => 'set', 'value' => 'No diet. No gym. No needle.'],
            'metaphor_anchor' => ['slot' => 'mechanism_tease', 'mode' => 'set', 'value' => 'A metabolic backdoor — a switch nobody told you about.'],

            // ── Funnel sequence ─────────────────────────────────────────────────────────────────
            'micro_commit' => ['slot' => 'cta_blocks', 'mode' => 'append', 'value' => [
                'label' => 'Watch the free presentation first', 'sub' => 'See if it fits before deciding.',
            ]],

            default => null,
        };
    }

    /**
     * Apply a snippet to the bridge at the right slot.
     *
     * @param  array{slot:string,value:mixed,mode:string}  $snippet
     * @return array<string,mixed>
     */
    private function inject(array $bridge, array $snippet): array
    {
        $slot = $snippet['slot'];
        $mode = $snippet['mode'];
        $value = $snippet['value'];

        if ($mode === 'set') {
            $bridge[$slot] = $value;

            return $bridge;
        }
        if ($mode === 'append') {
            $list = is_array($bridge[$slot] ?? null) ? $bridge[$slot] : [];
            $list[] = $value;
            $bridge[$slot] = $list;

            return $bridge;
        }
        if ($mode === 'prepend_section') {
            $list = is_array($bridge['body_sections'] ?? null) ? $bridge['body_sections'] : [];
            array_unshift($list, $value);
            $bridge['body_sections'] = $list;
        }

        return $bridge;
    }

    /** Same flattener the composer uses, kept local so the amplifier is standalone. */
    private function copyOf(array $bridge): string
    {
        $parts = [
            (string) ($bridge['headline'] ?? ''),
            (string) ($bridge['kicker'] ?? ''),
            (string) ($bridge['subheadline'] ?? ''),
            (string) ($bridge['lead_paragraph'] ?? ''),
            (string) ($bridge['mechanism_tease'] ?? ''),
            (string) ($bridge['ps'] ?? ''),
        ];
        foreach ((array) ($bridge['body_sections'] ?? []) as $s) {
            $parts[] = is_array($s) ? (string) ($s['heading'] ?? '').' '.(string) ($s['body'] ?? $s['text'] ?? '') : (string) $s;
        }
        foreach ((array) ($bridge['cta_blocks'] ?? []) as $c) {
            $parts[] = is_array($c) ? (string) ($c['label'] ?? '').' '.(string) ($c['sub'] ?? '') : (string) $c;
        }
        foreach ((array) ($bridge['objection_flips'] ?? []) as $o) {
            $parts[] = is_array($o) ? (string) ($o['objection'] ?? '').' '.(string) ($o['flip'] ?? '') : (string) $o;
        }
        foreach ((array) (($bridge['proof_block']['testimonials'] ?? [])) as $t) {
            $parts[] = is_array($t) ? (string) ($t['quote'] ?? '').' '.(string) ($t['result'] ?? '') : (string) $t;
        }

        return trim(implode("\n", array_filter($parts)));
    }

    private function meets(string $current, string $target): bool
    {
        $order = ['flat' => 0, 'weak' => 1, 'decent' => 2, 'strong' => 3, 'killer' => 4];

        return ($order[$current] ?? 0) >= ($order[$target] ?? 4);
    }

    private function mechanism(AiMarketingVslAsset $asset): string
    {
        return trim((string) preg_replace('/\s*\(.*$/u', '', (string) $asset->mechanism_name)) ?: 'protocol';
    }

    private function authority(AiMarketingVslAsset $asset): string
    {
        $devices = is_array($asset->persuasion_devices) ? $asset->persuasion_devices : [];
        foreach ((array) ($devices['authority'] ?? []) as $a) {
            $a = trim((string) preg_replace('/\s*[\(\[,:\-—].*$/u', '', (string) (is_array($a) ? reset($a) : $a)));
            if ($a !== '' && preg_match('/^\p{Lu}/u', $a) && str_word_count($a) <= 3) {
                return $a;
            }
        }

        return 'a respected doctor';
    }

    private function enemy(AiMarketingVslAsset $asset): string
    {
        $devices = is_array($asset->persuasion_devices) ? $asset->persuasion_devices : [];
        $conspiracy = $devices['conspiracy'] ?? '';
        $present = is_array($conspiracy) ? $conspiracy !== [] : (string) $conspiracy !== '';

        return $present ? 'Big Pharma' : 'The industry';
    }

    private function number(AiMarketingVslAsset $asset): string
    {
        $metrics = is_array($asset->metrics) ? $asset->metrics : [];
        foreach ((array) ($metrics['result_claims'] ?? []) as $c) {
            if (! is_scalar($c)) {
                continue; // nested/array claim → skip (was an "Array to string conversion" warning + silent miss)
            }
            if (preg_match('/(\d{2,3})\s*(lbs?|pounds|libras|kg)/iu', (string) $c, $m) && (int) $m[1] >= 30 && (int) $m[1] <= 90) {
                return $m[1].' lbs';
            }
        }

        return '';
    }
}
