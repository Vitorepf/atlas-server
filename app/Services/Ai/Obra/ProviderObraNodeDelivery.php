<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;

/**
 * AOBG N3.F2 — the PRODUCTION per-node delivery binding (the SPEND path).
 *
 * Drives the proven {@see AtlasLiveCodeDeliveryService} (request → real provider in
 * an isolated sandbox → php -l / self-test → CERTIFIED) for ONE obra node, and
 * shapes its result into the {@see ObraNodeDelivery} contract the executor consumes:
 * certified {path, content} files + the gates-passed gate receipt (the SAME sha256
 * scheme {@see \App\Services\Ai\RealExecution\MissionDeliveryOrchestrator} mints, so
 * the obra-accumulate materializer's gate is satisfied identically).
 *
 * This builds NO new code-gen engine — it is the thin adapter between the obra
 * spine and the already-proven delivery service. It is the ONLY place a provider
 * runs in the whole obra; everything else (planning, brain anchoring, accumulation,
 * outcome recording) is cost-free. In tests a fake {@see ObraNodeDelivery} stands in
 * for this binding so no tokens are burned.
 *
 * BRAIN-DRIVEN: the executor passes the node's pre-formatted, provider-safe
 * `brain_context` (derived from its `brain_refs`); we prepend it to the delivery only
 * when present (the prompt is byte-identical to the no-brain path otherwise).
 * FAIL-OPEN on brain by construction — an empty context simply means no anchor.
 */
final class ProviderObraNodeDelivery implements ObraNodeDelivery
{
    public function __construct(
        private readonly AtlasLiveCodeDeliveryService $delivery,
    ) {}

    public function label(): string
    {
        return 'provider_live_code_delivery';
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function deliver(string $request, array $context = []): array
    {
        $request = trim($request);
        if ($request === '') {
            return ['certified' => false, 'files' => [], 'reason' => 'empty_request'];
        }

        $options = [
            // Multi-file so a step that touches several files works (parsed via markers).
            'multi_file' => true,
        ];
        $providerKey = $this->providerKey($context);
        $providerConfig = (array) config('atlas.ai.providers.'.$providerKey, []);
        $options['provider'] = $providerKey;
        $options['model'] = $this->modelFor($providerKey, $context, $providerConfig);
        $options['timeout_seconds'] = $this->timeoutSeconds($context, $providerConfig);
        if (isset($context['target_area']) && is_string($context['target_area']) && $context['target_area'] !== '') {
            $options['target_file'] = (string) $context['target_area'];
        }
        // BRAIN-DRIVEN, FAIL-OPEN: only prepend a non-empty, pre-formatted context.
        if (isset($context['brain_context']) && is_string($context['brain_context']) && trim((string) $context['brain_context']) !== '') {
            $options['brain_context'] = (string) $context['brain_context'];
        }

        $result = $this->delivery->deliver($request, $options);

        if (($result['status'] ?? '') !== AtlasLiveCodeDeliveryService::STATUS_CERTIFIED) {
            $reason = $this->blockedReason($result);
            $diagnostic = $this->blockedDiagnostic($result, $reason);

            return [
                'certified' => false,
                'files' => [],
                'provider' => is_string($result['provider'] ?? null) ? $result['provider'] : null,
                'model' => is_string($result['model'] ?? null) ? $result['model'] : null,
                'reason' => $reason,
                'delivery_status' => is_string($result['status'] ?? null) ? $result['status'] : null,
                'target_file' => is_string($result['target_file'] ?? null) ? $result['target_file'] : null,
                'file_count' => is_numeric($result['file_count'] ?? null) ? (int) $result['file_count'] : null,
                'latency_ms' => is_numeric($result['latency_ms'] ?? null) ? (int) $result['latency_ms'] : null,
                'syntax_check' => $diagnostic['syntax_check'] ?? null,
                'run_check' => $diagnostic['run_check'] ?? null,
                'delivery_diagnostic' => $diagnostic,
            ];
        }

        // Read the certified sandbox files into {path, content} (git computes
        // modify-vs-new when applied onto the obra worktree) — the exact shape the
        // orchestrator's filesFromDelivery() produces.
        $files = [];
        foreach ((array) ($result['files'] ?? []) as $f) {
            $path = (string) ($f['path'] ?? '');
            $sandboxPath = (string) ($f['sandbox_path'] ?? '');
            if ($path === '' || $sandboxPath === '' || ! is_file($sandboxPath)) {
                continue;
            }
            $files[] = ['path' => $path, 'content' => (string) @file_get_contents($sandboxPath)];
        }
        if ($files === []) {
            return ['certified' => false, 'files' => [], 'reason' => 'no_files_from_delivery'];
        }

        // The gate credential — the SAME sha256-over-the-proven-facts the orchestrator
        // hands the materializer (certification IS the credential; no self-certify).
        $paths = array_map(static fn (array $f): string => $f['path'], $files);
        $gateReceipt = hash('sha256', (string) json_encode([
            'certified' => true,
            'request' => $request,
            'files' => $paths,
            'provider' => $result['provider'] ?? null,
            'syntax' => $result['syntax_check'] ?? null,
        ], JSON_UNESCAPED_SLASHES));

        return [
            'certified' => true,
            'files' => $files,
            'gate_receipt' => $gateReceipt,
            'provider' => is_string($result['provider'] ?? null) ? $result['provider'] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function providerKey(array $context): string
    {
        if (isset($context['provider']) && is_string($context['provider']) && trim($context['provider']) !== '') {
            return trim($context['provider']);
        }

        $configured = config('atlas.ai.default_provider', 'hermes_cli');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : 'hermes_cli';
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $providerConfig
     */
    private function modelFor(string $providerKey, array $context, array $providerConfig): string
    {
        if (isset($context['model']) && is_string($context['model']) && trim($context['model']) !== '') {
            return trim($context['model']);
        }

        $configured = $providerConfig['model'] ?? $providerConfig['model_identity'] ?? null;
        if (is_string($configured) && trim($configured) !== '' && ! str_ends_with(trim($configured), '_default')) {
            return trim($configured);
        }

        return $providerKey === 'hermes_cli' ? 'gpt-5.5' : '';
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $providerConfig
     */
    private function timeoutSeconds(array $context, array $providerConfig): int
    {
        $raw = $context['timeout_seconds'] ?? $providerConfig['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 600);

        return max(60, min(3600, (int) $raw));
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function blockedReason(array $result): string
    {
        foreach (['reason', 'blocked_reason', 'error_code'] as $key) {
            $value = $result[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return substr(trim($value), 0, 180);
            }
        }

        return 'delivery_not_certified';
    }

    /**
     * Bounded, provider-safe autopsy data for failed deliveries. Deliberately excludes
     * raw prompt, provider output, code preview, sandbox file contents and file bodies.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function blockedDiagnostic(array $result, string $reason): array
    {
        $diagnostic = [
            'schema_version' => 'atlas.obra.delivery_diagnostic.v1',
            'reason' => $reason,
            'delivery_status' => is_string($result['status'] ?? null) ? $result['status'] : null,
            'blocked_reason' => is_string($result['blocked_reason'] ?? null) ? substr(trim($result['blocked_reason']), 0, 180) : null,
            'provider' => is_string($result['provider'] ?? null) ? $result['provider'] : null,
            'model' => is_string($result['model'] ?? null) ? $result['model'] : null,
            'target_file' => is_string($result['target_file'] ?? null) ? $result['target_file'] : null,
            'file_count' => is_numeric($result['file_count'] ?? null) ? (int) $result['file_count'] : null,
            'latency_ms' => is_numeric($result['latency_ms'] ?? null) ? (int) $result['latency_ms'] : null,
            'syntax_check' => $this->boundedCheck((array) ($result['syntax_check'] ?? [])),
            'run_check' => $this->boundedCheck((array) ($result['run_check'] ?? [])),
        ];

        return array_filter($diagnostic, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string,mixed>  $check
     * @return array<string,mixed>
     */
    private function boundedCheck(array $check): array
    {
        if ($check === []) {
            return [];
        }

        $bounded = [];
        foreach (['ok', 'tool', 'reason', 'exit_code'] as $key) {
            if (array_key_exists($key, $check)) {
                $bounded[$key] = is_string($check[$key]) ? substr(trim($check[$key]), 0, 180) : $check[$key];
            }
        }
        if (isset($check['output']) && is_string($check['output'])) {
            $bounded['output_excerpt'] = substr(trim($check['output']), 0, 500);
        }

        return $bounded;
    }
}
