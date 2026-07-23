<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class ScanPrimitivesSupport
{
    private ?string $kernelDocumentationCorpus = null;

    /**
     * @var array<string,string>
     */
    private array $fileContentsCache = [];

    /**
     * @param  array<int,string>  $tokens
     * @param  array<int,string>  $ignoredPaths
     * @return array<int,string>
     */
    public function scanPhpFilesForForbiddenTokens(string $directory, array $tokens, array $ignoredPaths = []): array
    {
        if (! File::isDirectory($directory)) {
            return ["missing directory [{$directory}]"];
        }

        $violations = [];
        $ignored = array_flip(array_map(fn (string $path): string => realpath($path) ?: $path, $ignoredPaths));

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath() ?: $file->getPathname();
            if (isset($ignored[$path])) {
                continue;
            }

            $contents = File::get($path);
            foreach ($tokens as $token) {
                if (str_contains($contents, $token)) {
                    $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).": forbidden token [{$token}]";
                }
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @param  array<int,string>  $tokens
     * @return array<int,string>
     */
    public function missingTokenViolations(string $content, array $tokens, string $messagePrefix): array
    {
        $violations = [];
        foreach ($tokens as $token) {
            if (! str_contains($content, $token)) {
                $violations[] = "{$messagePrefix} [{$token}]";
            }
        }

        return $violations;
    }

    public function fileContents(string $path): string
    {
        // ponytail: per-run cache; compliance scans are read-only over disk
        return $this->fileContentsCache[$path] ??= (File::exists($path) ? File::get($path) : '');
    }

    /**
     * AiWorker implementation corpus = the facade file PLUS its owned
     * app/Services/Ai/AiWorkerSupport/*Section.php files. GOD-DEBULK split the
     * AiWorker godfile into same-family Section classes (constructed by, and
     * delegated to from, AiWorker) — so the worker's architectural contracts
     * (SLO stages, kernel repair contract, iteration-policy normalizer,
     * normalized provider-usage events) now legitimately span those sections.
     * Scanning the union keeps the AP invariants at full strength (the token
     * must still exist somewhere in the worker family) without pinning the
     * implementation to a single monolithic file.
     */
    public function aiWorkerImplementationCorpus(): string
    {
        return $this->fileContentsCache['__ai_worker_impl_corpus__'] ??= (function (): string {
            $corpus = $this->fileContents(app_path('Services/Ai/AiWorker.php'));
            $supportDir = app_path('Services/Ai/AiWorkerSupport');
            if (File::isDirectory($supportDir)) {
                foreach (File::files($supportDir) as $file) {
                    if ($file->getExtension() === 'php') {
                        $corpus .= "\n".File::get($file->getPathname());
                    }
                }
            }

            return $corpus;
        })();
    }

    public function kernelDocumentationCorpus(): string
    {
        if ($this->kernelDocumentationCorpus !== null) {
            return $this->kernelDocumentationCorpus;
        }

        $paths = [
            base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md'),
            base_path('docs/engineering-knowledge-base/kernel/contracts.md'),
            base_path('docs/engineering-knowledge-base/kernel/static-scans.md'),
            base_path('docs/engineering-knowledge-base/kernel/roadmap-ap-index.md'),
            base_path('docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md'),
            base_path('docs/engineering-knowledge-base/archive/source-material/kernel/atlas-ai-kernel-architecture-full-2026-05-08.md'),
        ];

        foreach (glob(base_path('docs/ap/AP-*.md')) ?: [] as $apDocPath) {
            $paths[] = $apDocPath;
        }

        $contents = [];
        foreach (array_values(array_unique($paths)) as $path) {
            if (is_string($path) && File::exists($path)) {
                $contents[] = File::get($path);
            }
        }

        return $this->kernelDocumentationCorpus = implode("\n\n---\n\n", $contents);
    }

    public function selfImprovementDomainDocumentationCorpus(): string
    {
        return $this->documentationCorpus([
            base_path('docs/engineering-knowledge-base/domains/self-improvement.md'),
            base_path('docs/engineering-knowledge-base/domains/self-improvement-flows.md'),
            base_path('docs/engineering-knowledge-base/domains/self-improvement-runtime.md'),
            base_path('docs/engineering-knowledge-base/archive/source-material/domains-self-improvement-full-2026-05-08.md'),
        ]);
    }

    /**
     * @param  array<int,string>  $paths
     */
    public function documentationCorpus(array $paths): string
    {
        $contents = [];
        foreach (array_values(array_unique($paths)) as $path) {
            if (File::exists($path)) {
                $contents[] = File::get($path);
            }
        }

        return implode("\n\n---\n\n", $contents);
    }
}
