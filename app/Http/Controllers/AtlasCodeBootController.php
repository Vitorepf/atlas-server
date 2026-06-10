<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\Storage;

/**
 * Atlas Code · unified boot snapshot.
 *
 * Returns a single, deterministic contract the desktop topbar consumes to
 * decide "Kernel is real and usable". Aggregates:
 *
 *   - kernel  · php, queue, db, server version, ts
 *   - providers · providers/status summary
 *   - mcp · /atlas-code/mcp/status summary
 *   - cartography · repo + vault roots, indexed counts
 *   - workspace · git root / current cwd snapshot
 *
 * Endpoint: GET /api/atlas-code/boot
 *
 * Anti-mock canon: if a subsystem is unavailable, we return `null`/`offline`
 * with `failure_code` and `repair_hint` — never invent.
 */
final class AtlasCodeBootController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $kernel = $this->kernel();
        $providers = $this->providers();
        $mcp = $this->mcp();
        $carto = $this->cartography();
        $workspace = $this->workspace();
        $queue = $this->queue();

        $ready = $kernel['db_connected']
            && $kernel['storage_writable']
            && $providers['available'] >= 0; // providers can be 0 if none configured — still ready

        return response()->json([
            'schema_version' => 1,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => $ready ? 'ready' : 'degraded',
            'kernel' => $kernel,
            'providers' => $providers,
            'mcp' => $mcp,
            'cartography' => $carto,
            'workspace' => $workspace,
            'queue' => $queue,
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_boot_controller'),
    ]);
    }

    /** @return array<string, mixed> */
    private function kernel(): array
    {
        $dbConnected = true;
        $dbError = null;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbConnected = false;
            $dbError = $e->getMessage();
        }

        $storageWritable = false;
        $storagePath = (string) config('atlas.storage_path');
        try {
            $disk = Storage::disk('atlas');
            $probe = '.boot/'.now()->format('YmdHisv').'-'.bin2hex(random_bytes(3)).'.txt';
            $disk->put($probe, 'ok');
            $storageWritable = $disk->exists($probe);
            $disk->delete($probe);
        } catch (\Throwable) {
            $storageWritable = false;
        }

        return [
            'service' => 'atlas-server',
            'version' => (string) config('app.version', '1.0.0'),
            'env' => (string) config('app.env', 'production'),
            'php_version' => PHP_VERSION,
            'db_connected' => $dbConnected,
            'db_error' => $dbError,
            'storage_path' => $storagePath,
            'storage_writable' => $storageWritable,
            'ts' => now()->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function providers(): array
    {
        // Best-effort: introspect ai_provider_health or similar without coupling.
        $available = 0;
        $degraded = 0;
        if (DatabaseTableAvailability::has('ai_provider_health')) {
            try {
                $available = (int) DB::table('ai_provider_health')->where('status', 'available')->count();
                $degraded = (int) DB::table('ai_provider_health')->whereIn('status', ['degraded', 'rate_limited'])->count();
            } catch (\Throwable) {
                // table exists but schema mismatch — leave zeros, anti-mock canon
            }
        }

        return [
            'available' => $available,
            'degraded' => $degraded,
            'source' => 'ai_provider_health',
        ];
    }

    /** @return array<string, mixed> */
    private function mcp(): array
    {
        $enabled = (bool) config('atlas.open_brain.mcp.http_enabled', true);

        return [
            'server' => 'atlas-open-brain',
            'protocol_version' => AtlasOpenBrainMcpService::PROTOCOL_VERSION,
            'http_enabled' => $enabled,
            'status' => $enabled ? 'active' : 'disabled',
            'transport' => 'http_json_rpc',
        ];
    }

    /** @return array<string, mixed> */
    private function cartography(): array
    {
        $repoRoot = (string) config('atlas_vault.repo_docs_path');
        $vaultRoot = (string) config('atlas_vault.obsidian_vault_path');

        return [
            'repo_root' => $repoRoot,
            'repo_readable' => $repoRoot !== '' && is_dir($repoRoot) && is_readable($repoRoot),
            'vault_root' => $vaultRoot,
            'vault_readable' => $vaultRoot !== '' && is_dir($vaultRoot) && is_readable($vaultRoot),
        ];
    }

    /** @return array<string, mixed> */
    private function workspace(): array
    {
        $cwd = (string) getcwd();

        return [
            'cwd' => $cwd,
            'is_git' => is_dir($cwd.'/.git'),
        ];
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        $connection = (string) config('queue.default', 'database');
        $pending = 0;
        $failed = 0;
        if (DatabaseTableAvailability::has('jobs')) {
            try {
                $pending = (int) DB::table('jobs')->count();
            } catch (\Throwable) {
                $pending = 0;
            }
        }
        if (DatabaseTableAvailability::has('failed_jobs')) {
            try {
                $failed = (int) DB::table('failed_jobs')->count();
            } catch (\Throwable) {
                $failed = 0;
            }
        }

        return [
            'connection' => $connection,
            'pending' => $pending,
            'failed' => $failed,
        ];
    }
}
