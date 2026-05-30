<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Frontier\Ports;

use App\Models\AiJob;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\ClaudeCliProvider;
use App\Services\Ai\Foundry\Frontier\Ports\AtlasClaudeCliFrontierGeneratorService;
use Tests\TestCase;

/**
 * Proves the REAL Claude-CLI (Opus 4.8) frontier generator: forces model claude-opus-4-8,
 * parses strict-JSON 13-key proposals, and is real-or-blocked (provider failure / unparseable
 * output => blocked, never fabricated). Uses a ClaudeCliProvider double — no real CLI call.
 */
final class AtlasClaudeCliFrontierGeneratorServiceTest extends TestCase
{
    private function dossier(): array
    {
        return ['area_id' => 'agentic_engineering_os', 'anchors' => [['anchor_id' => 'anc_1']]];
    }

    private function validProposalJson(): string
    {
        $proposal = [
            'proposal_id' => 'leap-compounding-memory',
            'horizon' => 'extreme',
            'title' => 'Compounding memory multiplier',
            'thesis' => 'Wire governed memory into every cycle.',
            'evidence_refs' => [['anchor_id' => 'anc_1']],
            'why_it_multiplies' => 'Each cycle compounds prior learning.',
            'success_metric' => 'merge_throughput up >=20% over 50 cycles',
            'rollback' => 'git revert the wiring commit',
            'risk_level' => 'medium',
            'dependencies' => [],
            'proposed_packets' => [['kind' => 'wiring', 'owner_candidate' => 'atlas_dev', 'label' => 'memory wire']],
            'provider_tier_required' => 'premium',
            'anti_pattern_self_check' => 'not benchmark/rivals; evidence-bound.',
        ];

        return 'Here are the proposals: '.json_encode([$proposal]).' done';
    }

    private function provider(string $output, bool $ok = true): ClaudeCliProvider
    {
        return new class($output, $ok) extends ClaudeCliProvider
        {
            public function __construct(private string $out, private bool $okFlag)
            {
                // intentionally do not call parent::__construct — no real deps needed.
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                // Assert the operator-mandated model is forced.
                \PHPUnit\Framework\Assert::assertSame(AtlasClaudeCliFrontierGeneratorService::MODEL, $job->model);

                return new AiProviderResult(
                    ok: $this->okFlag,
                    output: $this->out,
                    command: ['claude'],
                    exitCode: $this->okFlag ? 0 : 1,
                    durationMs: 10,
                    stdout: $this->out,
                    stderr: '',
                );
            }
        };
    }

    public function test_generates_schema_valid_proposals_from_claude_opus_4_8(): void
    {
        $gen = new AtlasClaudeCliFrontierGeneratorService($this->provider($this->validProposalJson()));
        $out = $gen->generate($this->dossier(), 3);

        $this->assertSame('generated', $out['status']);
        $this->assertSame('real:claude_cli:claude-opus-4-8', $out['generator_label']);
        $this->assertCount(1, $out['proposals']);
        $this->assertSame('leap-compounding-memory', $out['proposals'][0]['proposal_id']);
        $this->assertTrue($out['claim_policy']['provider_invoked']);
        $this->assertFalse($out['claim_policy']['writes_canon']);
        $this->assertArrayHasKey('leap-compounding-memory', $out['provenance']);
    }

    public function test_provider_failure_blocks_honestly(): void
    {
        $gen = new AtlasClaudeCliFrontierGeneratorService($this->provider('', false));
        $out = $gen->generate($this->dossier(), 3);

        $this->assertSame('blocked', $out['status']);
        $this->assertContains('claude_cli_provider_failed', $out['generator_blocked_reasons']);
        $this->assertSame([], $out['proposals']);
    }

    public function test_unparseable_output_blocks_never_fabricates(): void
    {
        $gen = new AtlasClaudeCliFrontierGeneratorService($this->provider('no json here at all'));
        $out = $gen->generate($this->dossier(), 3);

        $this->assertSame('blocked', $out['status']);
        $this->assertContains('claude_cli_no_parseable_proposals', $out['generator_blocked_reasons']);
        $this->assertSame([], $out['proposals']);
    }

    public function test_invalid_shape_proposals_are_dropped(): void
    {
        // A JSON array whose elements lack the 13 keys yields zero valid proposals => blocked.
        $gen = new AtlasClaudeCliFrontierGeneratorService($this->provider('[{"title":"missing keys"}]'));
        $out = $gen->generate($this->dossier(), 3);

        $this->assertSame('blocked', $out['status']);
        $this->assertContains('claude_cli_no_parseable_proposals', $out['generator_blocked_reasons']);
    }
}
