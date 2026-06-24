<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Feedback;

use Illuminate\Support\Carbon;

/**
 * FEEDBACK RECEIPT LEDGER — the audit substrate for the give_back→Replenisher feedback loop: every time mined
 * FACTs are applied to the Replenisher (see {@see AtlasLoopGiveBackToReplenisherFeedback}), one receipt records
 * WHICH input records produced it (by sha256 over their canonical serialization), WHEN, a compact summary of
 * the mined FACTs, and the resulting context-key delta. Without it the Loop's self-learning is unobservable
 * and ungovernable.
 *
 * APPEND-ONLY by construction: the public surface is record()/list()/count() only — there is NO update/delete,
 * and prior receipts are immutable (stored verbatim, never recomputed). list() returns chronological order.
 */
final class AtlasLoopFeedbackReceiptLedger
{
    public const SCHEMA = 'atlas.loop.feedback_receipt.v1';

    /** @var list<array<string,mixed>> append-only, insertion (chronological) order */
    private array $receipts = [];

    private int $seq = 0;

    /**
     * Append ONE feedback-application receipt; returns its id.
     *
     * @param  array{input_records?:list<mixed>, mined_facts?:array<string,mixed>, context_keys_added?:list<string>}  $application
     */
    public function record(array $application): string
    {
        $this->seq++;
        $inputRecords = array_values((array) ($application['input_records'] ?? []));
        $inputHash = hash('sha256', (string) json_encode($this->canonicalize($inputRecords), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $id = 'fbr-'.$this->seq.'-'.substr($inputHash, 0, 12);

        $this->receipts[] = [
            'schema' => self::SCHEMA,
            'id' => $id,
            'seq' => $this->seq,
            'applied_at' => Carbon::now('UTC')->toIso8601String(),
            'input_records_sha256' => $inputHash,
            'mined_facts_summary' => $this->summarize((array) ($application['mined_facts'] ?? [])),
            'context_keys_added' => array_values(array_filter(
                array_map(static fn ($k): string => (string) $k, (array) ($application['context_keys_added'] ?? [])),
                static fn (string $k): bool => $k !== '',
            )),
        ];

        return $id;
    }

    /**
     * The receipts in chronological (insertion) order, capped at $limit (<=0 ⇒ empty).
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $limit = 100): array
    {
        if ($limit <= 0) {
            return [];
        }

        return array_slice($this->receipts, 0, $limit);
    }

    public function count(): int
    {
        return count($this->receipts);
    }

    /**
     * A compact FACT summary (counts only — no scalar score) of the mined give_back FACTs.
     *
     * @param  array<string,mixed>  $minedFacts
     * @return array<string,int>
     */
    private function summarize(array $minedFacts): array
    {
        return [
            'top_reasons' => count((array) ($minedFacts['top_reasons'] ?? [])),
            'classes_rated' => count((array) ($minedFacts['give_back_rate_by_class'] ?? [])),
            'workers' => count((array) ($minedFacts['worker_concentration'] ?? [])),
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->canonicalize($v);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
