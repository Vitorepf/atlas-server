<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopPreHocBlastRadiusPredictor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pre-edit safety awareness: for a target --fqcn and a hypothetical member removal (--removed-member, repeatable),
 * gather consumer evidence and run the dormant {@see AtlasLoopPreHocBlastRadiusPredictor} to emit the advisory
 * facts (would_break list of {consumer_fqcn, break_kind}, safe, reason). Advisory + read-only: no file is edited,
 * no risk score invented — it only predicts which consumers a removal WOULD break.
 */
final class AtlasLoopBlastRadiusCommand extends Command
{
    /** Container key for injected consumer evidence (test seam): callable(string $fqcn): list<string|array>. */
    private const CONSUMER_SOURCE_BINDING = 'atlas.loop.blast_radius.consumer_source';

    protected $signature = 'atlas:loop:blast-radius {--fqcn=} {--removed-member=*} {--json}';

    protected $description = 'Advisory pre-edit blast-radius: which consumers a hypothetical member removal would break.';

    public function handle(AtlasLoopPreHocBlastRadiusPredictor $predictor): int
    {
        $fqcn = trim((string) $this->option('fqcn'));
        if ($fqcn === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'blast-radius requires --fqcn',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $removedMembers = array_values(array_filter(
            array_map('strval', (array) $this->option('removed-member')),
            static fn (string $member): bool => trim($member) !== '',
        ));

        $result = $predictor->predict($fqcn, ['removed_members' => $removedMembers], $this->consumerEvidence($fqcn));

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<string|array<string,mixed>>
     */
    private function consumerEvidence(string $fqcn): array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::CONSUMER_SOURCE_BINDING)) {
            $source = $app->make(self::CONSUMER_SOURCE_BINDING);
            if (is_callable($source)) {
                $result = $source($fqcn);

                return is_array($result) ? array_values($result) : [];
            }
        }

        return $this->scanConsumers($fqcn);
    }

    /**
     * Best-effort default: app/ classes that mention the target's short class name. FQCN-only evidence (no
     * member usage), so the real scan predicts breaks only when callers supply usage via the source seam.
     *
     * ponytail: O(app_files) string scan per run — fine for an occasional advisory command; index app/ if it
     * ever dominates.
     *
     * @return list<array{consumer_fqcn:string}>
     */
    private function scanConsumers(string $fqcn): array
    {
        try {
            $target = ltrim(trim($fqcn), '\\');
            $shortName = ($pos = strrpos($target, '\\')) !== false ? substr($target, $pos + 1) : $target;
            if ($shortName === '') {
                return [];
            }

            $root = base_path('app');
            if (! is_dir($root)) {
                return [];
            }

            $consumers = [];
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $entry) {
                if (! $entry instanceof \SplFileInfo || ! $entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
                    continue;
                }
                $contents = (string) @file_get_contents($entry->getPathname());
                if (! str_contains($contents, $shortName)
                    || preg_match('/^\s*namespace\s+([^\s;]+)/m', $contents, $ns) !== 1
                    || preg_match('/^(?:final\s+|abstract\s+)?(?:readonly\s+)?class\s+([A-Za-z0-9_]+)/m', $contents, $cls) !== 1) {
                    continue;
                }
                $consumerFqcn = ltrim($ns[1].'\\'.$cls[1], '\\');
                if ($consumerFqcn !== $target) {
                    $consumers[] = ['consumer_fqcn' => $consumerFqcn];
                }
            }

            return $consumers;
        } catch (Throwable) {
            return [];
        }
    }
}
