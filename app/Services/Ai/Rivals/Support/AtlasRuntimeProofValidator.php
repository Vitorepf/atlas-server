<?php

namespace App\Services\Ai\Rivals\Support;

use App\Support\IsNonEmptyString;

/** Structural verifier shared by receipt attachment and uplift comparison. */
final class AtlasRuntimeProofValidator
{
    public const SCHEMA_V2 = 'atlas.rivals2.atlas_dev_bridge_receipt.v2';

    public function valid(array $proof, string $expectedModel, ?string $runDir = null): bool
    {
        if (($proof['real_provider'] ?? false) !== true
            || ($proof['model'] ?? null) !== $expectedModel
            || data_get($proof, 'fair_mode.single_provider') !== true
            || data_get($proof, 'fair_mode.decide_disabled') !== true
            || data_get($proof, 'fair_mode.fallback_disabled') !== true
            || data_get($proof, 'usage.present') !== true
            || ((int) data_get($proof, 'usage.input_tokens', 0)
                + (int) data_get($proof, 'usage.output_tokens', 0)) < 1) {
            return false;
        }

        if (($proof['schema_version'] ?? null) !== self::SCHEMA_V2) {
            // Legacy proofs only remain admissible when they carry the original
            // independently-derived marker. New proofs use the governed v2 path.
            return ($proof['atlas_runtime'] ?? false) === true;
        }

        if (($proof['status'] ?? null) !== 'passed'
            || ($proof['failure_reason'] ?? null) !== null
            || ($proof['provider'] ?? null) !== 'hermes_cli') {
            return false;
        }

        if (($proof['runtime_contract'] ?? null) === 'atlas.hermes_cli_provider.v1') {
            return $this->validResponseProof($proof, $runDir);
        }

        return ($proof['atlas_runtime'] ?? false) === true
            && ($proof['execution'] ?? null) === 'atlas_cli_dev_efficient'
            && (int) data_get($proof, 'provider_call.provider_calls', 0) > 0
            && $this->validNativeStreams($proof, $runDir);
    }

    private function validResponseProof(array $proof, ?string $runDir): bool
    {
        if (! $this->nonEmpty($proof['execution_id'] ?? null)
            || ($proof['runtime_contract'] ?? null) !== 'atlas.hermes_cli_provider.v1'
            || data_get($proof, 'governance.atlas_is_sovereign') !== true
            || data_get($proof, 'governance.executive_mission_schema') !== 'atlas.hermes.executive_mission.v1'
            || data_get($proof, 'governance.result_packet_schema') !== 'atlas.hermes.result_packet.v1'
            || data_get($proof, 'governance.safe_mode') !== true) {
            return false;
        }

        $calls = is_array($proof['calls'] ?? null) ? $proof['calls'] : [];
        $providerCalls = (int) data_get($proof, 'provider_call.provider_calls', 0);
        $successful = (int) data_get($proof, 'provider_call.successful_responses', 0);
        if ($calls === [] || $providerCalls !== count($calls) || $successful < 1) {
            return false;
        }

        $observedSuccessful = 0;
        foreach ($calls as $call) {
            if (! is_array($call) || ! in_array($call['status'] ?? null, ['passed', 'failed'], true)) {
                return false;
            }
            if (! $this->nonEmpty($call['stdout_path'] ?? null)
                || ! $this->nonEmpty($call['stderr_path'] ?? null)
                || ! $this->readableStream($runDir, (string) ($call['stdout_path'] ?? ''))
                || ! $this->readableStream($runDir, (string) ($call['stderr_path'] ?? ''))) {
                return false;
            }
            if (($call['status'] ?? null) === 'failed') {
                if (! $this->nonEmpty($call['failure_reason'] ?? null)) {
                    return false;
                }

                continue;
            }
            $observedSuccessful++;
            if (! $this->nonEmpty($call['executive_mission_hash'] ?? null)
                || ! $this->nonEmpty($call['result_packet_hash'] ?? null)
                || ((int) ($call['input_tokens'] ?? 0) + (int) ($call['output_tokens'] ?? 0)) < 1) {
                return false;
            }
        }

        return $observedSuccessful === $successful;
    }

    private function validNativeStreams(array $proof, ?string $runDir): bool
    {
        foreach (['stdout', 'stderr'] as $stream) {
            $evidence = data_get($proof, "native_streams.{$stream}");
            if (! is_array($evidence)
                || ($evidence['present'] ?? false) !== true
                || ($evidence['verified'] ?? false) !== true
                || ! $this->nonEmpty($evidence['path'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/', (string) ($evidence['sha256'] ?? ''))
                || ! $this->readableStream($runDir, (string) ($evidence['path'] ?? ''))) {
                return false;
            }
            if ($runDir !== null
                && hash_file('sha256', rtrim($runDir, '/').'/'.$evidence['path']) !== $evidence['sha256']) {
                return false;
            }
        }

        return true;
    }

    private function nonEmpty(mixed $value): bool
    {
        return IsNonEmptyString::check($value);
    }

    private function readableStream(?string $runDir, string $relativePath): bool
    {
        if ($runDir === null) {
            return true;
        }
        if ($relativePath === '' || str_starts_with($relativePath, '/')
            || in_array('..', explode('/', str_replace('\\', '/', $relativePath)), true)) {
            return false;
        }

        return is_readable(rtrim($runDir, '/').'/'.$relativePath);
    }
}
