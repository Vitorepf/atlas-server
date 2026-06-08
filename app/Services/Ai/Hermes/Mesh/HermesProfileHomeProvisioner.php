<?php

namespace App\Services\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\HermesLearningHomeLinker;
use App\Services\Ai\Hermes\ManagedHermesHome;
use Illuminate\Filesystem\Filesystem;

/**
 * Materializes a per-PROFILE managed HERMES_HOME so each mesh ROLE runs isolated.
 *
 * The Executive Mesh resolves a profile per role (toolsets / provider / model /
 * skills). This class only builds the role's config body; the managed-home
 * plumbing (0700 dir, learning-state symlinks, operator `memory:` carry-over,
 * config.yaml 0600, traversal-safe slug, forget) is shared with the MCP
 * provisioner via {@see ManagedHermesHome} — one home builder, no duplication.
 * It returns a directory path, or `null` when the profile has nothing to
 * specialize.
 */
class HermesProfileHomeProvisioner
{
    private const KIND = 'profiles';

    public function __construct(
        private readonly Filesystem $files,
        private readonly HermesLearningHomeLinker $learning = new HermesLearningHomeLinker(new Filesystem()),
    ) {}

    /**
     * Materialize a managed HERMES_HOME for one mesh profile.
     *
     * @param  array{toolsets?:string[],provider?:?string,model?:?string,skills?:string[]}  $profile
     * @return string|null  the managed HERMES_HOME directory, or null when nothing to specialize
     */
    public function provision(string $role, array $profile, ?string $traceId = null): ?string
    {
        $toolsets = $this->stringList($profile['toolsets'] ?? null);
        $skills = $this->stringList($profile['skills'] ?? null);
        $provider = $this->string($profile['provider'] ?? null);
        $model = $this->string($profile['model'] ?? null);

        // Nothing to specialize: no toolsets AND no provider => do not provision.
        if ($toolsets === [] && $provider === null) {
            return null;
        }

        return $this->home()->write(self::KIND, $this->seed($role, $traceId), $this->config($provider, $model, $toolsets, $skills));
    }

    /**
     * Managed HERMES_HOME directory for a role (NOT a file, NOT the operator's ~/.hermes).
     */
    public function path(string $role, ?string $traceId = null): string
    {
        return $this->home()->path(self::KIND, $this->seed($role, $traceId));
    }

    public function forget(string $role, ?string $traceId = null): void
    {
        $this->home()->forget(self::KIND, $this->seed($role, $traceId));
    }

    private function home(): ManagedHermesHome
    {
        return new ManagedHermesHome($this->files, $this->learning);
    }

    /**
     * Builds the managed config body. Only includes `model` when a provider is
     * pinned, and always records the resolved tool/toolset hint so the role's
     * home documents what it was specialized for. (ManagedHermesHome carries the
     * operator's `memory:` config in automatically.)
     *
     * @param  string[]  $toolsets
     * @param  string[]  $skills
     * @return array<string,mixed>
     */
    private function config(?string $provider, ?string $model, array $toolsets, array $skills): array
    {
        $config = [];

        if ($provider !== null) {
            $modelBlock = ['provider' => $provider];
            if ($model !== null) {
                $modelBlock['model'] = $model;
            }
            $config['model'] = $modelBlock;
        }

        $config['tools'] = ['toolsets' => $toolsets];
        $config['toolsets'] = $toolsets;
        $config['skills'] = $skills;

        return $config;
    }

    /**
     * Raw home seed (role + optional trace) — {@see ManagedHermesHome::safeSlug}
     * makes it filesystem-safe.
     */
    private function seed(string $role, ?string $traceId): string
    {
        $trace = $this->string($traceId);
        $seed = trim($role).($trace !== null ? '-'.$trace : '');

        return trim($seed) !== '' ? $seed : 'no_role';
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $s = $this->string($item);
            if ($s !== null) {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
