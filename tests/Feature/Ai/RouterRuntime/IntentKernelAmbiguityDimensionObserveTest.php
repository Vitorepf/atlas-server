<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\IntentKernelService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

/**
 * Observe-wire: RequestAmbiguityDimensionClassifier → campo novo
 * 'ambiguity_dimension' dentro de signals do receipt AiAtlasIntentClassification
 * persistido por IntentKernelService::classify().
 */
class IntentKernelAmbiguityDimensionObserveTest extends TestCase
{
    use CreatesRouterRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRouterRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropRouterRuntimeTables();
        parent::tearDown();
    }

    public function test_verbo_sem_alvo_nomeia_missing_target_sem_mudar_intent(): void
    {
        $intent = app(IntentKernelService::class)->classify('implementa');

        // Observe-only: veredito pré-existente intacto.
        $this->assertSame(RouterRuntimeCanon::INTENT_PROGRAMMING, $intent->intent_type);

        $dimension = $intent->signals['ambiguity_dimension'];
        $this->assertIsArray($dimension);
        $this->assertSame('missing_target', $dimension['dimension']);
        $this->assertSame(['implementa'], $dimension['evidence']);
        $this->assertGreaterThanOrEqual(0.6, $dimension['confidence']);
        $this->assertLessThanOrEqual(0.95, $dimension['confidence']);
    }

    public function test_pedido_com_alvo_e_path_nomeia_none(): void
    {
        $intent = app(IntentKernelService::class)->classify('analisa o arquivo app/Services/Demo.php');

        $dimension = $intent->signals['ambiguity_dimension'];
        $this->assertIsArray($dimension);
        $this->assertSame('none', $dimension['dimension']);
        $this->assertSame([], $dimension['evidence']);
    }
}
