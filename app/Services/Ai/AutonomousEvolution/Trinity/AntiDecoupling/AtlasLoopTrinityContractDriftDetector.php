<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling;

use Throwable;

/**
 * One forensic record per commit where the Trinity contract was broken. FACTS only — no severity, no rank,
 * no score. Co-located with the detector.
 */
final class TrinityContractBreachFact
{
    public function __construct(
        public readonly string $commitSha,
        public readonly string $author,
        public readonly int $committedAtUnix,
        public readonly string $primitive,
        public readonly string $side,
        public readonly ?string $counterpart,
        public readonly string $divergenceSummary,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'commit_sha' => $this->commitSha,
            'author' => $this->author,
            'committed_at_unix' => $this->committedAtUnix,
            'primitive' => $this->primitive,
            'side' => $this->side,
            'counterpart' => $this->counterpart,
            'divergence_summary' => $this->divergenceSummary,
        ];
    }
}

/**
 * Read-only FACT engine answering: which Trinity primitive last broke the contract, in which commit, and
 * which counterpart did it desynchronize? Replays {@see AtlasLoopTrinityContractAuditor} at every commit
 * boundary in the supplied window and produces a deterministic descending-by-commit-time list of
 * {@see TrinityContractBreachFact}. NO scoring, NO ranking, NO severity. An empty list means the contract
 * was intact across the window.
 *
 * History walking is duck-typed via two injected callables:
 *   - $commitsSource(int $window): list<array{sha, author, committed_at_unix}>
 *   - $providerForCommit(string $sha): callable(string $primitive, string $side):string
 *
 * So tests inject synthetic history; production wires git log + a per-commit reflection probe.
 */
final class AtlasLoopTrinityContractDriftDetector
{
    /** @var callable(int):list<array{sha:string,author:string,committed_at_unix:int}> */
    private $commitsSource;

    /** @var callable(string):callable */
    private $providerForCommit;

    /**
     * @param  callable(int):list<array{sha:string,author:string,committed_at_unix:int}>  $commitsSource
     * @param  callable(string):callable                                                  $providerForCommit
     */
    public function __construct(callable $commitsSource, callable $providerForCommit)
    {
        $this->commitsSource = $commitsSource;
        $this->providerForCommit = $providerForCommit;
    }

    /**
     * @return list<TrinityContractBreachFact>  descending by committed_at_unix; empty when no breach in window
     */
    public function detect(array $frozenContract, int $window = 100): array
    {
        $commits = ($this->commitsSource)($window);
        $facts = [];
        foreach ($commits as $commit) {
            $sha = (string) ($commit['sha'] ?? '');
            if ($sha === '') {
                continue;
            }
            $provider = ($this->providerForCommit)($sha);
            if (! is_callable($provider)) {
                continue;
            }
            try {
                (new AtlasLoopTrinityContractAuditor($provider))->audit($frozenContract);
            } catch (TrinityContractBreachException $e) {
                $facts[] = new TrinityContractBreachFact(
                    commitSha: $sha,
                    author: (string) ($commit['author'] ?? ''),
                    committedAtUnix: (int) ($commit['committed_at_unix'] ?? 0),
                    primitive: $e->primitive,
                    side: $e->side,
                    counterpart: $e->counterpart,
                    divergenceSummary: $e->getMessage(),
                );
            } catch (Throwable) {
                // Any other exception is NOT a breach — skip (no false positives from probe failures).
            }
        }

        usort($facts, static fn (TrinityContractBreachFact $x, TrinityContractBreachFact $y): int => $y->committedAtUnix <=> $x->committedAtUnix);

        return $facts;
    }
}
