<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopReplenisherSiblingRoleCoherenceSentinel;
use PHPUnit\Framework\TestCase;

/**
 * Proves the sibling-role coherence sentinel: it REJECTS a role-token-only pairing (TransferGate↔ResourceGate,
 * the bug fixed today) and ACCEPTS a genuinely same-role pairing that shares a content noun
 * (TransferGate↔TransferAuditGate). FACTS-only, deterministic.
 */
final class AtlasLoopReplenisherSiblingRoleCoherenceSentinelTest extends TestCase
{
    private function sentinel(): AtlasLoopReplenisherSiblingRoleCoherenceSentinel
    {
        return new AtlasLoopReplenisherSiblingRoleCoherenceSentinel;
    }

    public function test_rejects_role_token_only_mismatch(): void
    {
        $orphan = 'TransferGate — a gate that authorizes moving funds. Transfer of funds.';
        $sibling = 'ResourceGate — a gate that allocates quota for resource pools.';

        $verdict = $this->sentinel()->inspectDocblocks($orphan, $sibling);

        $this->assertFalse($verdict['self_sufficient'], 'role-token-only pairing must be refused');
        $this->assertContains('role_token_only_mismatch', $verdict['deficiencies']);
        $this->assertContains('role_token_only_mismatch', $verdict['facts']['markers']);
        $this->assertSame(['gate'], $verdict['facts']['shared_tokens'], 'only the bare role suffix is shared');
        $this->assertSame([], $verdict['facts']['shared_content_tokens']);
    }

    public function test_accepts_same_role_sibling_sharing_a_content_noun(): void
    {
        $orphan = 'TransferGate — a gate that authorizes moving funds. Transfer of funds.';
        $sibling = 'TransferAuditGate — audit gate recording every transfer.';

        $verdict = $this->sentinel()->inspectDocblocks($orphan, $sibling);

        $this->assertTrue($verdict['self_sufficient'], 'a shared content noun (transfer) means coherent');
        $this->assertSame([], $verdict['deficiencies']);
        $this->assertContains('transfer', $verdict['facts']['shared_content_tokens']);
    }

    public function test_rejects_when_nothing_shared_at_all(): void
    {
        $verdict = $this->sentinel()->inspectDocblocks('alpha bravo charlie', 'delta echo foxtrot');

        $this->assertFalse($verdict['self_sufficient']);
        $this->assertContains('no_shared_semantic_token', $verdict['deficiencies']);
    }

    public function test_is_deterministic(): void
    {
        $orphan = 'TransferGate gate moving funds transfer';
        $sibling = 'ResourceGate gate quota resource';

        $a = $this->sentinel()->inspectDocblocks($orphan, $sibling);
        $b = $this->sentinel()->inspectDocblocks($orphan, $sibling);

        $this->assertSame(json_encode($a), json_encode($b), 'same input ⇒ byte-identical verdict');
    }
}
