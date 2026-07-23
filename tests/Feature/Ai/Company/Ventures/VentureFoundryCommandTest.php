<?php

namespace Tests\Feature\Ai\Company\Ventures;

use App\Models\AiVenture;
use App\Models\AiVentureIdea;
use App\Models\AiVentureStrategyReview;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureFoundryCommandTest extends TestCase
{
    use CreatesStrategyRuntimeTables;
    use CreatesVentureFoundryTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
        $this->createVentureFoundryTables();
    }

    protected function tearDown(): void
    {
        $this->dropVentureFoundryTables();
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    public function test_full_lifecycle_idea_to_strategist_review_via_command(): void
    {
        // 1. Register an idea.
        $exit = $this->artisan('atlas:venture', [
            'action' => 'idea-register',
            '--title' => 'SaaS de auditoria continua',
            '--problem' => 'Auditoria manual nao escala',
            '--icp' => 'Empresas reguladas',
            '--pain' => 'Multas e retrabalho',
            '--urgency' => 'high',
            '--market-size-usd' => '2000000000',
            '--pain-severity' => '4',
            '--founder-fit' => '5',
            '--sovereignty-fit' => '5',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
        $idea = AiVentureIdea::query()->firstOrFail();

        // 2. Promote idea to venture.
        $exit = $this->artisan('atlas:venture', [
            'action' => 'promote',
            '--idea' => $idea->idea_id,
            '--name' => 'Audita Continua',
            '--thesis' => 'Auditoria continua governada para empresas reguladas.',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);

        $venture = AiVenture::query()->firstOrFail();
        $this->assertSame('S0', $venture->stage);
        $this->assertSame('active', $venture->status);
        $this->assertEqualsWithDelta(100_000_000.0, (float) $venture->target_arr_usd, 0.01);
        $this->assertSame('promoted', $idea->refresh()->status);

        // 3. Declare business rules.
        foreach ([
            ['pricing', 'Preço mínimo de 200 USD/mês.'],
            ['customer', 'Atender apenas empresas reguladas.'],
            ['finance', 'Margem bruta mínima de 65%.'],
        ] as [$category, $statement]) {
            $exit = $this->artisan('atlas:venture', [
                'action' => 'rule-add',
                '--venture' => $venture->venture_id,
                '--category' => $category,
                '--statement' => $statement,
                '--json' => true,
            ])->run();
            $this->assertSame(0, $exit);
        }

        // 4. Record a metric observation.
        $exit = $this->artisan('atlas:venture', [
            'action' => 'metric-record',
            '--venture' => $venture->venture_id,
            '--metric' => 'arr_usd',
            '--value' => '25000',
            '--currency' => 'USD',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);

        // 5. Strategist review without --apply keeps the stage.
        $exit = $this->artisan('atlas:venture', [
            'action' => 'strategist-review',
            '--venture' => $venture->venture_id,
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
        $this->assertSame('S0', $venture->refresh()->stage);
        $this->assertSame(1, AiVentureStrategyReview::query()->count());

        // 6. Review with --apply promotes only to the evidence-backed stage.
        //    Opportunity is still missing, so the recommendation stays S0.
        $exit = $this->artisan('atlas:venture', [
            'action' => 'strategist-review',
            '--venture' => $venture->venture_id,
            '--apply' => true,
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
        $this->assertSame('S0', $venture->refresh()->stage);

        $review = AiVentureStrategyReview::query()->latest('created_at')->firstOrFail();
        $this->assertSame('S0', $review->recommended_stage);
        $this->assertNotEmpty($review->gaps);
        $this->assertNotEmpty($review->next_actions);

        // 7. Sector status reports counts.
        $exit = $this->artisan('atlas:venture', ['action' => 'status', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_command_reports_errors_as_structured_failure(): void
    {
        $exit = $this->artisan('atlas:venture', [
            'action' => 'venture-show',
            '--venture' => 'nao-existe',
            '--json' => true,
        ])->run();
        $this->assertSame(1, $exit);

        $exit = $this->artisan('atlas:venture', [
            'action' => 'acao-desconhecida',
            '--json' => true,
        ])->run();
        $this->assertSame(1, $exit);
    }
}
