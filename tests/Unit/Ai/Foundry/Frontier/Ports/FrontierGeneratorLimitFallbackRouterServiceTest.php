<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Frontier\Ports;

use App\Services\Ai\Foundry\Frontier\Ports\FrontierGeneratorLimitFallbackRouterService;
use App\Services\Ai\Foundry\Frontier\Ports\FrontierGeneratorPort;
use Tests\TestCase;

/**
 * Proves the Opus 4.8 -> Codex 5.5 limit-fallback routing is DETERMINISTIC and
 * proposal-only, driven entirely by fake FrontierGeneratorPort seams (NO real
 * provider, NO MiniMax/Opus/Codex spend):
 *  - primary generated  => primary used, no fallback.
 *  - primary LIMITED    => Codex fallback used automatically, with audit trail.
 *  - primary hard-failed => honest block, NO fallback, NO fabrication.
 */
final class FrontierGeneratorLimitFallbackRouterServiceTest extends TestCase
{
    /** A canned generator port that records whether it was invoked. */
    private function gen(array $result): FrontierGeneratorPort
    {
        return new class($result) implements FrontierGeneratorPort
        {
            public bool $invoked = false;

            public function __construct(private array $result) {}

            public function generate(array $dossier, int $count, array $context = []): array
            {
                $this->invoked = true;

                return $this->result;
            }
        };
    }

    private function generated(string $label, string $provider, string $model): array
    {
        return [
            'status' => 'generated',
            'proposals' => [['proposal_id' => 'p1']],
            'provenance' => [],
            'generator_label' => $label,
            'generator_provider_resolved' => $provider,
            'generator_model_resolved' => $model,
            'generator_blocked_reasons' => [],
            'provider_limited' => false,
            'claim_policy' => ['provider_invoked' => true, 'proposal_only' => true],
        ];
    }

    private function limited(): array
    {
        return [
            'status' => 'blocked',
            'proposals' => [],
            'provenance' => [],
            'generator_label' => 'real:claude_cli:claude-opus-4-8',
            'generator_provider_resolved' => 'claude_cli',
            'generator_model_resolved' => 'claude-opus-4-8',
            'generator_blocked_reasons' => ['provider_limited'],
            'provider_limited' => true,
            'provider_limit_error_code' => 'rate_limited',
            'claim_policy' => ['provider_invoked' => true, 'proposal_only' => true],
        ];
    }

    private function hardFailed(): array
    {
        return [
            'status' => 'blocked',
            'proposals' => [],
            'provenance' => [],
            'generator_label' => 'real:claude_cli:claude-opus-4-8',
            'generator_provider_resolved' => 'claude_cli',
            'generator_model_resolved' => 'claude-opus-4-8',
            'generator_blocked_reasons' => ['claude_cli_provider_failed'],
            'provider_limited' => false,
            'claim_policy' => ['provider_invoked' => true, 'proposal_only' => true],
        ];
    }

    public function test_primary_generated_keeps_opus_and_never_invokes_codex(): void
    {
        $primary = $this->gen($this->generated('real:claude_cli:claude-opus-4-8', 'claude_cli', 'claude-opus-4-8'));
        $fallback = $this->gen($this->generated('real:codex_cli:gpt-5.5-codex', 'codex_cli', 'gpt-5.5-codex'));

        $router = new FrontierGeneratorLimitFallbackRouterService($primary, $fallback);
        $out = $router->generate(['anchors' => []], 3);

        $this->assertSame('generated', $out['status']);
        $this->assertSame('real:claude_cli:claude-opus-4-8', $out['generator_label']);
        $this->assertFalse($fallback->invoked, 'Codex must NOT be invoked when Opus generated.');
        $this->assertSame(FrontierGeneratorLimitFallbackRouterService::ROUTE_PRIMARY, $out['fallback_audit']['route']);
        $this->assertFalse($out['fallback_audit']['fallback_invoked']);
    }

    public function test_opus_limit_routes_to_codex_automatically_with_audit(): void
    {
        $primary = $this->gen($this->limited());
        $fallback = $this->gen($this->generated('real:codex_cli:gpt-5.5-codex', 'codex_cli', 'gpt-5.5-codex'));

        $router = new FrontierGeneratorLimitFallbackRouterService($primary, $fallback);
        $out = $router->generate(['anchors' => []], 3);

        // Codex produced the proposals.
        $this->assertSame('generated', $out['status']);
        $this->assertSame('real:codex_cli:gpt-5.5-codex', $out['generator_label']);
        $this->assertSame('codex_cli', $out['generator_provider_resolved']);
        $this->assertTrue($fallback->invoked, 'Codex MUST be invoked on an Opus limit.');

        // Deterministic, auditable fallback trail.
        $audit = $out['fallback_audit'];
        $this->assertSame(FrontierGeneratorLimitFallbackRouterService::ROUTE_FALLBACK, $audit['route']);
        $this->assertTrue($audit['fallback_invoked']);
        $this->assertTrue($audit['primary_provider_limited']);
        $this->assertSame('rate_limited', $audit['primary_limit_error_code']);
        $this->assertSame('real:claude_cli:claude-opus-4-8', $audit['primary_generator_label']);
        $this->assertSame('real:codex_cli:gpt-5.5-codex', $audit['effective_generator_label']);
    }

    public function test_hard_failure_blocks_without_fallback_or_fabrication(): void
    {
        $primary = $this->gen($this->hardFailed());
        $fallback = $this->gen($this->generated('real:codex_cli:gpt-5.5-codex', 'codex_cli', 'gpt-5.5-codex'));

        $router = new FrontierGeneratorLimitFallbackRouterService($primary, $fallback);
        $out = $router->generate(['anchors' => []], 3);

        $this->assertSame('blocked', $out['status']);
        $this->assertSame([], $out['proposals']);
        $this->assertContains('claude_cli_provider_failed', $out['generator_blocked_reasons']);
        $this->assertFalse($fallback->invoked, 'A generic failure is NOT a limit; Codex must NOT be invoked.');
        $this->assertSame(FrontierGeneratorLimitFallbackRouterService::ROUTE_BLOCKED, $out['fallback_audit']['route']);
    }
}
