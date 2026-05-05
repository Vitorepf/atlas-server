<?php

namespace Tests\Unit\Ai\Kernel\Pipeline;

use App\Services\Ai\Kernel\Pipeline\PipelineInput;
use Tests\TestCase;

class PipelineInputTest extends TestCase
{
    public function test_audit_array_is_stable_for_reordered_nested_hints_and_metadata_keys(): void
    {
        $left = PipelineInput::fromArray([
            'text' => 'same prompt',
            'hints' => [
                'flow' => 'programming.dev',
                'nested' => [
                    'b' => 2,
                    'a' => 1,
                ],
            ],
            'metadata' => [
                'z_secret' => 'hidden',
                'a_secret' => 'hidden',
            ],
        ])->toAuditArray();

        $right = PipelineInput::fromArray([
            'text' => 'same prompt',
            'hints' => [
                'nested' => [
                    'a' => 1,
                    'b' => 2,
                ],
                'flow' => 'programming.dev',
            ],
            'metadata' => [
                'a_secret' => 'hidden',
                'z_secret' => 'hidden',
            ],
        ])->toAuditArray();

        $this->assertSame($left['primary_text_hash'], $right['primary_text_hash']);
        $this->assertSame($left['hints_hash'], $right['hints_hash']);
        $this->assertSame($left['input_fingerprint'], $right['input_fingerprint']);
        $this->assertSame(['a_secret', 'z_secret'], $left['metadata_keys']);
        $this->assertSame($left['metadata_keys'], $right['metadata_keys']);
    }

    public function test_audit_fingerprint_matches_audit_array_without_self_reference(): void
    {
        $input = PipelineInput::fromArray([
            'text' => 'fingerprint me',
            'hints' => ['flow' => 'programming.dev'],
            'metadata' => ['raw_prompt' => 'hidden'],
        ]);
        $audit = $input->toAuditArray();
        $payloadWithoutFingerprint = $audit;
        unset($payloadWithoutFingerprint['input_fingerprint']);

        $this->assertSame($input->auditFingerprint(), $audit['input_fingerprint']);
        $this->assertSame(hash('sha256', json_encode($payloadWithoutFingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $input->auditFingerprint());
    }

    public function test_from_array_ignores_non_scalar_primary_text_without_php_cast_warning(): void
    {
        $input = PipelineInput::fromArray([
            'text' => ['not' => 'a scalar'],
            'input_type' => ['also' => 'ignored'],
            'surface_id' => null,
        ]);

        $audit = $input->toAuditArray();

        $this->assertSame(hash('sha256', ''), $audit['primary_text_hash']);
        $this->assertSame('text', $audit['input_type']);
        $this->assertSame('kernel_pipeline_scaffold', $audit['surface_id']);
    }

    public function test_from_array_parses_string_boolean_dry_run_values(): void
    {
        $falseInput = PipelineInput::fromArray([
            'text' => 'audit false dry run request',
            'dry_run' => 'false',
        ]);
        $trueInput = PipelineInput::fromArray([
            'text' => 'audit true dry run request',
            'dry_run' => 'true',
        ]);

        $this->assertFalse($falseInput->dryRun);
        $this->assertFalse($falseInput->toAuditArray()['dry_run']);
        $this->assertTrue($trueInput->dryRun);
        $this->assertTrue($trueInput->toAuditArray()['dry_run']);
    }
}
