<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\CapabilityMarketClearingService;
use App\Services\Ai\AtlasDecide\CapabilityMarketRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CapabilityMarketClearingServiceTest extends TestCase
{
    public function test_ineligible_route_is_rejected_before_cheaper_route_can_win(): void
    {
        $decision = (new CapabilityMarketClearingService)->clear($this->request(), [
            $this->route('cheap', quality: 'unknown', cost: 1.0),
            $this->route('proven', quality: 'proven', cost: 10.0),
        ]);

        self::assertSame('proven', $decision->selectedRoute);
        self::assertSame('quality_evidence_unknown', $decision->rejected['cheap']);
    }

    public function test_r5_requires_independent_verifier_diversity(): void
    {
        $decision = (new CapabilityMarketClearingService)->clear($this->request(risk: 'R5'), [
            $this->route('one', quality: 'proven', verifier: ['same'], cost: 1.0),
        ]);

        self::assertNull($decision->selectedRoute);
        self::assertSame('verifier_diversity_insufficient', $decision->rejected['one']);
    }

    public function test_deterministic_replay_is_independent_of_candidate_order(): void
    {
        $routes = [$this->route('b', quality: 'proven', cost: 2.0), $this->route('a', quality: 'proven', cost: 2.0)];
        $service = new CapabilityMarketClearingService;
        $first = $service->clear($this->request(), $routes);
        $second = $service->clear($this->request(), array_reverse($routes));

        self::assertSame($first->decisionHash, $second->decisionHash);
        self::assertSame('a', $first->selectedRoute);
    }

    public function test_proven_quality_wins_before_time_and_cost(): void
    {
        $decision = (new CapabilityMarketClearingService)->clear($this->request(), [
            $this->route('fast-calibrated', quality: 'calibrated', time: 1, cost: 1.0),
            $this->route('slow-proven', quality: 'proven', time: 999, cost: 999.0),
        ]);

        self::assertSame('slow-proven', $decision->selectedRoute);
    }

    public function test_route_for_wrong_risk_class_is_rejected(): void
    {
        $decision = (new CapabilityMarketClearingService)->clear($this->request(risk: 'R5'), [
            $this->route('r3-only', quality: 'proven', supportedRisk: ['R3']),
        ]);

        self::assertNull($decision->selectedRoute);
        self::assertSame('risk_fit_insufficient', $decision->rejected['r3-only']);
    }

    public function test_route_with_stale_order_or_snapshot_binding_is_rejected(): void
    {
        $route = $this->route('drifted', quality: 'proven');
        $route['snapshot_hash'] = str_repeat('f', 64);

        $decision = (new CapabilityMarketClearingService)->clear($this->request(), [$route]);

        self::assertNull($decision->selectedRoute);
        self::assertSame('request_binding_mismatch', $decision->rejected['drifted']);
    }

    public function test_invalid_request_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CapabilityMarketRequest::fromArray(array_replace($this->request()->toArray(), ['snapshot_hash' => 'bad']));
    }

    private function request(string $risk = 'R3'): CapabilityMarketRequest
    {
        return CapabilityMarketRequest::fromArray([
            'order_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64),
            'authority_hash' => str_repeat('c', 64), 'risk_class' => $risk,
            'required_capabilities' => ['php'], 'topology' => $risk === 'R5' ? 'candidate_set' : 'single',
        ]);
    }

    /** @param list<string> $verifier */
    private function route(string $id, string $quality, array $verifier = ['independent-a', 'independent-b'], float $cost = 3.0, int $time = 100, array $supportedRisk = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5']): array
    {
        return ['id' => $id, 'capabilities' => ['php'], 'authority_status' => 'active', 'allowed' => true,
            'quality_status' => $quality, 'quality_hash' => str_repeat('d', 64), 'verifier_families' => $verifier,
            'available' => true, 'estimated_time_ms' => $time, 'estimated_cost' => $cost, 'provider_version' => 'v1',
            'supported_risk_classes' => $supportedRisk, 'order_hash' => str_repeat('a', 64),
            'snapshot_hash' => str_repeat('b', 64), 'authority_hash' => str_repeat('c', 64),
            'quality_evidence' => ['capability' => 'php', 'risk_class' => 'R3', 'observation_window' => '7d'],];
    }
}
