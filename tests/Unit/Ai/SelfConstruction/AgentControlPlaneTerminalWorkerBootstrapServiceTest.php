<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use PHPUnit\Framework\TestCase;

final class AgentControlPlaneTerminalWorkerBootstrapServiceTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/AgentControlPlaneTerminalWorkerBootstrapService.php');
    }

    // ── never_stop_before_drain contract ──────────────────────────────────────

    public function test_bootstrap_contains_never_stop_before_drain_contract(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("'never_stop_before_drain_contract'", $source, 'must emit never_stop_before_drain_contract key');
        $this->assertStringContainsString("'contract' => 'never_stop_before_drain'", $source, 'contract type must be declared');
    }

    public function test_contract_covers_retry_on_no_claimable_and_waiting_and_give_back(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("'no_claimable_task' => 'replenish_and_retry'", $source, 'must cover no_claimable_task retry');
        $this->assertStringContainsString("'waiting_on_dependencies' => 'retry_after_delay'", $source, 'must cover waiting_on_dependencies retry');
        $this->assertStringContainsString("'give_back' => 'pull_next_task'", $source, 'must cover give_back retry');
    }

    public function test_contract_forbids_comfortable_queue_stop_language(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("'comfortable_queue'", $source, 'must forbid comfortable_queue');
        $this->assertStringContainsString("'sufficient_depth'", $source, 'must forbid sufficient_depth');
        $this->assertStringContainsString("'adequate_supply'", $source, 'must forbid adequate_supply');
    }

    public function test_contract_includes_allowed_files_and_one_task_at_a_time_constraints(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("'allowed_files_only' => true", $source, 'must enforce allowed_files_only');
        $this->assertStringContainsString("'one_task_at_a_time' => true", $source, 'must enforce one_task_at_a_time');
    }

    public function test_all_payload_paths_include_never_stop_contract(): void
    {
        $source = $this->source();

        // Count occurrences: should appear in bootstrap(), blockedBeforeClaimPayload(), and preview()
        $count = substr_count($source, "'never_stop_before_drain_contract'");
        $this->assertGreaterThanOrEqual(3, $count, "contract must appear in all 3 payload paths (bootstrap, blocked, preview), got {$count}");
    }

    public function test_source_has_no_comfortable_queue_stop_language_in_contract_description(): void
    {
        $source = $this->source();

        // The contract description must NOT contain "stop when comfortable" or similar permissive language
        $this->assertStringNotContainsString('stop when comfortable', $source);
        $this->assertStringNotContainsString('stop when sufficient', $source);
    }
}
