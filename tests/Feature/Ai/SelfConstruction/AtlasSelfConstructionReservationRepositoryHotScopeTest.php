<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionReservationRepository;
use Tests\TestCase;

final class AtlasSelfConstructionReservationRepositoryHotScopeTest extends TestCase
{
    private function svc(): AtlasSelfConstructionReservationRepository
    {
        return new AtlasSelfConstructionReservationRepository;
    }

    private function validPacket(array $overrides = []): array
    {
        $id = 'hs-'.str_replace('.', '', uniqid('', true));
        return array_replace([
            'packet_id' => $id,
            'allowed_files' => [],
            'forbidden_files' => [],
        ], $overrides);
    }

    private function claim(string $file): array
    {
        $pkt = $this->validPacket(['allowed_files' => [$file]]);
        return $this->svc()->claim($pkt, 'hermes-muscle-12-'.$pkt['packet_id'], 'sess-1', 30, hash('sha256', (string) json_encode($pkt)));
    }

    public function test_non_hot_path_is_accepted(): void
    {
        $result = $this->claim('app/Services/Ai/SomeOtherModule/Test.php');
        // May be blocked by ledger overlap from prior runs, but NOT by hot_scope_forbidden.
        $reasons = $result['blocking_reasons'] ?? [];
        $this->assertNotContains('hot_scope_forbidden', $reasons);
    }

    public function test_forward_slash_hot_scope_is_blocked(): void
    {
        $result = $this->claim('app/Services/Ai/Voice/Command.php');
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('hot_scope_forbidden', $result['blocking_reasons'] ?? []);
    }

    public function test_backslash_hot_scope_is_blocked(): void
    {
        $result = $this->claim('app\\Services\\Ai\\Voice\\Command.php');
        $this->assertSame('blocked', $result['status']);
        $reasons = $result['blocking_reasons'] ?? [];
        $this->assertContains('hot_scope_forbidden', $reasons, 'backslash path must be detected as hot scope');
    }

    public function test_dot_prefix_hot_scope_is_blocked(): void
    {
        $result = $this->claim('./app/Services/Ai/Voice/Command.php');
        $this->assertSame('blocked', $result['status']);
        $reasons = $result['blocking_reasons'] ?? [];
        $this->assertContains('hot_scope_forbidden', $reasons, './ prefix path must be detected as hot scope');
    }
}
