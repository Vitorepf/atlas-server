<?php

namespace App\Services\Ai\Hermes\Mesh;

use Symfony\Component\Process\Process;

/**
 * Operational launcher for Executive Mesh child workers: turns a planned child
 * (role/profile/worktree/checkpoints) + its raw objective into a STARTED
 * `hermes` process, isolated in its own git worktree, with the profile's
 * toolsets/skills/provider applied.
 *
 * Verified against Hermes v0.15.1: top-level `-z/--oneshot PROMPT` runs a single
 * headless prompt and prints ONLY the final text (no banner/spinner) — ideal for
 * a fleet child; `-t/-s/--provider/--worktree` apply to it. The raw objective is
 * held ONLY here, transiently, and is passed as a process arg — it never reaches
 * any sealed Atlas receipt (those carry the leaf-computed objective_hash only).
 *
 * This class is the production seam for HermesExecutiveMeshService::dispatch();
 * the service stays fully unit-testable via a fake worker. Bounded concurrency,
 * fail-closed gating, and reconciliation are the service's responsibility.
 */
class HermesMeshProcessWorkerFactory
{
    public function __construct(
        private readonly HermesProfileHomeProvisioner $profileHomes,
    ) {}

    /**
     * Build the worker closure the mesh service dispatches with. It maps a
     * planned child to its raw objective (held only in $objectivesByIndex,
     * supplied by the operator surface) and starts a real process.
     *
     * @param  array<int,string>  $objectivesByIndex  child index => raw objective text
     * @return callable(array<string,mixed>):MeshWorkerHandle
     */
    public function workerFor(array $objectivesByIndex, ?string $workdir = null): callable
    {
        return function (array $child) use ($objectivesByIndex, $workdir): MeshWorkerHandle {
            $index = (int) ($child['index'] ?? 0);
            $objective = $objectivesByIndex[$index] ?? '';

            return $this->start($child, (string) $objective, $workdir);
        };
    }

    /**
     * Build (without launching) the exact argv this factory WOULD run for a
     * child — for dry-run previews. Pure: no process, no provisioning.
     *
     * @param  array<string,mixed>  $child
     * @return array<int,string>
     */
    public function previewArgs(array $child, string $objective): array
    {
        $binary = (string) config('atlas.ai.providers.hermes_cli.binary', 'hermes');

        return array_merge([$binary], $this->buildArgs($child, $objective));
    }

    /**
     * @param  array<string,mixed>  $child
     */
    public function start(array $child, string $objective, ?string $workdir = null): MeshWorkerHandle
    {
        $binary = (string) config('atlas.ai.providers.hermes_cli.binary', 'hermes');
        $command = array_merge([$binary], $this->buildArgs($child, $objective));

        // Per-child specialization is carried by the CLI flags (-t/-s/--provider/-m)
        // and filesystem isolation by --worktree, so by DEFAULT the child inherits
        // the operator's real ~/.hermes (full model/provider/auth config). A managed
        // per-profile HERMES_HOME is OPT-IN (mesh.isolate_profile_home): it isolates
        // config but, since it does not clone the operator's model config, must only
        // be enabled alongside profiles that set their own provider/model.
        $profileHome = config('atlas.ai.providers.hermes_cli.mesh.isolate_profile_home', false) === true
            ? $this->profileHomes->provision(
                is_string($child['role'] ?? null) ? (string) $child['role'] : '',
                is_array($child['profile'] ?? null) ? $child['profile'] : [],
                is_string($child['trace_id'] ?? null) ? $child['trace_id'] : null,
            )
            : null;

        $process = new Process(
            $command,
            $workdir,
            $this->env($profileHome),
            null,
            $this->timeoutSeconds(),
        );
        $process->start();

        return new HermesMeshProcessHandle($process, (int) ($child['index'] ?? 0));
    }

    /**
     * @param  array<string,mixed>  $child
     * @return array<int,string>
     */
    private function buildArgs(array $child, string $objective): array
    {
        $args = [];

        if (($child['assigned_worktree'] ?? false) === true) {
            $args[] = '--worktree';
        }

        $profile = is_array($child['profile'] ?? null) ? $child['profile'] : [];

        $toolsets = $this->csv($profile['toolsets'] ?? ($child['requested_toolsets'] ?? []));
        if ($toolsets !== '') {
            $args[] = '-t';
            $args[] = $toolsets;
        }

        $skills = $this->csv($profile['skills'] ?? []);
        if ($skills !== '') {
            $args[] = '-s';
            $args[] = $skills;
        }

        $provider = $profile['provider'] ?? null;
        if (is_string($provider) && trim($provider) !== '') {
            $args[] = '--provider';
            $args[] = trim($provider);
        }

        $model = $profile['model'] ?? null;
        if (is_string($model) && trim($model) !== '') {
            $args[] = '-m';
            $args[] = trim($model);
        }

        // Oneshot headless mode: prints ONLY the final response text.
        $args[] = '-z';
        $args[] = $objective;

        return $args;
    }

    /**
     * @return array<string,string>
     */
    private function env(?string $profileHome = null): array
    {
        $env = [];
        $home = $profileHome ?? config('atlas.ai.providers.hermes_cli.hermes_home');
        if (is_string($home) && trim($home) !== '') {
            $env['HERMES_HOME'] = trim($home);
        }

        return $env;
    }

    private function timeoutSeconds(): float
    {
        $value = config('atlas.ai.providers.hermes_cli.delegation_child_timeout_seconds_max', 600);

        return is_numeric($value) ? max(1.0, (float) $value) : 600.0;
    }

    /**
     * @param  mixed  $value
     */
    private function csv($value): string
    {
        if (! is_array($value)) {
            return '';
        }

        $clean = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = trim($item);
            }
        }

        return implode(',', array_values(array_unique($clean)));
    }
}
