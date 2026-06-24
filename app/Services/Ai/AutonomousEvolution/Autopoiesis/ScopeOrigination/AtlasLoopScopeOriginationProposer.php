<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination;

final class AtlasLoopScopeOriginationProposer
{
    /** @var array<string,mixed>|null */
    private ?array $lastAbstainReason = null;

    /**
     * @param  array<string,mixed>  $policy
     */
    public function __construct(
        private readonly array $policy = [],
    ) {}

    public function propose(LivingSignalSnapshot $snap): ?ScopeProposal
    {
        $snapshot = $snap->toArray();
        $snapshotHash = (string) ($snapshot['content_hash'] ?? '');
        $sources = is_array($snapshot['sources'] ?? null) ? $snapshot['sources'] : [];

        $factRefs = [];
        $targetPaths = [];

        foreach ($sources as $source => $facts) {
            if (! is_array($facts)) {
                continue;
            }

            foreach ($facts as $fact) {
                if (! is_array($fact)) {
                    continue;
                }

                $factId = trim((string) ($fact['fact_id'] ?? ''));
                if ($factId === '') {
                    continue;
                }

                $factRefs[] = [
                    'fact_id' => $factId,
                    'snapshot_hash' => $snapshotHash,
                    'source' => (string) $source,
                ];

                foreach ($this->paths($fact) as $path) {
                    $targetPaths[$path] = true;
                }
            }
        }

        if ($factRefs === []) {
            $this->lastAbstainReason = [
                'kind' => 'no_fact_refs',
                'message' => 'No FACT, no proposal.',
                'snapshot_hash' => $snapshotHash,
            ];

            return null;
        }

        $targetPaths = array_keys($targetPaths);
        sort($targetPaths);

        if ($targetPaths === []) {
            $this->lastAbstainReason = [
                'kind' => 'no_target_paths',
                'message' => 'FACT refs exist but no concrete target paths were derivable.',
                'snapshot_hash' => $snapshotHash,
            ];

            return null;
        }

        usort($factRefs, static function (array $left, array $right): int {
            $sourceOrder = strcmp($left['source'], $right['source']);
            if ($sourceOrder !== 0) {
                return $sourceOrder;
            }

            return strcmp($left['fact_id'], $right['fact_id']);
        });

        $this->lastAbstainReason = null;

        return new ScopeProposal([
            'expected_leverage_signal' => $this->expectedLeverageSignal($snapshot),
            'fact_refs' => $factRefs,
            'objective_text' => $this->objectiveText($targetPaths, $snapshot),
            'target_paths' => $targetPaths,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function lastAbstainReason(): ?array
    {
        return $this->lastAbstainReason;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function expectedLeverageSignal(array $snapshot): string
    {
        $coherence = $snapshot['coherence'] ?? null;
        if (is_float($coherence) || is_int($coherence)) {
            return 'coherence:'.number_format((float) $coherence, 6, '.', '');
        }

        return (string) ($this->policy['default_expected_leverage_signal'] ?? 'coherence:unknown');
    }

    /**
     * @param  list<string>  $targetPaths
     * @param  array<string,mixed>  $snapshot
     */
    private function objectiveText(array $targetPaths, array $snapshot): string
    {
        $prefix = (string) ($this->policy['objective_prefix'] ?? 'Originate concrete scope for');
        $coherence = $snapshot['coherence'] ?? null;
        $signal = is_float($coherence) || is_int($coherence)
            ? 'coherence '.number_format((float) $coherence, 6, '.', '')
            : 'unknown coherence';

        return $prefix.' '.implode(', ', $targetPaths).' with '.$signal;
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return list<string>
     */
    private function paths(array $fact): array
    {
        $paths = [];

        foreach (['target_path', 'path', 'file'] as $key) {
            $value = trim((string) ($fact[$key] ?? ''));
            if ($value !== '') {
                $paths[] = $value;
            }
        }

        foreach (['target_paths', 'paths', 'files'] as $key) {
            $value = $fact[$key] ?? null;
            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $paths[] = trim($item);
                }
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }
}
