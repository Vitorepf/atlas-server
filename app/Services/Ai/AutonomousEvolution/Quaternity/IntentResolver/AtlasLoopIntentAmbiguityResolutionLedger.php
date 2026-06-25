<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver;

use RuntimeException;

/**
 * Append-only, Merkle-chained ledger of operator resolutions for ambiguity clarifying questions.
 *
 * Each row carries: intent_id, ambiguity_finding_id, question_hash, chosen_candidate_string (or
 * null = "I don't know"), resolved_at_utc, operator_signature_hash, prior_row_hash.
 *
 * append() refuses when:
 *   - the question_hash is NOT in the open follow-up set (resolver returns false)
 *   - the chosen_candidate_string is NOT byte-identical to one of the proposed candidates
 *   - the supplied prior_row_hash does NOT match the actual tail
 *
 * NEVER mutates prior rows; binding into the intent record is a downstream consumer concern.
 */
final class AtlasLoopIntentAmbiguityResolutionLedger
{
    public const SCHEMA = 'atlas.loop.intent_ambiguity_resolution.v1';

    /** @var callable(string $questionHash): bool */
    private $openSetPredicate;

    /** @var callable(string $questionHash): list<string> */
    private $candidatesProvider;

    public function __construct(
        private readonly string $ledgerPath,
        callable $openSetPredicate,
        callable $candidatesProvider,
    ) {
        $this->openSetPredicate = $openSetPredicate;
        $this->candidatesProvider = $candidatesProvider;
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @return array<string,mixed>  the appended row (after row_hash computation)
     */
    public function append(
        string $intentId,
        string $ambiguityFindingId,
        string $questionHash,
        ?string $chosenCandidate,
        string $resolvedAtUtc,
        string $operatorSignatureHash,
        string $expectedPriorRowHash,
    ): array {
        if (! ($this->openSetPredicate)($questionHash)) {
            throw new IntentAmbiguityResolutionRejectedException('question_hash_not_in_open_set:'.$questionHash);
        }

        $proposed = ($this->candidatesProvider)($questionHash);
        if ($chosenCandidate !== null && ! in_array($chosenCandidate, $proposed, true)) {
            throw new IntentAmbiguityResolutionRejectedException('candidate_not_in_proposed_set');
        }

        $actualTail = $this->tailHash();
        if ($expectedPriorRowHash !== $actualTail) {
            throw new IntentAmbiguityResolutionRejectedException('prior_row_hash_mismatch: expected='.$expectedPriorRowHash.' actual='.$actualTail);
        }

        $row = [
            'schema' => self::SCHEMA,
            'intent_id' => $intentId,
            'ambiguity_finding_id' => $ambiguityFindingId,
            'question_hash' => $questionHash,
            'chosen_candidate_string' => $chosenCandidate,
            'resolved_at_utc' => $resolvedAtUtc,
            'operator_signature_hash' => $operatorSignatureHash,
            'prior_row_hash' => $expectedPriorRowHash,
        ];
        $row['row_hash'] = $this->rowHash($row);

        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = @fopen($this->ledgerPath, 'a');
        if ($handle === false) {
            throw new RuntimeException('AtlasLoopIntentAmbiguityResolutionLedger: cannot open '.$this->ledgerPath);
        }
        try {
            fwrite($handle, $line."\n");
            fflush($handle);
        } finally {
            fclose($handle);
        }

        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function tail(): ?array
    {
        $rows = $this->readAll();

        return $rows === [] ? null : $rows[count($rows) - 1];
    }

    public function tailHash(): string
    {
        $tail = $this->tail();

        return $tail === null ? '' : (string) ($tail['row_hash'] ?? '');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function snapshotForIntent(string $intentId): array
    {
        $rows = $this->readAll();

        return array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['intent_id'] ?? '') === $intentId));
    }

    public function chainVerify(): bool
    {
        $expectedPrior = '';
        foreach ($this->readAll() as $row) {
            if ((string) ($row['prior_row_hash'] ?? '<missing>') !== $expectedPrior) {
                return false;
            }
            $check = $row;
            $stored = (string) ($check['row_hash'] ?? '');
            unset($check['row_hash']);
            $recomputed = $this->rowHash($check);
            if ($recomputed !== $stored) {
                return false;
            }
            $expectedPrior = $stored;
        }

        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readAll(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $rows = [];
        $handle = @fopen($this->ledgerPath, 'r');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function rowHash(array $row): string
    {
        unset($row['row_hash']);
        ksort($row, SORT_STRING);
        $canonical = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $canonical);
    }
}

final class IntentAmbiguityResolutionRejectedException extends RuntimeException
{
}
