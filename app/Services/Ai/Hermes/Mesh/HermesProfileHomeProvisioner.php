<?php

namespace App\Services\Ai\Hermes\Mesh;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Materializes a per-PROFILE managed HERMES_HOME so each mesh ROLE runs isolated.
 *
 * The Executive Mesh resolves a profile per role (toolsets / provider / model /
 * skills). Hermes honors `HERMES_HOME=<dir>` (it IGNORES `HERMES_CONFIG`), so to
 * actually specialize a role we give it its OWN managed home directory under
 * `storage/app/hermes/profiles/<role(+trace)>/` containing a `config.yaml` that
 * pins the role's `model.provider`/`model` and a `tools`/`toolsets` hint — and we
 * SYMLINK the operator's real assets (skills/, skill-bundles/, mcp-tokens/, .env)
 * into it so credentials and skills keep working. This closes the "profiles via
 * flags only" gap: instead of leaning on CLI flags, each role boots a real,
 * isolated home.
 *
 * This is a pure, side-effect-bounded provisioner: it NEVER calls a model and
 * NEVER decides policy. It mirrors {@see \App\Services\Ai\Hermes\HermesManagedMcpConfigProvisioner}:
 * managed dir is 0700, config.yaml is 0600, operator files are only ever
 * symlinked (never copied or mutated), and `forget()` removes the managed dir
 * for full reversibility. It returns a directory path (no receipt — same as the
 * MCP provisioner), or `null` when the profile has nothing to specialize.
 */
class HermesProfileHomeProvisioner
{
    public function __construct(
        private readonly Filesystem $files,
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

        $home = $this->path($role, $traceId);
        $this->files->ensureDirectoryExists($home, 0700);
        $this->linkOperatorAssets($home);

        $body = $this->encode($this->config($provider, $model, $toolsets, $skills));
        $configPath = $home.'/config.yaml';
        $this->files->put($configPath, $body);
        @chmod($configPath, 0600);

        return $home;
    }

    /**
     * Managed HERMES_HOME directory for a role (NOT a file, NOT the operator's ~/.hermes).
     */
    public function path(string $role, ?string $traceId = null): string
    {
        return storage_path('app/hermes/profiles/'.$this->slug($role, $traceId));
    }

    public function forget(string $role, ?string $traceId = null): void
    {
        $home = $this->path($role, $traceId);
        if ($this->files->isDirectory($home)) {
            // Removes the managed dir + the symlink entries (not their targets).
            $this->files->deleteDirectory($home);
        }
    }

    /**
     * Builds the managed config body. Only includes `model` when a provider is
     * pinned, and always records the resolved tool/toolset hint so the role's
     * home documents what it was specialized for.
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
     * Symlinks the operator's real Hermes assets into the managed home so skills,
     * credentials and OAuth tokens keep working while Atlas controls the profile.
     * Never copies or mutates the operator's files; only adds links into storage.
     */
    private function linkOperatorAssets(string $home): void
    {
        $operatorHome = $this->operatorHome();
        if ($operatorHome === null || ! $this->files->isDirectory($operatorHome)) {
            return;
        }

        foreach (['skills', 'skill-bundles', 'mcp-tokens', '.env'] as $asset) {
            $target = $operatorHome.'/'.$asset;
            $link = $home.'/'.$asset;
            if (! file_exists($target) || file_exists($link) || is_link($link)) {
                continue;
            }
            @symlink($target, $link);
        }
    }

    private function operatorHome(): ?string
    {
        $env = getenv('HERMES_HOME');
        if (is_string($env) && trim($env) !== '') {
            return rtrim(trim($env), '/');
        }

        $base = getenv('HOME');

        return is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/').'/.hermes' : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        if (class_exists(Yaml::class)) {
            return Yaml::dump($payload, 6, 2);
        }

        // JSON is a valid YAML 1.2 subset — avoids a hard symfony/yaml dependency.
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Builds a filesystem-safe slug from the role (+ optional trace) that can
     * never traverse outside `storage/app/hermes/profiles/`.
     */
    private function slug(string $role, ?string $traceId): string
    {
        $trace = $this->string($traceId);
        $seed = trim($role).($trace !== null ? '-'.$trace : '');
        $seed = trim($seed) !== '' ? $seed : 'no_role';

        $slug = Str::slug($seed, '-');

        if ($slug === '') {
            return 'hermes-profile-'.substr(hash('sha256', $seed), 0, 24);
        }

        return Str::limit($slug, 120, '');
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
