<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Registry;

use App\Services\Ai\AutonomousEvolution\SelfModel\Registry\AtlasLoopModelRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AtlasLoopModelRegistryTest extends TestCase
{
    public function test_promote_with_receipt_sets_active_and_appends_history(): void
    {
        $registry = new AtlasLoopModelRegistry;

        $result = $registry->promote('muscle-v1', true);

        $this->assertSame(['status' => 'ok', 'active' => 'muscle-v1'], $result);
        $this->assertSame('muscle-v1', $registry->active());
        $this->assertNull($registry->previous());
        $this->assertSame([
            ['version' => 'muscle-v1', 'promoted_at' => 'promotion-000000', 'receipt' => true],
        ], $registry->history());
    }

    public function test_promote_without_receipt_rejects_and_leaves_active_unchanged(): void
    {
        $registry = new AtlasLoopModelRegistry;
        $registry->promote('muscle-v1', true);

        $result = $registry->promote('muscle-v2', false);

        $this->assertSame(['status' => 'rejected', 'reason' => 'operator_receipt_required'], $result);
        $this->assertSame('muscle-v1', $registry->active());
        $this->assertNull($registry->previous());
        $this->assertSame(['muscle-v1'], array_column($registry->history(), 'version'));
    }

    public function test_second_receipted_promote_moves_prior_active_into_previous(): void
    {
        $registry = new AtlasLoopModelRegistry;
        $registry->promote('muscle-v1', true);

        $result = $registry->promote('muscle-v2', true);

        $this->assertSame(['status' => 'ok', 'active' => 'muscle-v2'], $result);
        $this->assertSame('muscle-v2', $registry->active());
        $this->assertSame('muscle-v1', $registry->previous());
        $this->assertSame(['muscle-v1', 'muscle-v2'], array_column($registry->history(), 'version'));
        $this->assertSame(['promotion-000000', 'promotion-000001'], array_column($registry->history(), 'promoted_at'));
    }

    public function test_revert_to_previous_swaps_active_back_without_receipt(): void
    {
        $registry = new AtlasLoopModelRegistry;
        $registry->promote('muscle-v1', true);
        $registry->promote('muscle-v2', true);

        $result = $registry->revertToPrevious();

        $this->assertSame(['status' => 'reverted', 'active' => 'muscle-v1', 'from' => 'muscle-v2'], $result);
        $this->assertSame('muscle-v1', $registry->active());
        $this->assertSame('muscle-v2', $registry->previous());
    }

    public function test_revert_to_previous_with_no_previous_is_blocked(): void
    {
        $registry = new AtlasLoopModelRegistry;
        $registry->promote('muscle-v1', true);

        $result = $registry->revertToPrevious();

        $this->assertSame(['status' => 'blocked', 'reason' => 'no_previous_version'], $result);
        $this->assertSame('muscle-v1', $registry->active());
        $this->assertNull($registry->previous());
    }

    public function test_history_is_append_only_through_public_api(): void
    {
        $registry = new AtlasLoopModelRegistry;
        $registry->promote('muscle-v1', true);
        $history = $registry->history();
        $history[] = ['version' => 'tampered', 'promoted_at' => 'now', 'receipt' => false];

        $this->assertSame(['muscle-v1'], array_column($registry->history(), 'version'));

        $publicMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(AtlasLoopModelRegistry::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertNotContains('update', $publicMethods);
        $this->assertNotContains('delete', $publicMethods);
    }
}
