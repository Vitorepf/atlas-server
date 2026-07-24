<?php

namespace App\Services\Ai\Hermes;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * THE single builder for an Atlas-managed `HERMES_HOME` directory.
 *
 * Hermes honors `HERMES_HOME=<dir>` (it IGNORES `HERMES_CONFIG`). Several Atlas
 * transports need a managed home: the MCP config provisioner, the per-profile
 * mesh provisioner, and (next) the ACP runtime. Before this class each one
 * re-implemented the identical skeleton — byte-for-byte `encode()`, the
 * 0700-dir / linkLearningState / memory-merge / 0600-config.yaml / forget
 * lifecycle, and the dir-traversal-safe slug. That duplication was a review
 * hazard; this collapses it into ONE place.
 *
 * Invariants preserved from the originals: managed dir is 0700, config.yaml is
 * 0600, raw secrets live ONLY in config.yaml (never in a sealed receipt), the
 * operator's real `~/.hermes` is NEVER mutated (only symlinked into via
 * {@see HermesLearningHomeLinker}), and the operator's `memory:` config is
 * carried so Hermes self-learning stays continuous across the relocation.
 */
class ManagedHermesHome
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly HermesLearningHomeLinker $learning = new HermesLearningHomeLinker(new Filesystem),
    ) {}

    /**
     * Managed HERMES_HOME directory for a kind ('home', 'profiles', 'acp', ...)
     * + a caller-provided seed. Never the operator's ~/.hermes; never traversable
     * outside storage/app/hermes/.
     */
    public function path(string $kind, string $slugSeed): string
    {
        return storage_path('app/hermes/'.$this->safeSegment($kind).'/'.$this->safeSlug($slugSeed));
    }

    /**
     * Materialize the managed home: link the operator's learning state (state.db,
     * skills, .env, ...), carry the operator's `memory:` config so memory stays
     * enabled, and write the caller's config body to config.yaml. An explicit
     * `memory` key in $configBody wins over the inherited operator memory config.
     *
     * @param  array<string,mixed>  $configBody  e.g. ['mcp_servers'=>[...]] or a profile config
     * @return string the managed HERMES_HOME directory
     */
    public function write(string $kind, string $slugSeed, array $configBody): string
    {
        $home = $this->path($kind, $slugSeed);
        $this->files->ensureDirectoryExists($home, 0700);
        $this->learning->linkLearningState($home);

        if (! array_key_exists('memory', $configBody)) {
            $memory = $this->learning->memoryConfig();
            if ($memory !== []) {
                $configBody['memory'] = $memory;
            }
        }

        $configPath = $home.'/config.yaml';
        $this->files->put($configPath, $this->encode($configBody));
        @chmod($configPath, 0600);

        return $home;
    }

    /**
     * Merge a config patch INTO the SAME managed home (same kind+seed), preserving
     * whatever an earlier {@see write()} already put there (e.g. the MCP
     * `mcp_servers` block). Top-level keys in $patch win. This is the single seam
     * a second governed transport (delegation caps) uses to extend one home rather
     * than materialize a second HERMES_HOME for the same run.
     *
     * @param  array<string,mixed>  $patch
     * @return string the managed HERMES_HOME directory
     */
    public function merge(string $kind, string $slugSeed, array $patch): string
    {
        $existing = $this->read($kind, $slugSeed);

        return $this->write($kind, $slugSeed, array_merge($existing, $patch));
    }

    /**
     * Decode the config.yaml of a managed home for the given kind+seed; empty
     * array when the home/config does not exist yet.
     *
     * @return array<string,mixed>
     */
    public function read(string $kind, string $slugSeed): array
    {
        $configPath = $this->path($kind, $slugSeed).'/config.yaml';
        if (! $this->files->exists($configPath)) {
            return [];
        }

        return $this->decode((string) $this->files->get($configPath));
    }

    public function forget(string $kind, string $slugSeed): void
    {
        $home = $this->path($kind, $slugSeed);
        if ($this->files->isDirectory($home)) {
            // Removes the managed dir + the symlink entries (not their targets).
            $this->files->deleteDirectory($home);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function encode(array $payload): string
    {
        if (class_exists(Yaml::class)) {
            return Yaml::dump($payload, 6, 2);
        }

        // JSON is a valid YAML 1.2 subset — avoids a hard symfony/yaml dependency.
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Inverse of {@see encode()} — parse a managed config.yaml back to an array.
     * Tolerant by design: an unreadable/empty body yields [] so a merge never
     * destroys a working home by throwing.
     *
     * @return array<string,mixed>
     */
    public function decode(string $contents): array
    {
        $contents = trim($contents);
        if ($contents === '') {
            return [];
        }

        if (class_exists(Yaml::class)) {
            try {
                $parsed = Yaml::parse($contents);

                return is_array($parsed) ? $parsed : [];
            } catch (\Throwable) {
                // Fall through to the JSON-subset reader below.
            }
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Filesystem-safe slug that can never traverse outside storage/app/hermes/.
     */
    public function safeSlug(string $seed): string
    {
        $seed = trim($seed) !== '' ? trim($seed) : 'no_seed';
        $slug = Str::slug($seed, '-');

        if ($slug === '') {
            return 'hermes-home-'.substr(hash('sha256', $seed), 0, 24);
        }

        return Str::limit($slug, 120, '');
    }

    private function safeSegment(string $kind): string
    {
        $segment = preg_replace('/[^a-z0-9_-]/i', '', trim($kind));

        return is_string($segment) && $segment !== '' ? $segment : 'home';
    }
}
