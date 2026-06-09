<?php

declare(strict_types=1);

namespace App\Services\Ai\RealExecution;

/**
 * Mission e2e — "Atlas delivers from natural language", end to end.
 *
 * Chains the pieces built across this program into one governed flow:
 *
 *   request (natural language)
 *     → AtlasLiveCodeDeliveryService  (provider generates code WITH the wired
 *        code-graph auto-context; isolated sandbox; php -l / self-test; CERTIFIED)
 *     → diff (built from the certified sandbox files)
 *     → GovernedBranchMaterializationService  (real branch; NEVER main; gated)
 *     → ready-to-merge artifact (branch + diff + review commands)
 *
 * The operator merges (their sovereignty). This orchestrator stops at the branch:
 * it never merges, never pushes, never touches main. Delivery's own certification
 * is the gate credential handed to the materializer (no self-certification beyond
 * what php -l / the verifier already proved).
 */
class MissionDeliveryOrchestrator
{
    public const SCHEMA = 'atlas.ai.mission_delivery.v1';

    public function __construct(
        private readonly AtlasLiveCodeDeliveryService $delivery,
        private readonly GovernedBranchMaterializationService $materializer,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function deliver(string $request, array $options = []): array
    {
        $request = trim($request);
        if ($request === '') {
            return $this->blocked('request_required', 'request');
        }

        // STAGE 1 — request → certified code (real provider, isolated sandbox, php -l).
        $delivery = $this->delivery->deliver($request, $options);
        if (($delivery['status'] ?? '') !== AtlasLiveCodeDeliveryService::STATUS_CERTIFIED) {
            return $this->blocked((string) ($delivery['reason'] ?? 'delivery_not_certified'), 'delivery', ['delivery' => $delivery]);
        }

        // STAGE 2 — certified sandbox files → {path, content} (git computes modify-vs-new).
        $contentFiles = $this->filesFromDelivery($delivery);
        if ($contentFiles === []) {
            return $this->blocked('no_files_from_delivery', 'files', ['delivery' => $delivery]);
        }

        // STAGE 3 — the certification IS the gate credential (sha256 over the proven facts).
        $files = array_values(array_filter(array_map(
            static fn ($f): string => (string) ($f['path'] ?? ''),
            (array) ($delivery['files'] ?? []),
        )));
        $id = (string) ($options['id'] ?? ('mission-'.substr(hash('sha256', $request), 0, 10)));
        $receipt = hash('sha256', (string) json_encode([
            'certified' => true, 'request' => $request, 'files' => $files,
            'provider' => $delivery['provider'] ?? null, 'syntax' => $delivery['syntax_check'] ?? null,
        ], JSON_UNESCAPED_SLASHES));

        // STAGE 4 — materialize to a real branch (NEVER main; gated; reversible).
        $materialization = $this->materializer->materialize([
            'id' => $id,
            'files' => $contentFiles,
            'repo_dir' => (string) ($options['repo_dir'] ?? base_path()),
            'certified' => true,
            'gate_receipt' => $receipt,
            'measure_cmd' => isset($options['measure_cmd']) ? (string) $options['measure_cmd'] : null,
        ]);

        return [
            'schema_version' => self::SCHEMA,
            'delivered' => (bool) ($materialization['materialized'] ?? false),
            'stage' => 'complete',
            'request' => $request,
            'delivery' => [
                'certified' => true,
                'provider' => $delivery['provider'] ?? null,
                'files' => $files,
            ],
            'materialization' => $materialization,
            'branch' => $materialization['branch'] ?? null,
            'main_untouched' => (bool) ($materialization['main_untouched'] ?? true),
            'never_merged' => true,
            'review_commands' => $materialization['review_commands'] ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @return array<int,array{path:string,content:string}>
     */
    private function filesFromDelivery(array $delivery): array
    {
        $files = [];
        foreach ((array) ($delivery['files'] ?? []) as $f) {
            $path = (string) ($f['path'] ?? '');
            $sandboxPath = (string) ($f['sandbox_path'] ?? '');
            if ($path === '' || $sandboxPath === '' || ! is_file($sandboxPath)) {
                continue;
            }
            $files[] = ['path' => $path, 'content' => (string) @file_get_contents($sandboxPath)];
        }

        return $files;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $reason, string $stage, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA,
            'delivered' => false,
            'stage' => $stage,
            'reason' => $reason,
            'branch' => null,
            'main_untouched' => true,
            'never_merged' => true,
        ], $extra);
    }
}
