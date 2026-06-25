<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneContextFreshnessGate;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only Atlas-native CLI for inspecting project-lane admission and stewardship health. Three verbs:
 *   inspect — describe the action contract + required manifest fields (no I/O).
 *   admit   — evaluate a --manifest file via AtlasProjectLaneAdmissionPolicy and report admitted/blocked
 *             FACTS (no provider, shell, git, or human-approval side effects).
 *   health  — compose admission + context-freshness FACTS into a lane_health payload listing exact
 *             blockers; no scalar hype score.
 */
final class AtlasProjectLaneStewardshipCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:task:project-lanes {action : inspect|admit|health} {--manifest=} {--json}';

    /** @var string */
    protected $description = 'Read-only inspector for project-lane admission and stewardship health.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        $payload = match ($action) {
            'inspect' => $this->inspectAction(),
            'admit' => $this->admitAction(),
            'health' => $this->healthAction(),
            default => ['schema_version' => 'atlas.multiproject.lane_cli.error.v1', 'status' => 'unknown_action', 'action' => $action],
        };

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $ok = in_array((string) ($payload['status'] ?? 'ok'), ['ok'], true) || ! isset($payload['status']);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectAction(): array
    {
        return [
            'schema_version' => 'atlas.multiproject.lane_cli.inspect.v1',
            'verbs' => ['inspect', 'admit', 'health'],
            'admission_schema' => AtlasProjectLaneAdmissionPolicy::SCHEMA,
            'isolation' => AtlasProjectLaneAdmissionPolicy::ISOLATION,
            'required_manifest_fields' => AtlasProjectLaneAdmissionPolicy::REQUIRED_FIELDS,
            'freshness_schema' => AtlasProjectLaneContextFreshnessGate::SCHEMA,
            'freshness_required_evidence' => AtlasProjectLaneContextFreshnessGate::REQUIRED_EVIDENCE,
            'side_effects' => [
                'writes_storage' => false,
                'starts_workers' => false,
                'calls_external_providers' => false,
                'invokes_shell_or_git' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function admitAction(): array
    {
        $manifestPath = (string) ($this->option('manifest') ?? '');
        if ($manifestPath === '') {
            return ['schema_version' => 'atlas.multiproject.lane_cli.error.v1', 'status' => 'usage_error', 'reason' => '--manifest is required for admit'];
        }
        $manifest = $this->loadManifest($manifestPath);
        if (! is_array($manifest)) {
            return ['schema_version' => 'atlas.multiproject.lane_cli.error.v1', 'status' => 'manifest_unreadable', 'manifest_path' => $manifestPath];
        }

        $policy = $this->app()->make(AtlasProjectLaneAdmissionPolicy::class);

        return $policy->admit($manifest);
    }

    /**
     * @return array<string,mixed>
     */
    private function healthAction(): array
    {
        $manifestPath = (string) ($this->option('manifest') ?? '');
        if ($manifestPath === '') {
            return ['schema_version' => 'atlas.multiproject.lane_cli.error.v1', 'status' => 'usage_error', 'reason' => '--manifest is required for health'];
        }
        $manifest = $this->loadManifest($manifestPath);
        if (! is_array($manifest)) {
            return ['schema_version' => 'atlas.multiproject.lane_cli.error.v1', 'status' => 'manifest_unreadable', 'manifest_path' => $manifestPath];
        }

        $admission = $this->app()->make(AtlasProjectLaneAdmissionPolicy::class)->admit($manifest);
        $observations = is_array($manifest['context_observations'] ?? null) ? $manifest['context_observations'] : [];
        $freshness = $this->app()->make(AtlasProjectLaneContextFreshnessGate::class)->evaluate($manifest, $observations);

        $blockers = array_values(array_merge(
            (array) ($admission['blocking_reasons'] ?? []),
            (array) ($freshness['blockers'] ?? []),
        ));

        return [
            'schema_version' => 'atlas.multiproject.lane_health.v1',
            'project_id' => (string) ($manifest['project_id'] ?? ''),
            'admission' => $admission,
            'context_freshness' => $freshness,
            'lane_health' => [
                'conformant' => $admission['admitted'] === true && ($freshness['conformant'] ?? false) === true,
                'blockers' => $blockers,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadManifest(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        try {
            $raw = (string) file_get_contents($path);
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
