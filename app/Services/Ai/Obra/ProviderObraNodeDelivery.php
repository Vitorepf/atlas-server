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
        if (isset($context['provider']) && is_string($context['provider']) && $context['provider'] !== '') {
            $options['provider'] = (string) $context['provider'];
        }
        if (isset($context['target_area']) && is_string($context['target_area']) && $context['target_area'] !== '') {
            $options['target_file'] = (string) $context['target_area'];
        }
        // BRAIN-DRIVEN, FAIL-OPEN: only prepend a non-empty, pre-formatted context.
        if (isset($context['brain_context']) && is_string($context['brain_context']) && trim((string) $context['brain_context']) !== '') {
            $options['brain_context'] = (string) $context['brain_context'];
        }

        $result = $this->delivery->deliver($request, $options);

        if (($result['status'] ?? '') !== AtlasLiveCodeDeliveryService::STATUS_CERTIFIED) {
            return [
                'certified' => false,
                'files' => [],
                'provider' => is_string($result['provider'] ?? null) ? $result['provider'] : null,
                'reason' => (string) ($result['reason'] ?? 'delivery_not_certified'),
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
}
