<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use App\Models\AtlasProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Self-Improvement Human Trust Ledger.
 *
 * Persists outcomes of human review on Atlas self-improvement proposals so
 * the system can measure where it earned trust, where it overreached, and
 * where it stayed too conservative. Capped at 100 entries per Obra (or per
 * "global" bucket when no Obra is bound), deduped within 30s.
 *
 * Hard rules:
 *   - NEVER calls a provider;
 *   - NEVER spends a token;
 *   - NEVER auto-promotes work just because trust is high — trust signals are
 *     diagnostic, not authorization;
 *   - Cooldown / autonomy adjustments stay outside this service.
 *
 * Schema: atlas.self_improvement.human_trust_ledger.v1
 */
class AtlasSelfImprovementHumanTrustLedgerService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.human_trust_ledger.v1';
    public const ENTRY_SCHEMA_VERSION = 'atlas.self_improvement.human_trust_ledger_entry.v1';

    public const METADATA_KEY = 'atlas_self_improvement_human_trust_ledger';
    public const MAX_ENTRIES = 100;
    public const DEDUPE_WINDOW_SECONDS = 30;

    public const OUTCOME_PROPOSAL_APPROVED = 'proposal_approved';
    public const OUTCOME_PROPOSAL_REJECTED = 'proposal_rejected';
    public const OUTCOME_PROPOSAL_REVISED = 'proposal_revised';
    public const OUTCOME_AUTOPROMOTION_ACCEPTED = 'autopromotion_accepted';
    public const OUTCOME_AUTOPROMOTION_REVERTED = 'autopromotion_reverted';
    public const OUTCOME_OVERREACH_FLAGGED = 'overreach_flagged';
    public const OUTCOME_OVER_CONSERVATIVE_FLAGGED = 'over_conservative_flagged';
    public const OUTCOME_PROPOSAL_ACCEPTED_FOR_FORGE = 'proposal_accepted_for_forge';
    public const OUTCOME_PROPOSAL_REJECTED_FOR_FORGE = 'proposal_rejected_for_forge';

    /*
     * Self-Improvement Closed Loop Level 7 v1 outcomes — emitted by
     * `AtlasSelfImprovementResultLedgerService::record()` after a measure-result
     * run produces a Delta Scorecard grade. They feed `trust_band` derivation
     * (high_trust/medium/low) per Obra and globally; they NEVER promote
     * completion claim and NEVER call a provider.
     */
    public const OUTCOME_SELF_IMPROVEMENT_MAJOR_IMPROVEMENT = 'self_improvement_major_improvement';
    public const OUTCOME_SELF_IMPROVEMENT_IMPROVED = 'self_improvement_improved';
    public const OUTCOME_SELF_IMPROVEMENT_NEUTRAL = 'self_improvement_neutral';
    public const OUTCOME_SELF_IMPROVEMENT_REGRESSED = 'self_improvement_regressed';
    public const OUTCOME_SELF_IMPROVEMENT_INVALID_EVIDENCE = 'self_improvement_invalid_evidence';

    /** @var list<string> */
    public const KNOWN_OUTCOMES = [
        self::OUTCOME_PROPOSAL_APPROVED,
        self::OUTCOME_PROPOSAL_REJECTED,
        self::OUTCOME_PROPOSAL_REVISED,
        self::OUTCOME_AUTOPROMOTION_ACCEPTED,
        self::OUTCOME_AUTOPROMOTION_REVERTED,
        self::OUTCOME_OVERREACH_FLAGGED,
        self::OUTCOME_OVER_CONSERVATIVE_FLAGGED,
        self::OUTCOME_PROPOSAL_ACCEPTED_FOR_FORGE,
        self::OUTCOME_PROPOSAL_REJECTED_FOR_FORGE,
        self::OUTCOME_SELF_IMPROVEMENT_MAJOR_IMPROVEMENT,
        self::OUTCOME_SELF_IMPROVEMENT_IMPROVED,
        self::OUTCOME_SELF_IMPROVEMENT_NEUTRAL,
        self::OUTCOME_SELF_IMPROVEMENT_REGRESSED,
        self::OUTCOME_SELF_IMPROVEMENT_INVALID_EVIDENCE,
    ];

    /**
     * Record a trust outcome.
     *
     * @param  array{
     *     proposal_id?: ?string,
     *     outcome: string,
     *     reviewer?: ?string,
     *     reason?: ?string,
     *     area?: ?string,
     *     occurred_at?: \DateTimeInterface|string|null,
     * }  $payload
     * @return array<string,mixed>
     */
    public function record(?AtlasProject $project, array $payload): array
    {
        $outcome = $this->stringOrNull($payload['outcome'] ?? null);
        if ($outcome === null || ! in_array($outcome, self::KNOWN_OUTCOMES, true)) {
            throw new \InvalidArgumentException('unknown_outcome:'.(string) $outcome);
        }

        $occurredAt = $this->resolveOccurredAt($payload['occurred_at'] ?? null);

        $entry = [
            'schema_version' => self::ENTRY_SCHEMA_VERSION,
            'entry_id' => 'trust_'.(string) Str::ulid(),
            'occurred_at' => $occurredAt->toIso8601String(),
            'outcome' => $outcome,
            'proposal_id' => $this->stringOrNull($payload['proposal_id'] ?? null),
            'reviewer' => $this->stringOrNull($payload['reviewer'] ?? null),
            'reason' => $this->stringOrNull($payload['reason'] ?? null),
            'area' => $this->stringOrNull($payload['area'] ?? null),
            'silent' => false,
            'auto_promotes_work' => false,
            'external_provider_call' => false,
        ];

        if ($project !== null) {
            $existing = $this->memoryFor($project);
            $entries = $existing['entries'] ?? [];
            if (! $this->dedupe($entries, $entry)) {
                $entries[] = $entry;
            }
            if (count($entries) > self::MAX_ENTRIES) {
                $entries = array_slice($entries, -self::MAX_ENTRIES);
            }

            $metadata = is_array($project->metadata) ? $project->metadata : [];
            $metadata[self::METADATA_KEY] = [
                'schema_version' => self::SCHEMA_VERSION,
                'obra_id' => (string) $project->getKey(),
                'entry_count' => count($entries),
                'entries' => $entries,
                'updated_at' => $occurredAt->toIso8601String(),
                'external_provider_call' => false,
            ];
            $project->forceFill(['metadata' => $metadata])->save();
        }

        $this->maybeWriteLedger($project, $entry);

        return $entry;
    }

    /**
     * @return array<string,mixed>
     */
    public function memoryFor(?AtlasProject $project): array
    {
        if ($project === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'obra_id' => null,
                'entry_count' => 0,
                'entries' => [],
                'updated_at' => null,
                'external_provider_call' => false,
            ];
        }

        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $stored = data_get($metadata, self::METADATA_KEY);
        if (! is_array($stored) || ! is_array($stored['entries'] ?? null)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'obra_id' => (string) $project->getKey(),
                'entry_count' => 0,
                'entries' => [],
                'updated_at' => null,
                'external_provider_call' => false,
            ];
        }

        $entries = array_values(array_filter($stored['entries'], 'is_array'));
        usort($entries, static fn (array $a, array $b): int => strcmp(
            (string) ($b['occurred_at'] ?? ''),
            (string) ($a['occurred_at'] ?? ''),
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'obra_id' => (string) $project->getKey(),
            'entry_count' => count($entries),
            'entries' => $entries,
            'updated_at' => $stored['updated_at'] ?? null,
            'external_provider_call' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(?AtlasProject $project): array
    {
        $memory = $this->memoryFor($project);
        $entries = $memory['entries'];

        $counts = array_fill_keys(self::KNOWN_OUTCOMES, 0);
        foreach ($entries as $entry) {
            $outcome = (string) ($entry['outcome'] ?? '');
            if (isset($counts[$outcome])) {
                $counts[$outcome]++;
            }
        }

        $approved = $counts[self::OUTCOME_PROPOSAL_APPROVED];
        $rejected = $counts[self::OUTCOME_PROPOSAL_REJECTED];
        $revised = $counts[self::OUTCOME_PROPOSAL_REVISED];
        $autopromAccepted = $counts[self::OUTCOME_AUTOPROMOTION_ACCEPTED];
        $autopromReverted = $counts[self::OUTCOME_AUTOPROMOTION_REVERTED];
        $overreach = $counts[self::OUTCOME_OVERREACH_FLAGGED];
        $overConservative = $counts[self::OUTCOME_OVER_CONSERVATIVE_FLAGGED];

        $totalProposals = $approved + $rejected + $revised;
        $approvalRate = $totalProposals > 0 ? round($approved / $totalProposals, 4) : null;
        $totalAutopromotions = $autopromAccepted + $autopromReverted;
        $autopromotionRevertRate = $totalAutopromotions > 0
            ? round($autopromReverted / $totalAutopromotions, 4)
            : null;

        $trustBand = $this->resolveTrustBand($approvalRate, $autopromotionRevertRate, $overreach, $overConservative);

        $memory['counts'] = $counts;
        $memory['summary'] = [
            'total_proposals' => $totalProposals,
            'approval_rate' => $approvalRate,
            'autopromotion_accepted' => $autopromAccepted,
            'autopromotion_reverted' => $autopromReverted,
            'autopromotion_revert_rate' => $autopromotionRevertRate,
            'overreach_flagged' => $overreach,
            'over_conservative_flagged' => $overConservative,
            'trust_band' => $trustBand,
        ];
        $memory['known_outcomes'] = self::KNOWN_OUTCOMES;
        $memory['max_entries'] = self::MAX_ENTRIES;
        $memory['dedupe_window_seconds'] = self::DEDUPE_WINDOW_SECONDS;

        return $memory;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  array<string,mixed>  $candidate
     */
    private function dedupe(array $entries, array $candidate): bool
    {
        if (empty($entries)) {
            return false;
        }
        $candidateAt = $this->parseIso($candidate['occurred_at'] ?? null);
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $entry = $entries[$i];
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['outcome'] ?? null) !== ($candidate['outcome'] ?? null)) {
                continue;
            }
            if (($entry['proposal_id'] ?? null) !== ($candidate['proposal_id'] ?? null)) {
                continue;
            }
            $existingAt = $this->parseIso($entry['occurred_at'] ?? null);
            if ($candidateAt === null || $existingAt === null) {
                return true;
            }
            if (abs($candidateAt->diffInSeconds($existingAt, true)) <= self::DEDUPE_WINDOW_SECONDS) {
                return true;
            }

            return false;
        }

        return false;
    }

    private function resolveTrustBand(
        ?float $approvalRate,
        ?float $autopromotionRevertRate,
        int $overreach,
        int $overConservative,
    ): string {
        if ($approvalRate === null) {
            return 'insufficient_data';
        }
        if ($overreach > 0 && ($autopromotionRevertRate ?? 0.0) > 0.5) {
            return 'low_trust_overreach';
        }
        if ($overConservative > 0 && $approvalRate < 0.3) {
            return 'low_trust_too_conservative';
        }
        if ($approvalRate >= 0.85 && ($autopromotionRevertRate ?? 0.0) <= 0.1) {
            return 'high_trust';
        }
        if ($approvalRate >= 0.6) {
            return 'medium_trust';
        }

        return 'low_trust';
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function maybeWriteLedger(?AtlasProject $project, array $entry): void
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return;
        }

        try {
            DB::table('atlas_ledger_events')->insert([
                'event_id' => (string) ($entry['entry_id'] ?? Str::ulid()),
                'schema_version' => self::ENTRY_SCHEMA_VERSION,
                'event_type' => 'SELF_IMPROVEMENT_TRUST_LEDGER_ENTRY',
                'emitter_stage' => 'self_improvement_human_trust_ledger',
                'emitter_version' => 'v1',
                'payload' => json_encode([
                    'obra_id' => $project ? (string) $project->getKey() : null,
                    'entry' => $entry,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'occurred_at' => $entry['occurred_at'] ?? now()->toIso8601String(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // best-effort
        }
    }

    private function parseIso(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveOccurredAt(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                // fall through
            }
        }

        return Carbon::now();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
