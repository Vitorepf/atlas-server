<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Simulation;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializerSupport2;
use LogicException;
use Symfony\Component\Process\Process;

/**
 * Materializes an isolated shadow workspace for a proposed Loop change so it can be evaluated
 * WITHOUT touching the live source tree. FACT-only: records (commit sha, dirty fingerprints,
 * sandbox path, createdAt, deterministic contentChecksum). Never scores, never judges, never
 * calls a provider.
 *
 * Fail-closed contract: every materialization target is run through
 * AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource — a sandboxParent inside
 * app_path() throws and writes ZERO bytes.
 */
final class AtlasLoopSimulationSandboxBuilder
{
    public function __construct(
        private readonly string $sandboxParent,
    ) {}

    public function build(string $sourceRoot, ?string $seed = null): SandboxHandle
    {
        $seed = $seed ?? 'sandbox';
        $sandboxPath = rtrim($this->sandboxParent, '/').'/atlas-sim-'.preg_replace('/[^a-z0-9_-]/i', '_', $seed);
        try {
            AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource($sandboxPath);
        } catch (\Throwable $e) {
            throw new LogicException(
                'AtlasLoopSimulationSandboxBuilder: refusing to build inside live source tree ('.$sandboxPath.')',
                0,
                $e,
            );
        }

        if (! is_dir($sandboxPath)) {
            mkdir($sandboxPath, 0o755, true);
        }

        $commitSha = $this->gitRevParseHead($sourceRoot);
        $trackedFiles = $this->gitLsFiles($sourceRoot);
        $dirtyFiles = $this->dirtyFingerprints($sourceRoot, $trackedFiles);

        foreach ($trackedFiles as $rel) {
            $src = $sourceRoot.'/'.$rel;
            if (! is_file($src)) {
                continue;
            }
            $dst = $sandboxPath.'/'.$rel;
            AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource($dst);
            $parent = dirname($dst);
            if (! is_dir($parent)) {
                mkdir($parent, 0o755, true);
            }
            copy($src, $dst);
        }

        $contentChecksum = $this->computeContentChecksum($commitSha, $dirtyFiles, $seed);

        return new SandboxHandle(
            sandboxPath: $sandboxPath,
            sourceCommitSha: $commitSha,
            dirtyFiles: $dirtyFiles,
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
            contentChecksum: $contentChecksum,
            seed: $seed,
        );
    }

    private function gitRevParseHead(string $root): string
    {
        $proc = new Process(['git', 'rev-parse', 'HEAD'], $root);
        $proc->run();

        return $proc->isSuccessful() ? trim($proc->getOutput()) : 'unknown';
    }

    /**
     * @return list<string>
     */
    private function gitLsFiles(string $root): array
    {
        $proc = new Process(['git', 'ls-files', '-z'], $root);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return [];
        }
        $files = array_values(array_filter(explode("\0", $proc->getOutput()), static fn (string $l): bool => $l !== ''));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  list<string>  $tracked
     * @return list<array{path:string,sha256:string}>
     */
    private function dirtyFingerprints(string $root, array $tracked): array
    {
        $proc = new Process(['git', 'status', '--porcelain=v1', '-z'], $root);
        $proc->run();
        $dirty = [];
        if ($proc->isSuccessful()) {
            foreach (array_filter(explode("\0", $proc->getOutput()), static fn (string $e): bool => $e !== '') as $line) {
                $rel = substr($line, 3);
                $abs = $root.'/'.$rel;
                $dirty[] = [
                    'path' => $rel,
                    'sha256' => is_file($abs) ? hash_file('sha256', $abs) : '0',
                ];
            }
        }
        usort($dirty, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $dirty;
    }

    /**
     * @param  list<array{path:string,sha256:string}>  $dirty
     */
    private function computeContentChecksum(string $commitSha, array $dirty, string $seed): string
    {
        $lines = [$commitSha, $seed];
        foreach ($dirty as $row) {
            $lines[] = $row['path'].'|'.$row['sha256'];
        }

        return hash('sha256', implode("\n", $lines));
    }
}

/**
 * FACT-only value object describing a built sandbox.
 *
 * @phpstan-type DirtyEntry array{path:string,sha256:string}
 */
final class SandboxHandle
{
    /**
     * @param  list<array{path:string,sha256:string}>  $dirtyFiles
     */
    public function __construct(
        public readonly string $sandboxPath,
        public readonly string $sourceCommitSha,
        public readonly array $dirtyFiles,
        public readonly string $createdAt,
        public readonly string $contentChecksum,
        public readonly string $seed,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'sandbox_path' => $this->sandboxPath,
            'source_commit_sha' => $this->sourceCommitSha,
            'dirty_files' => $this->dirtyFiles,
            'created_at' => $this->createdAt,
            'content_checksum' => $this->contentChecksum,
            'seed' => $this->seed,
        ];
    }
}
