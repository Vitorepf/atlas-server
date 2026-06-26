<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Support\RecursivelyKsortsArrays;

/**
 * Exports a release dossier in JSON + Markdown so an operator can
 * read the certification proof in human form. Persistence is opt-in
 * and only allowed under the snapshot-store dossier prefix.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneReleaseDossierExporter
{
    use RecursivelyKsortsArrays;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_release_dossier_export.v1';

    public const MODE = 'read_only_agent_control_plane_release_dossier_export';

    public const ALLOWED_PREFIX = 'atlas/self-construction/agent-control-plane/dossiers';

    public const DEFAULT_DISK = 'local';

    public function __construct(
        private readonly AgentControlPlaneReleaseDossierService $dossier,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function export(array $options = []): array
    {
        $persist = (bool) ($options['persist'] ?? false);
        $label = isset($options['label']) && is_string($options['label']) ? trim($options['label']) : '';
        $skipSimulator = (bool) ($options['skip_simulator'] ?? true);

        $dossierPayload = $this->dossier->build(['skip_simulator' => $skipSimulator]);

        $markdown = $this->renderMarkdown($dossierPayload);
        $jsonPayload = json_encode($dossierPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $exportId = (string) Str::uuid();
        $generatedAt = CarbonImmutable::now()->toIso8601String();

        $persistedPath = null;
        $persistedJsonPath = null;
        $persistError = null;
        if ($persist) {
            try {
                $disk = Storage::disk(self::DEFAULT_DISK);
                $base = self::ALLOWED_PREFIX.'/'.($label !== '' ? Str::slug($label) : 'dossier').'_'.$exportId;
                $persistedPath = $base.'.md';
                $persistedJsonPath = $base.'.json';
                $disk->put($persistedPath, $markdown);
                $disk->put($persistedJsonPath, (string) $jsonPayload);
            } catch (\Throwable $e) {
                $persistError = $e->getMessage();
                $persistedPath = null;
                $persistedJsonPath = null;
            }
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'export_id' => $exportId,
            'generated_at' => $generatedAt,
            'status' => $persist && $persistError !== null ? 'persist_failed' : 'available',
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'completion_claim_allowed' => false,
            'runtime_execution_allowed' => false,
            'provider_call_allowed' => false,
            'self_programming_allowed' => false,
            'persist' => $persist,
            'persisted_md_path' => $persistedPath,
            'persisted_json_path' => $persistedJsonPath,
            'persist_error' => $persistError,
            'allowed_prefix' => self::ALLOWED_PREFIX,
            'json_payload' => $dossierPayload,
            'markdown' => $markdown,
            'markdown_byte_size' => strlen($markdown),
            'machine_summary' => (array) data_get($dossierPayload, 'machine_summary', []),
            'operator_summary' => (array) data_get($dossierPayload, 'operator_summary', []),
            'machine_summary_hash' => $this->stableHash((array) data_get($dossierPayload, 'machine_summary', [])),
            'operator_summary_hash' => $this->stableHash((array) data_get($dossierPayload, 'operator_summary', [])),
            'markdown_hash' => hash('sha256', $markdown),
            'release_dossier_hash' => (string) data_get($dossierPayload, 'release_dossier_hash'),
            'non_execution_guarantees' => [
                'exporter_does_not_start_codex',
                'exporter_does_not_call_codex_cli_or_app',
                'exporter_does_not_spawn_subprocess',
                'exporter_does_not_invoke_adapter',
                'exporter_does_not_execute_adapter',
                'exporter_does_not_call_provider',
                'exporter_does_not_dispatch_work',
                'exporter_does_not_spend_tokens',
                'exporter_does_not_enable_self_programming',
                'exporter_does_not_write_ledger',
                'exporter_does_not_mutate_pointer',
                'exporter_does_not_promote_completion_claim',
                'exporter_persists_only_in_allowed_prefix_when_persist_true',
            ],
            'human_summary' => $persist
                ? ($persistError === null ? 'Release dossier exported and persisted under the allowed prefix.' : 'Release dossier exported but persistence failed; see persist_error.')
                : 'Release dossier exported in memory only; pass persist=true to write under the allowed prefix.',
        ];

        $payload['export_hash'] = $this->stableHash($this->normalizeForExportHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $dossier
     */
    private function renderMarkdown(array $dossier): string
    {
        $status = (string) data_get($dossier, 'status');
        $risk = (string) data_get($dossier, 'risk_classification');
        $pointer = (string) data_get($dossier, 'current_pointer');
        $nextSlice = (string) data_get($dossier, 'next_required_slice');
        $nextBatch = (string) data_get($dossier, 'next_safe_macro_batch');
        $runtimeSafe = (bool) data_get($dossier, 'runtime_safety_all_false');
        $chainStatus = (string) data_get($dossier, 'chain_integrity_status');
        $gateStatus = (string) data_get($dossier, 'promotion_gate_status');
        $detectionRate = (float) data_get($dossier, 'scenario_detection_rate');
        $blockers = (array) data_get($dossier, 'blockers', []);
        $warnings = (array) data_get($dossier, 'warnings', []);
        $nonGoals = (array) data_get($dossier, 'non_goals', []);
        $instruction = (string) data_get($dossier, 'operator_summary.instruction');

        $md = "# Atlas Agent Control Plane — Release Dossier\n\n";
        $md .= "- **Overall status:** {$status}\n";
        $md .= '- **Risk classification:** '.$risk."\n";
        $md .= '- **Current pointer:** `'.$pointer."`\n";
        $md .= '- **Next required slice:** `'.$nextSlice."`\n";
        $md .= '- **Next safe macro batch:** `'.$nextBatch."`\n";
        $md .= '- **Runtime safety all false:** '.($runtimeSafe ? 'yes' : 'NO')."\n";
        $md .= "\n## Chain integrity\n\n";
        $md .= "- Status: {$chainStatus}\n";
        $md .= '- Chain integrity hash: `'.((string) data_get($dossier, 'chain_integrity_hash'))."`\n";
        $md .= "\n## Replay\n\n";
        $md .= '- Replay hash: `'.((string) data_get($dossier, 'replay_hash'))."`\n";
        $md .= '- Deterministic replay hash: `'.((string) data_get($dossier, 'deterministic_replay_hash'))."`\n";
        $md .= '- Proof bundle hash: `'.((string) data_get($dossier, 'proof_bundle_hash'))."`\n";
        $md .= "\n## Snapshot / Diff / Promotion gate\n\n";
        $md .= '- Diff hash: `'.((string) data_get($dossier, 'diff_hash'))."`\n";
        $md .= '- Promotion gate hash: `'.((string) data_get($dossier, 'gate_hash'))."`\n";
        $md .= "- Promotion gate status: {$gateStatus}\n";
        $md .= "\n## Scenario simulator\n\n";
        $md .= sprintf("- Detection rate: %.4f\n", $detectionRate);
        $md .= '- Scenario matrix hash: `'.((string) data_get($dossier, 'scenario_matrix_hash'))."`\n";
        $md .= "\n## Mutation guard\n\n";
        $md .= '- Mutation guard hash: `'.((string) data_get($dossier, 'mutation_guard_hash'))."`\n";
        $md .= '- Mutation guard passed: '.((bool) data_get($dossier, 'mutation_guard_passed') ? 'yes' : 'NO')."\n";
        $md .= "\n## Blockers\n\n";
        if ($blockers === []) {
            $md .= "- (none)\n";
        } else {
            foreach ($blockers as $b) {
                $md .= '- '.$b."\n";
            }
        }
        $md .= "\n## Warnings\n\n";
        if ($warnings === []) {
            $md .= "- (none)\n";
        } else {
            foreach ($warnings as $w) {
                $md .= '- '.$w."\n";
            }
        }
        $md .= "\n## Non-goals\n\n";
        foreach ($nonGoals as $g) {
            $md .= '- '.$g."\n";
        }
        $md .= "\n## Operator checklist\n\n";
        $md .= "- [ ] Review chain integrity status\n";
        $md .= "- [ ] Confirm runtime safety all false\n";
        $md .= "- [ ] Confirm pointer did not advance\n";
        $md .= "- [ ] Verify scenario detection rate is 1.0\n";
        $md .= "- [ ] Verify promotion gate is not blocked\n";
        $md .= "- [ ] Confirm mutation guard passed\n";
        $md .= "- [ ] Read deterministic replay hash and snapshot history\n";
        $md .= "- [ ] Persist dossier evidence (optional, allowed prefix only)\n";
        $md .= "\n_Operator instruction: ".$instruction."_\n";

        return $md;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForExportHash(array $payload): array
    {
        $clone = $payload;
        // The markdown / markdown_hash / machine_summary_hash / operator_summary_hash
        // and json_payload all embed dossier-level volatile fields (`dossier_id`,
        // `generated_at`, `replay_hash`, `gate_hash`). The export_hash therefore
        // hashes only the structural envelope (schema_version, mode, persist
        // policy, allowed_prefix, options); for content fingerprint use
        // `markdown_hash` directly.
        unset(
            $clone['export_id'],
            $clone['generated_at'],
            $clone['export_hash'],
            $clone['json_payload'],
            $clone['markdown'],
            $clone['markdown_byte_size'],
            $clone['markdown_hash'],
            $clone['machine_summary'],
            $clone['operator_summary'],
            $clone['machine_summary_hash'],
            $clone['operator_summary_hash'],
            $clone['release_dossier_hash'],
            $clone['persisted_md_path'],
            $clone['persisted_json_path'],
        );

        return $this->recursivelyKsort($clone);
    }


    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
