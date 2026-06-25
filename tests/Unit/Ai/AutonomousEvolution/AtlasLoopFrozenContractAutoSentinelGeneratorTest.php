<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractAutoSentinelGenerator;
use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractOperatorReceiptVerifier;
use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractSentinelClobberException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the frozen-contract auto-sentinel generator: produces one assertion per invariant with
 * deterministic test-method names derived from the invariant text; refuses to overwrite an existing test
 * file without a valid operator-receipt token; succeeds with a valid token.
 */
final class AtlasLoopFrozenContractAutoSentinelGeneratorTest extends TestCase
{
    private function factsFixture(): array
    {
        return [
            'schema' => 'atlas.frozen_contract.v1',
            'class' => 'App\\Services\\Demo\\FrozenSample',
            'invariants' => [
                'INVARIANT: emits FACTS only, never a numeric score',
                'PETREO: never relax thresholds at runtime',
            ],
            'forbidden_mutations' => [],
            'anchors' => [],
            'contract_version' => 'v1',
        ];
    }

    public function test_generate_produces_one_assertion_per_invariant_with_deterministic_method_names(): void
    {
        $generator = new AtlasLoopFrozenContractAutoSentinelGenerator;
        $tmp = sys_get_temp_dir().'/atlas_sentinel_'.bin2hex(random_bytes(6)).'.php';

        try {
            $result = $generator->generate($this->factsFixture(), $tmp);

            $this->assertSame('FrozenSampleFrozenContractSentinelTest', $result['class_name']);
            // Deterministic test method names derived from the invariant text — full lowercase slug.
            $this->assertStringContainsString('test_invariant_invariant_emits_facts_only_never_a_numeric_score', $result['code']);
            $this->assertStringContainsString('test_invariant_petreo_never_relax_thresholds_at_runtime', $result['code']);
            // One assertion per invariant.
            $this->assertSame(2, substr_count($result['code'], 'assertStringContainsString'));
            // Target-class-exists guard test always present.
            $this->assertStringContainsString('test_target_class_still_exists', $result['code']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_generate_is_deterministic_byte_identical_for_identical_inputs(): void
    {
        $generator = new AtlasLoopFrozenContractAutoSentinelGenerator;
        $tmp = sys_get_temp_dir().'/atlas_sentinel_'.bin2hex(random_bytes(6)).'.php';

        try {
            $a = $generator->generate($this->factsFixture(), $tmp);
            $b = $generator->generate($this->factsFixture(), $tmp.'_other'); // different target path, same facts ⇒ same code
            $this->assertSame($a['code'], $b['code']);
        } finally {
            @unlink($tmp);
            @unlink($tmp.'_other');
        }
    }

    public function test_clobber_existing_file_without_receipt_token_throws(): void
    {
        $generator = new AtlasLoopFrozenContractAutoSentinelGenerator;
        $tmp = sys_get_temp_dir().'/atlas_sentinel_'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($tmp, "<?php /* existing */\n");

        try {
            $this->expectException(AtlasLoopFrozenContractSentinelClobberException::class);
            $generator->generate($this->factsFixture(), $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_clobber_existing_file_with_valid_receipt_token_succeeds(): void
    {
        $verifier = new class implements AtlasLoopFrozenContractOperatorReceiptVerifier
        {
            public function isValid(string $token): bool
            {
                return $token === 'OPERATOR-OK-001';
            }
        };
        $generator = new AtlasLoopFrozenContractAutoSentinelGenerator($verifier);
        $tmp = sys_get_temp_dir().'/atlas_sentinel_'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($tmp, "<?php /* existing */\n");

        try {
            $result = $generator->generate($this->factsFixture(), $tmp, 'OPERATOR-OK-001');
            $this->assertStringContainsString('FrozenContractSentinelTest', $result['code']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_default_verifier_refuses_every_token(): void
    {
        $generator = new AtlasLoopFrozenContractAutoSentinelGenerator;
        $tmp = sys_get_temp_dir().'/atlas_sentinel_'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($tmp, "<?php /* existing */\n");

        try {
            $this->expectException(AtlasLoopFrozenContractSentinelClobberException::class);
            $generator->generate($this->factsFixture(), $tmp, 'any-token');
        } finally {
            @unlink($tmp);
        }
    }

    public function test_empty_invariants_still_produces_target_class_exists_guard_only(): void
    {
        $generator = new AtlasLoopFrozenContractAutoSentinelGenerator;
        $facts = $this->factsFixture();
        $facts['invariants'] = [];
        $tmp = sys_get_temp_dir().'/atlas_sentinel_'.bin2hex(random_bytes(6)).'.php';

        try {
            $result = $generator->generate($facts, $tmp);
            $this->assertSame(0, substr_count($result['code'], 'assertStringContainsString'));
            $this->assertStringContainsString('test_target_class_still_exists', $result['code']);
        } finally {
            @unlink($tmp);
        }
    }
}
