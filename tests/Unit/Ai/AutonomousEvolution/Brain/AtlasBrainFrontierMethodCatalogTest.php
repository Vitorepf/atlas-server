<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierMethodCatalog;
use Tests\TestCase;

final class AtlasBrainFrontierMethodCatalogTest extends TestCase
{
    public function test_inspect_returns_schema_and_counts(): void
    {
        $r = (new AtlasBrainFrontierMethodCatalog)->inspect();
        self::assertSame(AtlasBrainFrontierMethodCatalog::SCHEMA, $r['schema']);
        self::assertSame($r['total'], $r['implemented'] + $r['unharvested']);
        self::assertGreaterThan(0, $r['implemented']);
        self::assertGreaterThan(0, $r['unharvested']);
    }

    public function test_implemented_methods_resolve_to_existing_classes(): void
    {
        $r = (new AtlasBrainFrontierMethodCatalog)->inspect();
        foreach ($r['methods'] as $m) {
            if ($m['status'] === 'implemented') {
                self::assertNotNull($m['organ_class']);
                self::assertTrue(class_exists($m['organ_class']), "{$m['id']} class must exist");
            }
        }
    }

    public function test_method_ids_are_unique(): void
    {
        $ids = array_column((new AtlasBrainFrontierMethodCatalog)->inspect()['methods'], 'id');
        self::assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainFrontierMethodCatalog.php',
                true
            )
        );
    }
}
