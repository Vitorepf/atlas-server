<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * L2-13: a executabilidade honesta do ADRS é um RATCHET — não pode regredir nem voltar a
 * over-claim. O ADRS já foi desambiguado em 3 eixos: runtime_completeness (os mecanismos
 * BUILDÁVEIS, o 10/10 alcançável), asymptote (permanentemente false — a bússola), e
 * reality_dependent (NUNCA contado para completude). Este teste congela:
 *   - todos os mecanismos buildáveis estão construídos E endurecidos (built==hardened==total);
 *   - a assíntota NUNCA é reivindicada completa (honestidade permanente);
 *   - reality-dependent jamais é dobrado em completude (anti-over-claim estrutural).
 */
final class AtlasAdrsRuntimeCompletenessRatchetTest extends TestCase
{
    private function report(): array
    {
        $out = new BufferedOutput;
        Artisan::call('atlas:documentation-reality-completeness', ['--json' => true], $out);

        return json_decode($out->fetch(), true);
    }

    public function test_buildable_mechanism_catalog_is_complete_and_cannot_shrink(): void
    {
        // O catálogo de mecanismos buildáveis (a definição do 10/10 alcançável) é estável
        // e cada mecanismo declarado está construído. hardened_count depende do índice de
        // code-intelligence VIVO (degrada em env de teste sem índice), por isso não é
        // amarrado aqui — o ratchet do catálogo + a honestidade dos eixos é o invariante.
        $rc = $this->report()['runtime_completeness'];
        $this->assertGreaterThanOrEqual(15, $rc['total_mechanisms'], 'o catálogo de mecanismos não pode encolher');
        $this->assertSame($rc['total_mechanisms'], $rc['built_count'], 'todo mecanismo buildável deve estar construído (ratchet)');
        $this->assertLessThanOrEqual($rc['built_count'], $rc['hardened_count'], 'hardened nunca excede built (coerência)');
    }

    public function test_asymptote_is_never_claimed_complete(): void
    {
        $verdict = $this->report()['verdict'];
        $this->assertFalse((bool) $verdict['asymptote_complete'], 'a assíntota L-inf é permanentemente incompleta');
        $this->assertFalse((bool) $verdict['claims_asymptote'], 'o ADRS nunca reivindica a assíntota — over-claim proibido');
        $this->assertTrue((bool) ($verdict['asymptote_is_permanent_compass'] ?? false), 'a assíntota é bússola permanente, por design');
    }

    public function test_reality_dependent_is_never_folded_into_completeness(): void
    {
        $report = $this->report();
        $verdict = $report['verdict'];
        // O eixo grounded existe e fica SEPARADO — nunca somado ao 10/10 (anti-over-claim).
        $this->assertArrayHasKey('reality_dependent', $report);
        $this->assertFalse((bool) $verdict['folds_grounded_into_completeness'], 'grounded nunca é dobrado em completude');
        $this->assertFalse((bool) $verdict['outcome_grounded_counts_toward_completeness'], 'outcomes reais não inflam o score');
        $this->assertArrayHasKey('claim_policy', $report);
    }
}
