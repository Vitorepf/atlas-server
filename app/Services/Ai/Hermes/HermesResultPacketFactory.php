<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use App\Services\Ai\AiProviderResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class HermesResultPacketFactory
{
    /**
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<string,mixed>
     */
    public function build(AiJob $job, AiProviderResult $result, array $mission, array $invocation): array
    {
        $memoryCandidates = $this->memoryDeltaCandidates($result->output);
        $packet = [
            'schema_version' => 'atlas.hermes.result_packet.v1',
            'result_id' => $this->resultId($job, $result, $mission),
            'mission_id' => (string) ($mission['mission_id'] ?? ''),
            'mission_hash' => (string) ($mission['mission_hash'] ?? ''),
            'executor' => 'hermes_cli',
            'status' => $result->ok ? 'succeeded' : 'failed',
            'atlas_is_sovereign' => true,
            'provider_is_executor_only' => true,
            'output' => [
                'response_hash' => $result->output !== '' ? hash('sha256', $result->output) : null,
                'response_bytes' => strlen($result->output),
                'response_line_count' => $result->output === '' ? 0 : count(preg_split('/\R/u', $result->output) ?: []),
                'stdout_hash' => $result->stdout !== '' ? hash('sha256', $result->stdout) : null,
                'stderr_hash' => $result->stderr !== '' ? hash('sha256', $result->stderr) : null,
            ],
            'runtime' => [
                'duration_ms' => $result->durationMs,
                'exit_code' => $result->exitCode,
                'error_code' => $result->errorCode,
                'error_message_hash' => $result->errorMessage ? hash('sha256', $result->errorMessage) : null,
                'cli_invocation_hash' => $this->hashValue($invocation),
            ],
            'evidence_packet' => [
                'schema_version' => 'atlas.hermes.evidence_packet.v1',
                'evidence_required' => true,
                'provider_output_recorded' => true,
                'attempt_metadata_contains_packet' => true,
                'atlas_verifier_is_authority' => true,
                'operator_claims_allowed_from_packet_only' => false,
            ],
            'tool_summary' => [
                'toolsets' => Arr::wrap(data_get($mission, 'runtime.toolsets', [])),
                'skills' => Arr::wrap(data_get($mission, 'runtime.skills', [])),
                'gateway_allowed' => (bool) data_get($mission, 'runtime.gateway_allowed', false),
                'worktree' => (bool) data_get($mission, 'runtime.worktree', false),
            ],
            'gateway' => [
                'gateway_allowed' => (bool) data_get($mission, 'runtime.gateway_allowed', false),
                'delivery_authority' => 'atlas',
                'hermes_may_deliver_but_not_decide' => true,
            ],
            'memory_gate' => [
                'schema_version' => 'atlas.hermes.memory_gate.v1',
                'memory_policy' => (string) data_get($mission, 'memory_policy.hermes_memory', 'off'),
                'canonical_memory' => 'atlas',
                'promotion_allowed_now' => false,
                'promotion_requires_atlas_memory_gate' => true,
                'candidate_count' => count($memoryCandidates),
                'candidates' => $memoryCandidates,
            ],
            'validation' => [
                'required_commands' => Arr::wrap(data_get($mission, 'validation.required_commands', [])),
                'status' => data_get($mission, 'validation.required_commands') ? 'pending_atlas_verifier' : 'no_commands_required',
                'result_packet_is_not_validation_proof' => true,
            ],
        ];

        $packet['result_hash'] = $this->hashValue(Arr::except($packet, ['result_hash']));

        return $packet;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function memoryDeltaCandidates(string $output): array
    {
        $decoded = $this->decodeCandidateEnvelope($output);
        if ($decoded === null) {
            return [];
        }

        $items = is_array($decoded['candidates'] ?? null) ? $decoded['candidates'] : [];
        $candidates = [];
        foreach (array_slice($items, 0, 8) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $claim = $this->string($item['claim'] ?? null, 700);
            if ($claim === null) {
                continue;
            }

            $class = $this->string($item['class'] ?? null, 80) ?: 'fact';
            if (! in_array($class, ['fact', 'preference', 'procedure', 'workaround', 'noise'], true)) {
                $class = 'noise';
            }

            $suggestedAction = $this->string($item['suggested_action'] ?? null, 80) ?: 'quarantine';
            if (! in_array($suggestedAction, ['promote', 'quarantine', 'discard'], true)) {
                $suggestedAction = 'quarantine';
            }

            $confidence = is_numeric($item['confidence'] ?? null) ? (float) $item['confidence'] : 0.4;
            $confidence = min(1.0, max(0.0, $confidence));

            $candidates[] = [
                'candidate_id' => 'hermes_memory_candidate_'.substr(hash('sha256', $claim.'|'.$index), 0, 16),
                'claim' => $claim,
                'evidence' => $this->evidence($item['evidence'] ?? null),
                'confidence' => $confidence,
                'class' => $class,
                'source' => 'hermes_session',
                'suggested_action' => $suggestedAction,
                'gate_status' => 'quarantined_for_atlas_review',
                'promotion_allowed_now' => false,
            ];
        }

        return $candidates;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeCandidateEnvelope(string $output): ?array
    {
        foreach ($this->jsonBlocks($output) as $block) {
            try {
                $decoded = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (
                is_array($decoded)
                && ($decoded['schema_version'] ?? null) === 'atlas.hermes.memory_delta_candidates.v1'
            ) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function jsonBlocks(string $output): array
    {
        $blocks = [];
        if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/isu', $output, $matches)) {
            foreach ($matches[1] as $match) {
                $blocks[] = trim($match);
            }
        }

        $trimmed = trim($output);
        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            $blocks[] = $trimmed;
        }

        return array_values(array_unique($blocks));
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function evidence(mixed $value): array
    {
        $items = is_array($value) ? $value : [];
        $evidence = [];
        foreach (array_slice($items, 0, 5) as $item) {
            if (is_string($item) || is_numeric($item)) {
                $evidence[] = [
                    'kind' => 'hermes_output_excerpt',
                    'excerpt' => Str::limit((string) $item, 500, ''),
                ];
            } elseif (is_array($item)) {
                $excerpt = $this->string($item['excerpt'] ?? $item['text'] ?? null, 500);
                $ref = $this->string($item['ref'] ?? null, 180);
                if ($excerpt !== null || $ref !== null) {
                    $evidence[] = array_filter([
                        'kind' => $this->string($item['kind'] ?? null, 80) ?: 'hermes_output_excerpt',
                        'ref' => $ref,
                        'excerpt' => $excerpt,
                    ], static fn (mixed $entry): bool => $entry !== null && $entry !== '');
                }
            }
        }

        return $evidence;
    }

    private function resultId(AiJob $job, AiProviderResult $result, array $mission): string
    {
        return 'hermes_result_'.substr(hash('sha256', implode('|', [
            $job->getKey() ?: 'pending',
            $mission['mission_hash'] ?? '',
            $result->durationMs,
            $result->exitCode ?? 'null',
            $result->output !== '' ? hash('sha256', $result->output) : 'empty',
        ])), 0, 24);
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function hashValue(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        try {
            return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\JsonException) {
            return hash('sha256', serialize($value));
        }
    }
}
