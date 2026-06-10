<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use App\Models\HermesMcpCapabilityCandidate;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

/**
 * Quarantines an MCP server that Hermes asked for but Atlas has NOT allowlisted
 * (or that surfaced from a catalog install). The server descriptor is recorded
 * as a governed `HermesMcpCapabilityCandidate` — never enabled, never promoted
 * automatically. Only sha256 digests of the command/url/env/headers/oauth reach
 * the persisted payload, so a quarantined candidate never leaks a raw secret.
 * ATLS stays the capability authority: promotion is gated downstream.
 */
class HermesMcpCapabilityCandidateRecorder
{
    use HermesAdapterReceipt;

    /**
     * @param  array<string,mixed>  $serverDescriptor
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     */
    public function record(array $serverDescriptor, AiJob $job, array $mission, array $invocation): ?HermesMcpCapabilityCandidate
    {
        if (! DatabaseTableAvailability::has('hermes_mcp_capability_candidates')) {
            return null;
        }

        $name = $this->string($serverDescriptor['name'] ?? null, 190) ?: 'hermes_mcp_server_unknown';
        $transport = $this->transport($serverDescriptor);
        $source = $this->source($serverDescriptor);
        $candidateHash = $this->candidateHash($name, $transport, $serverDescriptor);

        $existing = HermesMcpCapabilityCandidate::query()
            ->where('candidate_hash', $candidateHash)
            ->first();
        if ($existing instanceof HermesMcpCapabilityCandidate) {
            return $existing;
        }

        return HermesMcpCapabilityCandidate::query()->create([
            'server_name' => $name,
            'candidate_hash' => $candidateHash,
            'transport' => $transport,
            'status' => 'persisted_for_review',
            'source' => $source,
            'risk_class' => 'high',
            'promotion_allowed' => false,
            'review_required' => true,
            'payload_json' => $this->payload($name, $transport, $source, $serverDescriptor),
            'evidence_refs_json' => $this->evidence($serverDescriptor, $job, $mission, $invocation),
            'promotion_gate_json' => $this->promotionGate(),
            'reviewed_at' => null,
            'expires_at' => now()->addDays(90),
        ]);
    }

    /**
     * @param  array<string,mixed>  $serverDescriptor
     * @return array<string,mixed>
     */
    private function payload(string $name, string $transport, string $source, array $serverDescriptor): array
    {
        return [
            'schema_version' => 'atlas.hermes.mcp_capability_candidate.v1',
            'server_name' => $name,
            'transport' => $transport,
            'source' => $source,
            'gate_status' => 'quarantined_for_atlas_capability_review',
            'review_status' => 'pending',
            'enabled_now' => false,
            'promotion_allowed_now' => false,
            'enablement_requires_atlas_capability_gate' => true,
            'canonical_tool_authority' => 'atlas',
            'command_hash' => $this->commandHash($serverDescriptor),
            'url_hash' => $this->valueHash($serverDescriptor['url'] ?? null),
            'env_keys_hash' => $this->keysHash($serverDescriptor['env'] ?? null),
            'headers_hash' => $this->keysHash($serverDescriptor['headers'] ?? null),
            'oauth_hash' => $this->oauthHash($serverDescriptor['oauth'] ?? null),
            'tool_filters' => $this->toolFilters($serverDescriptor['tools'] ?? null),
            'risk_class' => 'high',
        ];
    }

    /**
     * @param  array<string,mixed>  $serverDescriptor
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<int,array<string,mixed>>
     */
    private function evidence(array $serverDescriptor, AiJob $job, array $mission, array $invocation): array
    {
        return [
            [
                'kind' => 'hermes_mcp_capability_candidate',
                'server_name' => $this->string($serverDescriptor['name'] ?? null, 190),
                'trace_id' => is_string($job->trace_id) && trim($job->trace_id) !== '' ? trim($job->trace_id) : null,
                'mission_id' => $mission['mission_id'] ?? null,
                'mission_hash' => $mission['mission_hash'] ?? null,
                'cli_invocation_hash' => $this->hashValue($invocation),
                'enabled_now' => false,
                'promotion_allowed_now' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function promotionGate(): array
    {
        return [
            'gate' => 'AtlasCapabilityGate',
            'capability_authority' => 'atlas',
            'enabled_now' => false,
            'promotion_allowed_now' => false,
            'enablement_requires_atlas_capability_gate' => true,
            'review_required' => true,
            'always_quarantine' => true,
            'risk_class' => 'high',
            'danger_requires_operator_authority' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $serverDescriptor
     */
    private function candidateHash(string $name, string $transport, array $serverDescriptor): string
    {
        return hash('sha256', implode('|', [
            $name,
            $transport,
            (string) $this->commandHash($serverDescriptor),
            (string) $this->valueHash($serverDescriptor['url'] ?? null),
        ]));
    }

    /**
     * @param  array<string,mixed>  $serverDescriptor
     */
    private function commandHash(array $serverDescriptor): ?string
    {
        $command = $serverDescriptor['command'] ?? null;
        $args = $serverDescriptor['args'] ?? null;

        $parts = [];
        if (is_string($command) || is_numeric($command)) {
            $parts[] = (string) $command;
        }
        if (is_array($args)) {
            foreach ($args as $arg) {
                if (is_string($arg) || is_numeric($arg)) {
                    $parts[] = (string) $arg;
                }
            }
        }

        if ($parts === []) {
            return null;
        }

        return hash('sha256', implode(' ', $parts));
    }

    private function valueHash(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : hash('sha256', $value);
    }

    private function keysHash(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $keys = array_values(array_filter(array_map(
            fn (mixed $key): ?string => is_string($key) ? $key : (is_numeric($key) ? (string) $key : null),
            array_keys($value),
        )));

        if ($keys === []) {
            return null;
        }

        sort($keys);

        return hash('sha256', implode(',', $keys));
    }

    private function oauthHash(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{include:array<int,string>,exclude:array<int,string>}
     */
    private function toolFilters(mixed $tools): array
    {
        $tools = is_array($tools) ? $tools : [];

        return [
            'include' => $this->stringList($tools['include'] ?? null, 64, 190),
            'exclude' => $this->stringList($tools['exclude'] ?? null, 64, 190),
        ];
    }

    /**
     * @param  array<string,mixed>  $serverDescriptor
     */
    private function transport(array $serverDescriptor): string
    {
        $transport = $this->string($serverDescriptor['transport'] ?? null, 40);
        if ($transport !== null && in_array($transport, ['stdio', 'http', 'sse'], true)) {
            return $transport;
        }

        $url = $this->string($serverDescriptor['url'] ?? null, 2000);

        return $url !== null ? 'http' : 'stdio';
    }

    /**
     * @param  array<string,mixed>  $serverDescriptor
     */
    private function source(array $serverDescriptor): string
    {
        $source = $this->string($serverDescriptor['source'] ?? null, 40);

        return in_array($source, ['manifest_diff', 'catalog_install'], true) ? $source : 'manifest_diff';
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value, int $limit, int $itemLimit): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? preg_split('/\s*,\s*/', $value) ?: [] : []);

        return collect(array_slice($items, 0, $limit))
            ->map(fn (mixed $item): ?string => $this->string($item, $itemLimit))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
