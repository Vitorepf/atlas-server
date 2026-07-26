<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support\BaselineSignature;

/**
 * Loads the pinned {@see BaselineSignature} from the committed artifact on
 * disk. The path is determined once at the loader's construction (from a
 * concrete path, or the well-known mission-owned default under
 * `storage/atlas-dev-elevation/baseline-signature.json`) and the loaded
 * snapshot is cached: {@see load()} returns the same instance on every call
 * so a mid-run mutation of the on-disk file cannot influence a gated run that
 * already captured the pinned copy.
 */
final class BaselineSignatureLoader
{
    public const DEFAULT_ARTIFACT_PATH = 'storage/atlas-dev-elevation/baseline-signature.json';

    private ?BaselineSignature $cached = null;

    public function __construct(
        private readonly string $artifactPath,
    ) {}

    public static function default(): self
    {
        return new self(self::defaultArtifactPath());
    }

    public static function defaultArtifactPath(): string
    {
        $repoRoot = self::repoRoot();
        if ($repoRoot !== '') {
            return $repoRoot.'/'.self::DEFAULT_ARTIFACT_PATH;
        }

        return self::DEFAULT_ARTIFACT_PATH;
    }

    /**
     * Returns the cached snapshot, loading from disk on the first call. Any
     * subsequent call (even after the on-disk file has been rewritten) returns
     * the originally-loaded snapshot, so a gated run is anchored to a pinned
     * copy for its entire duration.
     */
    public function load(): BaselineSignature
    {
        if ($this->cached instanceof BaselineSignature) {
            return $this->cached;
        }

        $raw = $this->readArtifact();
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new \InvalidArgumentException(
                'BaselineSignatureLoader: artifact ['.$this->artifactPath.'] did not decode to a JSON object.'
            );
        }

        return $this->cached = BaselineSignature::fromPayload($payload);
    }

    /**
     * Returns the SHA-256 of the on-disk artifact's raw bytes. Captured at
     * load time so callers (the delta-gate, validators) can prove the gate is
     * anchored to the pinned copy.
     */
    public function pinnedArtifactByteHash(): string
    {
        return hash('sha256', $this->readArtifact());
    }

    private function readArtifact(): string
    {
        if (! is_file($this->artifactPath)) {
            throw new \InvalidArgumentException(
                'BaselineSignatureLoader: artifact not found at ['.$this->artifactPath.']'
            );
        }

        $raw = file_get_contents($this->artifactPath);
        if ($raw === false || $raw === '') {
            throw new \InvalidArgumentException(
                'BaselineSignatureLoader: artifact ['.$this->artifactPath.'] is empty or unreadable.'
            );
        }

        return $raw;
    }

    private static function repoRoot(): string
    {
        // Prefer Laravel's base_path() when the framework is bootstrapped so
        // path resolution is identical for HTTP/CLI/test entrypoints. Fall
        // back to walking up from this file for unit tests that do not
        // bootstrap the framework.
        if (function_exists('base_path')) {
            try {
                $base = base_path();
                if ($base !== '') {
                    return $base;
                }
            } catch (\Throwable) {
                // fall through to the static walk-up below
            }
        }

        $here = __DIR__;
        $segments = explode('/app/Services/Ai/Programming/AtlasDev/Support/BaselineSignature', $here, 2);
        if (count($segments) === 2 && $segments[0] !== '') {
            return $segments[0];
        }

        return '';
    }
}
