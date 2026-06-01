<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodeCodexSlatePremiumService;
use Tests\TestCase;

/**
 * Pins the slate-premium canon of Atlas Code Codex Slate Premium v1: forbidden
 * warm-graphite/black backgrounds, no pulse-halo status dots, calm
 * letter-spacing (0 in body, max 0.04em), no serif italic in operational body,
 * and slate tokens kept out of the global :root scope.
 *
 * @see docs/engineering-knowledge-base/atlas-code-codex-slate-premium-v1.md
 */
class AtlasCodeCodexSlatePremiumTest extends TestCase
{
    private function service(): AtlasCodeCodexSlatePremiumService
    {
        return new AtlasCodeCodexSlatePremiumService();
    }

    /**
     * The doc's own canonical example (tokens under .atlas-shell.surface-code,
     * static status dot with box-shadow: none / animation: none, canonical slate
     * hexes) must pass clean — zero violations, ok=true. This also pins the
     * canonical bg/accent/on-gold values verbatim from ## Contratos.
     */
    public function test_canonical_example_passes_clean_and_pins_token_values(): void
    {
        $result = $this->service()->auditCanonExample();

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['counts']['total']);
        $this->assertSame([], $result['violations']);

        $tokens = AtlasCodeCodexSlatePremiumService::canonTokens();
        $this->assertSame('.atlas-shell.surface-code', $tokens['scope']);
        $this->assertSame('#1d2b34', $tokens['bg']['base']);
        $this->assertSame('#11202a', $tokens['bg']['terminal']);
        $this->assertSame('#d4a85a', $tokens['accent']['accent']);
        $this->assertSame('#15212a', $tokens['accent']['on_gold']);
    }

    /**
     * forbidden_changes: reintroducing warm graphite / pure black as bg is
     * banned. Each of the four documented hexes is flagged; #1d2b34 (the
     * canonical slate) is NOT, and #0001 must not trip the #000 rule.
     */
    public function test_forbidden_background_hexes_are_flagged(): void
    {
        $css = <<<CSS
        .atlas-shell.surface-code { --cc-bg: #1d2b34; }
        .panel-a { background: #23211c; }
        .panel-b { background: #1c1a16; }
        .panel-c { background: #16140f; }
        .panel-d { background: #000; }
        .shadow { box-shadow: 0 0 0 #0001; }
        CSS;

        $result = $this->service()->inspectSource($css, 'bg_test');

        $this->assertFalse($result['ok']);
        // Exactly the four forbidden hexes — slate #1d2b34 and #0001 excluded.
        $this->assertSame(4, $result['counts']['forbidden_bg']);
        foreach ($result['violations'] as $v) {
            $this->assertSame(
                AtlasCodeCodexSlatePremiumService::FINDING_FORBIDDEN_BG,
                $v['finding'],
            );
        }
    }

    /**
     * decisions: "Status dots SEMPRE estaticos. Animacao pulse halo proibida."
     * A pulse animation or a glowing box-shadow on a status dot is flagged; the
     * canonical static dot (box-shadow: none) is not.
     */
    public function test_pulse_halo_on_status_dots_is_flagged(): void
    {
        $css = <<<CSS
        .cc-status-dot { animation: pulse 1.4s infinite; }
        .cc-status-dot[data-status='running'] { box-shadow: 0 0 8px var(--cc-info); }
        .cc-status-dot[data-pulse='true'] { box-shadow: none; animation: none; }
        CSS;

        $result = $this->service()->inspectSource($css, 'pulse_test');

        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['counts']['pulse_halo']);
        // The static dot line (box-shadow: none) produced no finding.
        $this->assertSame(2, $result['counts']['total']);
    }

    /**
     * decisions: "Letter-spacing 0 em corpo; max 0.04em." forbidden_changes:
     * "letter-spacing 1.0-1.8px em corpo/botoes/dados." The brutal px value and
     * an over-max em value are flagged; 0.04em (the boundary) and a JSX
     * camelCase brutal value are handled correctly.
     */
    public function test_brutal_letter_spacing_is_flagged_in_css_and_jsx(): void
    {
        $src = <<<SRC
        .eyebrow { letter-spacing: 1.4px; }
        .label { letter-spacing: 0.08em; }
        .ok-eyebrow { letter-spacing: 0.04em; }
        const style = { letterSpacing: '1.6px' };
        SRC;

        $result = $this->service()->inspectSource($src, 'ls_test');

        $this->assertFalse($result['ok']);
        // 1.4px + 0.08em + 1.6px = 3 violations; 0.04em is allowed (boundary).
        $this->assertSame(3, $result['counts']['letter_spacing']);
    }

    /**
     * forbidden_changes: "Voltar serif italic em paragrafos operacionais." A
     * serif family with font-style italic is flagged; sans italic and serif
     * non-italic are not.
     */
    public function test_serif_italic_body_is_flagged_but_sans_italic_is_not(): void
    {
        $css = <<<CSS
        .quote { font-family: Cormorant, serif; font-style: italic; }
        .note { font-family: Inter, sans-serif; font-style: italic; }
        .title { font-family: Cormorant, serif; font-style: normal; }
        CSS;

        $result = $this->service()->inspectSource($css, 'serif_test');

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['counts']['serif_italic']);
        $this->assertSame('serif_italic', $result['violations'][0]['finding']);
    }

    /**
     * forbidden_changes: "Mover tokens slate teal para :root global (quebra
     * Cartografia)." A --cc-* token defined inside :root is a scope violation;
     * the same token under .atlas-shell.surface-code is fine.
     */
    public function test_slate_token_in_root_is_a_scope_violation(): void
    {
        $bad = <<<CSS
        :root {
          --cc-bg: #1d2b34;
        }
        .atlas-shell.surface-code {
          --cc-accent: #d4a85a;
        }
        CSS;

        $result = $this->service()->inspectSource($bad, 'scope_test');

        $this->assertFalse($result['ok']);
        // Only the :root --cc-bg is a scope violation; the scoped --cc-accent is fine.
        $this->assertSame(1, $result['counts']['token_scope']);
        $this->assertSame(
            AtlasCodeCodexSlatePremiumService::FINDING_TOKEN_SCOPE,
            $result['violations'][0]['finding'],
        );

        // Sanity: the exact same tokens, both correctly scoped, pass clean.
        $good = <<<CSS
        .atlas-shell.surface-code {
          --cc-bg: #1d2b34;
          --cc-accent: #d4a85a;
        }
        CSS;
        $this->assertTrue($this->service()->inspectSource($good, 'scope_ok')['ok']);
    }
}
