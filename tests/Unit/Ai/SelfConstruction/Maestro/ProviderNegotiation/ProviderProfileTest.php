<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\ProviderProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderProfileTest extends TestCase
{
    public function test_raw_secret_looking_extras_fields_are_rejected_from_serialization(): void
    {
        $profile = new ProviderProfile(
            providerId: 'codex-cloud',
            declaredCapabilities: ['code'],
            observedCostPerTokenIn: 0.001,
            observedCostPerTokenOut: 0.002,
            observedP50LatencyMs: 200,
            currentLoadPct: 10,
            locality: 'cloud',
            sensitivityAllowed: ['unclassified'],
            extras: [
                'api_key' => 'sk-live-abc123',
                'secret_token' => 'xyz',
                'billing_credential' => 'cred-1',
                'password' => 'hunter2',
                'notes' => 'benign metadata',
            ],
        );

        $arr = $profile->toArray();

        $this->assertArrayNotHasKey('api_key', $arr['extras']);
        $this->assertArrayNotHasKey('secret_token', $arr['extras']);
        $this->assertArrayNotHasKey('billing_credential', $arr['extras']);
        $this->assertArrayNotHasKey('password', $arr['extras']);
        $this->assertSame('benign metadata', $arr['extras']['notes']);
    }

    public function test_capability_safety_level_cost_class_and_proof_strength_are_preserved(): void
    {
        $profile = new ProviderProfile(
            providerId: 'fable-local',
            declaredCapabilities: ['code', 'plan'],
            observedCostPerTokenIn: 0.0,
            observedCostPerTokenOut: 0.0,
            observedP50LatencyMs: 100,
            currentLoadPct: 5,
            locality: 'local',
            sensitivityAllowed: ['unclassified', 'sensitive'],
            extras: [],
            safetyLevel: 'high',
            costClass: 'free',
            proofStrength: 0.85,
        );

        $arr = $profile->toArray();

        $this->assertSame(['code', 'plan'], $arr['declared_capabilities']);
        $this->assertSame('high', $arr['safety_level']);
        $this->assertSame('free', $arr['cost_class']);
        $this->assertSame(0.85, $arr['proof_strength']);
    }

    public function test_defaults_apply_when_safety_cost_and_proof_fields_are_omitted(): void
    {
        $profile = new ProviderProfile('p', [], 0.0, 0.0, 0, 0, 'local', []);
        $arr = $profile->toArray();

        $this->assertSame(ProviderProfile::SAFETY_STANDARD, $arr['safety_level']);
        $this->assertSame(ProviderProfile::COST_CLASS_UNKNOWN, $arr['cost_class']);
        $this->assertSame(0.0, $arr['proof_strength']);
    }

    #[DataProvider('localitiesProvider')]
    public function test_local_subscription_and_internal_profiles_serialize_provider_neutral_metadata_only(string $locality): void
    {
        $profile = new ProviderProfile(
            providerId: 'p-'.$locality,
            declaredCapabilities: ['code'],
            observedCostPerTokenIn: 0.0,
            observedCostPerTokenOut: 0.0,
            observedP50LatencyMs: 50,
            currentLoadPct: 1,
            locality: $locality,
            sensitivityAllowed: ['unclassified'],
            extras: ['internal_auth_key' => 'raw-secret', 'region' => 'us-east'],
        );

        $arr = $profile->toArray();

        $this->assertArrayNotHasKey('internal_auth_key', $arr['extras']);
        $this->assertSame('us-east', $arr['extras']['region']);
        $this->assertSame($locality, $arr['locality']);
        $this->assertArrayHasKey('safety_level', $arr);
        $this->assertArrayHasKey('cost_class', $arr);
        $this->assertArrayHasKey('proof_strength', $arr);
    }

    /** @return list<array{0:string}> */
    public static function localitiesProvider(): array
    {
        return [['local'], ['subscription'], ['internal']];
    }
}
