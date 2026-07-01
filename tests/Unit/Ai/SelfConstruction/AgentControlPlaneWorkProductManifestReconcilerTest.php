<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkProductManifestReconciler;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneWorkProductManifestReconcilerTest extends TestCase
{
    public function test_blank_paths_are_ignored_but_counted_as_invalid(): void
    {
        $result = (new AgentControlPlaneWorkProductManifestReconciler)->reconcile(
            [['path' => ''], ['path' => 'app/Foo.php']],
            [['path' => ''], ['path' => 'app/Foo.php']],
        );

        $this->assertSame(2, $result['invalid_path_count']);
        $this->assertSame(1, $result['observed_output_count']);
        $this->assertSame(1, $result['expected_output_count']);
        $this->assertSame('manifest_reconciliation_clear', $result['status']);
    }

    public function test_duplicate_observed_paths_do_not_inflate_count_and_are_surfaced(): void
    {
        $result = (new AgentControlPlaneWorkProductManifestReconciler)->reconcile(
            [['path' => 'app/Foo.php'], ['path' => 'app/Foo.php'], ['path' => 'app/Foo.php']],
            [['path' => 'app/Foo.php']],
        );

        $this->assertSame(1, $result['observed_output_count']);
        $this->assertContains('app/Foo.php', $result['duplicate_observed_paths']);
        $this->assertSame(1, $result['duplicate_observed_path_count']);
    }

    public function test_missing_outputs_return_gaps_status_and_repair_next_action(): void
    {
        $result = (new AgentControlPlaneWorkProductManifestReconciler)->reconcile(
            [],
            [['path' => 'app/Foo.php']],
        );

        $this->assertSame('manifest_reconciliation_has_gaps', $result['status']);
        $this->assertSame('repair_manifest_before_collection', $result['next_action']);
        $this->assertSame(1, $result['missing_count']);
    }

    public function test_unexpected_outputs_return_gaps_status_and_repair_next_action(): void
    {
        $result = (new AgentControlPlaneWorkProductManifestReconciler)->reconcile(
            [['path' => 'app/Unexpected.php']],
            [['path' => 'app/Foo.php']],
        );

        $this->assertSame('manifest_reconciliation_has_gaps', $result['status']);
        $this->assertSame('repair_manifest_before_collection', $result['next_action']);
        $this->assertSame(1, $result['unexpected_count']);
    }

    public function test_clean_manifest_returns_clear_status_and_proceed_next_action(): void
    {
        $result = (new AgentControlPlaneWorkProductManifestReconciler)->reconcile(
            [['path' => 'app/Foo.php']],
            [['path' => 'app/Foo.php']],
        );

        $this->assertSame('manifest_reconciliation_clear', $result['status']);
        $this->assertSame('collection_may_proceed', $result['next_action']);
    }
}
