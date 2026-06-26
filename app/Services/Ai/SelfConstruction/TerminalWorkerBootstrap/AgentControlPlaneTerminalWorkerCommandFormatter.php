<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap;

/**
 * ITEM8 — the cohesive command / string-formatting concern the terminal-worker bootstrap service
 * uses to render CLI arguments, escape values, recommend queue tags, and normalize string lists.
 *
 * Five methods migrated verbatim from
 * {@see \App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalWorkerBootstrapService}:
 *  - {@see self::bootstrapCommand}: the full `atlas:ai:self-construction` CLI invocation that
 *    kicks off the terminal-worker status probe, including the actor / target-min / max-new
 *    flags and a per-tag `--queue-tag=...` argument for each tag in the list.
 *  - {@see self::commandValue}: shell-safe value emission — values matching
 *    `[A-Za-z0-9_.:@\\/-]+` are returned verbatim (cheap, no quoting overhead); anything else
 *    is `escapeshellarg`-wrapped so a malicious actor / tag cannot inject shell metachars.
 *  - {@see self::queueTagArgs}: render the `--queue-tag=...` argument suffix for an existing
 *    command line (returns an empty string when the list is empty so the caller can always
 *    concatenate safely).
 *  - {@see self::recommendedQueueTag}: derive the canonical `terminal-loop-<slug>` queue tag
 *    from an actor string (lowercase, slug-safe, trimmed of stray separators).
 *  - {@see self::stringList}: trim + non-empty filter + array_values on any list of mixed values
 *    (the same shape used by {@see \App\Services\Ai\SelfConstruction\TaskQueue\TaskPacketCanonicalizer}
 *    and {@see \App\Services\Ai\SelfConstruction\Leasing\AgentControlPlaneLeasePathCanonicalizer}).
 *
 * Pure / stateless / zero Laravel surface. The escaping regex and the queue-tag slug regex are
 * preserved verbatim from the god-class so the byte-identical CLI contract survives the split.
 */
class AgentControlPlaneTerminalWorkerCommandFormatter
{
    /**
     * @param  list<string>  $queueTags
     */
    public function bootstrapCommand(string $actor, int $targetMin, int $maxNew, array $queueTags): string
    {
        $parts = [
            'php artisan atlas:ai:self-construction',
            '--agent-control-plane-terminal-worker-bootstrap-status',
            '--actor='.$this->commandValue($actor),
            '--target-min-claimable-tasks='.$targetMin,
            '--max-new-tasks='.$maxNew,
        ];

        foreach ($queueTags as $tag) {
            $parts[] = '--queue-tag='.$this->commandValue($tag);
        }

        $parts[] = '--json';

        return implode(' ', $parts);
    }

    public function commandValue(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.:@\\/-]+$/', $value) === 1) {
            return $value;
        }

        return escapeshellarg($value);
    }

    /**
     * @param  list<string>  $queueTags
     */
    public function queueTagArgs(array $queueTags): string
    {
        if ($queueTags === []) {
            return '';
        }

        return ' '.implode(' ', array_map(
            fn (string $tag): string => '--queue-tag='.$this->commandValue($tag),
            $queueTags,
        ));
    }

    public function recommendedQueueTag(string $actor): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9_.:-]+/', '-', trim($actor)));
        $slug = trim($slug, '-._:');

        return 'terminal-loop-'.($slug === '' ? 'codex' : $slug);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }
}
