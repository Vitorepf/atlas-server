<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\BfclAdapter;
use App\Services\Ai\Rivals\Adapters\External\HarborTerminalBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\Tau2BenchAdapter;
use App\Services\Ai\Rivals\Core\SuiteRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class SuiteRegistryTest extends TestCase
{
    public function test_assert_complete_and_catalog_are_ten_of_ten(): void
    {
        $registry = new SuiteRegistry;
        $registry->assertComplete();

        $catalog = $registry->catalog();
        $this->assertCount(10, $catalog);
        $this->assertCount(10, $registry->externalSuiteIds());
        foreach ($catalog as $row) {
            $this->assertSame($row['repo_id'], $row['suite_id']);
            $this->assertTrue($row['adapter_resolves']);
            $this->assertSame($row['suite_id'], $row['adapter']);
        }
    }

    public function test_legacy_aliases_resolve_only_when_allowed(): void
    {
        $registry = new SuiteRegistry;
        $this->assertTrue($registry->isLegacyAlias('tau2_bfcl'));
        $this->assertSame('tau2_bench', $registry->canonicalize('tau2_bfcl'));
        $this->assertSame('terminal_bench', $registry->canonicalize('harbor_terminal_bench'));
        $this->assertInstanceOf(Tau2BenchAdapter::class, $registry->adapterFor('tau2_bfcl'));
        $this->assertInstanceOf(HarborTerminalBenchAdapter::class, $registry->adapterFor('harbor_terminal_bench'));

        $this->expectException(InvalidArgumentException::class);
        $registry->canonicalize('tau2_bfcl', allowLegacyAlias: false);
    }

    public function test_bfcl_and_tau2_are_distinct_adapters(): void
    {
        $registry = new SuiteRegistry;
        $this->assertInstanceOf(Tau2BenchAdapter::class, $registry->adapterFor('tau2_bench', allowLegacyAlias: false));
        $this->assertInstanceOf(BfclAdapter::class, $registry->adapterFor('bfcl', allowLegacyAlias: false));
        $this->assertNotSame(
            $registry->adapterFor('tau2_bench')::class,
            $registry->adapterFor('bfcl')::class
        );
    }
}
