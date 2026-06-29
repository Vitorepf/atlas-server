<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

use Illuminate\Support\Facades\File;

/**
 * Production {@see AtlasLoopAmbitionFacultyStore} — a single-file JSON persistence of the ambition-faculty
 * target. `current()` reads the file (a missing/invalid file yields the canonical default target); `save()`
 * writes the target's canonical bytes. The drift CLI only ever calls `save()` on a gated apply.
 */
final class AtlasLoopFileAmbitionFacultyStore implements AtlasLoopAmbitionFacultyStore
{
    public function __construct(private readonly ?string $path = null) {}

    public function current(): AtlasLoopAmbitionFacultyTarget
    {
        $path = $this->path();
        if (! is_file($path)) {
            return AtlasLoopAmbitionFacultyTarget::fromArray([]);
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return AtlasLoopAmbitionFacultyTarget::fromArray(is_array($decoded) ? $decoded : []);
    }

    public function save(AtlasLoopAmbitionFacultyTarget $target): void
    {
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $target->canonicalBytes());
    }

    /** The resolved store path: explicit > config override > storage default. */
    public function path(): string
    {
        if ($this->path !== null && trim($this->path) !== '') {
            return $this->path;
        }

        return (string) config(
            'atlas.quaternity.ambition_faculty_path',
            storage_path('atlas/quaternity/ambition-faculty-target.json'),
        );
    }
}
