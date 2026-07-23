<?php

namespace App\Services\Ai\Company\Ventures\Comprehension;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use Illuminate\Support\Str;

/**
 * Persists {@see FindingDraft}s into ai_venture_comprehension_findings with a
 * deterministic hash and per-run dedup. Shared by every capability so the
 * whole subsystem records findings identically.
 */
class ComprehensionRecorder
{
    /** @var array<string,bool> natural keys already recorded this process, per run */
    private array $seen = [];

    /**
     * @param  iterable<FindingDraft>  $drafts
     * @return array<int,AiVentureComprehensionFinding>
     */
    public function recordMany(AiVentureComprehensionRun $run, iterable $drafts): array
    {
        $recorded = [];
        foreach ($drafts as $draft) {
            $model = $this->record($run, $draft);
            if ($model !== null) {
                $recorded[] = $model;
            }
        }

        return $recorded;
    }

    public function record(AiVentureComprehensionRun $run, FindingDraft $draft): ?AiVentureComprehensionFinding
    {
        $dedupKey = $run->id.'::'.$draft->naturalKey();
        if (isset($this->seen[$dedupKey])) {
            return null;
        }

        $finding_hash = StrategyCanonicalHash::sha256([
            'run_id' => $run->id,
            'natural_key' => $draft->naturalKey(),
        ]);

        // Cross-process idempotency: if this run already has the finding, skip.
        $existing = AiVentureComprehensionFinding::query()->where('finding_hash', $finding_hash)->first();
        if ($existing !== null) {
            $this->seen[$dedupKey] = true;

            return $existing;
        }

        $model = AiVentureComprehensionFinding::query()->create([
            'uuid' => (string) Str::uuid(),
            'run_id' => $run->id,
            'venture_id' => $run->venture_id,
            'capability' => $draft->capability,
            'kind' => $draft->kind,
            'category' => $draft->category,
            'title' => Str::limit($draft->title, 480, ''),
            'detail' => $draft->detail,
            'severity' => $draft->severity,
            'impact_score' => $draft->impactScore,
            'effort_score' => $draft->effortScore,
            'leverage_score' => $draft->leverageScore,
            'confidence' => $draft->confidence,
            'evidence_kind' => $draft->evidenceKind,
            'evidence_path' => $draft->evidencePath,
            'evidence_line' => $draft->evidenceLine,
            'evidence_snippet' => $draft->evidenceSnippet !== null ? Str::limit($draft->evidenceSnippet, 800, '') : null,
            'evidence_refs' => $draft->evidenceRefs,
            'recommendation' => $draft->recommendation,
            'payload' => $draft->payload,
            'source' => $draft->source,
            'finding_hash' => $finding_hash,
        ]);

        $this->seen[$dedupKey] = true;

        return $model;
    }
}
