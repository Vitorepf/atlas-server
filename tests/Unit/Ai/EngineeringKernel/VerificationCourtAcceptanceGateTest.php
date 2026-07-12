<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasAutonomosGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasForgeGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasObraGateAdapter;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\EngineeringKernel\VerificationCourtAcceptanceGate;
use PHPUnit\Framework\TestCase;

final class VerificationCourtAcceptanceGateTest extends TestCase
{
    public function test_complete_floor_and_court_bundle_promotes(): void
    {
        $verdict = (new VerificationCourtAcceptanceGate)->certify($this->bundle(), TrustLevel::Dev);

        self::assertSame(CertVerdict::PROMOTE, $verdict->status);
        self::assertArrayHasKey('verification_roles', $verdict->invariants);
        self::assertArrayHasKey('false_green_replay', $verdict->invariants);
    }

    public function test_missing_role_blocks_even_when_floor_is_green(): void
    {
        $facts = $this->courtFacts();
        unset($facts['dispositions'][1]);
        $verdict = (new VerificationCourtAcceptanceGate)->certify($this->bundle($facts), TrustLevel::Dev);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('verification_roles', $verdict->blockers);
        self::assertStringContainsString('missing_role:product_management', $verdict->invariants['verification_roles']['detail']);
    }

    public function test_forged_na_self_verification_and_hash_drift_block(): void
    {
        $facts = $this->courtFacts();
        $facts['dispositions'][0]['status'] = 'not_applicable';
        $facts['dispositions'][0]['na_proof'] = false;
        $facts['dispositions'][1]['author_id'] = 'same';
        $facts['dispositions'][1]['verifier_id'] = 'same';
        $facts['actual_hashes']['world_hash'] = str_repeat('f', 64);
        $verdict = (new VerificationCourtAcceptanceGate)->certify($this->bundle($facts), TrustLevel::Forge);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('verification_roles', $verdict->blockers);
        self::assertContains('verification_hashes', $verdict->blockers);
    }

    public function test_replay_false_green_blocks_and_preserves_component_reason(): void
    {
        $facts = $this->courtFacts();
        $facts['replay']['replay_outcomes'][0]['passed'] = false;
        $verdict = (new VerificationCourtAcceptanceGate)->certify($this->bundle($facts), TrustLevel::Autonomos);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('false_green_replay', $verdict->blockers);
        self::assertStringContainsString('replay_red:cmd-1', $verdict->invariants['false_green_replay']['detail']);
    }

    public function test_all_surface_adapters_route_court_facts_through_the_same_gate(): void
    {
        $bundle = $this->bundle();
        $adapters = [
            [new AtlasDevGateAdapter, TrustLevel::Dev],
            [new AtlasForgeGateAdapter, TrustLevel::Forge],
            [new AtlasAutonomosGateAdapter, TrustLevel::Autonomos],
            [new AtlasObraGateAdapter, TrustLevel::Autonomos],
        ];

        foreach ($adapters as [$adapter, $trust]) {
            $verdict = $adapter->certify($bundle, $trust);
            self::assertSame(CertVerdict::PROMOTE, $verdict->status, $adapter::class.': '.implode(',', $verdict->blockers));
            self::assertArrayHasKey('verification_roles', $verdict->invariants);
        }
    }

    public function test_master_plan_role_names_are_normalized_at_the_court_boundary(): void
    {
        $facts = $this->courtFacts();
        $facts['required_roles'] = EngineeringRoleRoster::CANONICAL_ROLES;
        $facts['dispositions'] = array_map(
            static fn (array $disposition): array => array_replace($disposition, ['role' => EngineeringRoleRoster::canonicalRole((string) $disposition['role'])]),
            $facts['dispositions'],
        );

        $verdict = (new VerificationCourtAcceptanceGate)->certify($this->bundle($facts), TrustLevel::Dev);

        self::assertSame(CertVerdict::PROMOTE, $verdict->status);
    }

    public function test_unknown_master_role_is_fail_closed(): void
    {
        $facts = $this->courtFacts();
        $facts['required_roles'][0] = 'invented_quality_role';

        $verdict = (new VerificationCourtAcceptanceGate)->certify($this->bundle($facts), TrustLevel::Dev);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertStringContainsString('unknown_quality_foundry_role', $verdict->invariants['verification_roles']['detail']);
    }

    /** @param array<string,mixed>|null $facts */
    private function bundle(?array $facts = null): AcceptanceBundle
    {
        return AcceptanceBundleFactory::honest(['non_functional' => ['verification_court' => $facts ?? $this->courtFacts()]]);
    }

    /** @return array<string,mixed> */
    private function courtFacts(): array
    {
        return [
            'required_roles' => EngineeringRoleRoster::OFFICIAL_ROLES,
            'dispositions' => array_map(
                static fn (string $role): array => ['role' => $role, 'status' => 'pass', 'author_id' => 'author-a', 'verifier_id' => 'judge-a'],
                EngineeringRoleRoster::OFFICIAL_ROLES,
            ),
            'expected_hashes' => ['world_hash' => str_repeat('a', 64), 'spec_hash' => str_repeat('b', 64), 'order_hash' => str_repeat('c', 64)],
            'actual_hashes' => ['world_hash' => str_repeat('a', 64), 'spec_hash' => str_repeat('b', 64), 'order_hash' => str_repeat('c', 64)],
            'allegation' => ['task_packet_id' => 'packet-1', 'lease_id' => 'lease-1', 'allowed_files_hash' => 'files-1', 'command_hash' => 'command-1'],
            'evidence' => ['receipt_chain' => ['task_packet_id' => 'packet-1', 'lease_id' => 'lease-1', 'allowed_files_hash' => 'files-1', 'command_hash' => 'command-1']],
            'replay' => [
                'evidence_contract_result' => ['accepted' => true],
                'replay_plan_result' => ['plan_status' => 'ready', 'commands' => [['id' => 'cmd-1', 'name' => 'focused']], 'blockers' => []],
                'replay_outcomes' => [['command_id' => 'cmd-1', 'passed' => true, 'output_present' => true]],
                'changed_files' => ['app/Foo.php'], 'allowed_files' => ['app/Foo.php'],
            ],
        ];
    }
}
