<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Tests\TestCase;

/**
 * Freezes the Obra #12 lesson: classes wired ONLY via constructor injection
 * (same-namespace DI has no `use` and no `::class`) must never be classified
 * as unused/0-ref by the reachability detectors.
 */
final class CodeRealityDiAwareClassificationTest extends TestCase
{
    public function test_code_intelligence_extracts_constructor_injection_relations(): void
    {
        $content = <<<'PHP'
        <?php

        namespace App\Services\Fake;

        use App\Services\Other\ImportedDependency;

        class NeedsDeps
        {
            public function __construct(
                private readonly SameNamespaceDependency $local,
                private readonly ImportedDependency $imported,
                private readonly ?SameNamespaceDependency $nullable,
                private string $scalar,
            ) {}
        }
        PHP;

        $method = new \ReflectionMethod(EngineeringCodeIntelligenceService::class, 'parsePhpRelations');
        $relations = $method->invoke(
            app(EngineeringCodeIntelligenceService::class),
            'app/Services/Fake/NeedsDeps.php',
            $content,
            'fake-module',
        );

        $injections = array_values(array_filter(
            $relations['symbol_references'],
            static fn (array $row): bool => $row['kind'] === 'php_constructor_injection',
        ));
        $symbols = array_column($injections, 'symbol');

        // Same-namespace DI (the Obra #12 blind spot: no use, no ::class) resolves to FQCN.
        $this->assertContains('App\Services\Fake\SameNamespaceDependency', $symbols);
        // Imported DI resolves through the use-map.
        $this->assertContains('App\Services\Other\ImportedDependency', $symbols);
        // Nullable hint counts; scalar hint never does.
        $this->assertSame(3, count($injections));
    }

    public function test_class_injected_only_via_constructor_is_not_an_unused_candidate(): void
    {
        // Real Obra #12 falso-órfão: SRLForethoughtCapture is wired ONLY through
        // SRLEpisodeRepository's constructor (same namespace, no use statement).
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)
            ->classify('app/Services/Ai/Learning/SRL/SRLForethoughtCapture.php');

        $this->assertTrue(data_get($payload, 'evidence.reachability.signals.has_constructor_injectors'));
        $this->assertContains(
            'app/Services/Ai/Learning/SRL/SRLEpisodeRepository.php',
            data_get($payload, 'evidence.reachability.source_breakdown.constructor_injectors.paths'),
        );
        $this->assertSame('active_read_only', $payload['classification']);
        $this->assertNotSame('unused_candidate', $payload['classification']);
    }
}
