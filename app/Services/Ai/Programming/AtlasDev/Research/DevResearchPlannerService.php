<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Research;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ResearchFinding;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ResearchOpenQuestion;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ResearchSource;
use App\Services\Ai\Programming\AtlasDev\Schemas\ResearchReceipt;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Dev research planner — turns a question + a curated set of canonical
 * sources into a `atlas.dev.research_receipt.v1` honest projection.
 *
 * The planner is intentionally PROVIDER-FREE: no LLM is invoked, no rivals
 * battery is involved. It does basic heuristics over operator-supplied
 * sources + findings + open questions and emits the canonical receipt with
 * an honesty-checked status:
 *
 *  - sources empty AND findings empty AND blocker_reasons empty
 *    → status=`blocked_insufficient_context`, blocker_reasons synthesised.
 *  - sources non-empty AND findings empty
 *    → status=`no_canonical_source`, open question synthesised when absent.
 *  - findings present AND open_questions empty
 *    → status=`answered`.
 *  - findings present AND open_questions non-empty
 *    → status=`partial`.
 *
 * Confidence is derived as the mean of finding confidences (or 0 when
 * empty), clipped to [0, 1] — never overridden upward.
 *
 * Why a planner instead of a full Dev Research orchestrator: the E2E battery
 * needs an auditable receipt path that doesn't depend on a provider. A
 * future runtime can replace `plan()` with a richer implementation while
 * keeping the schema stable.
 */
class DevResearchPlannerService
{
    /**
     * @param  array<string,mixed>  $input  shape:
     *                                      {
     *                                      run_id: string (required),
     *                                      question: string (required, trimmed non-empty),
     *                                      receipt_id?: string (auto if absent),
     *                                      sources?: list<array{kind,ref,hash?,excerpt?}>,
     *                                      findings?: list<array{finding_id,claim,confidence,supports,caveat?}>,
     *                                      open_questions?: list<array{question_id,question,severity,suggested_next_step?}>,
     *                                      evidence_refs?: list<string>,
     *                                      blocker_reasons?: list<string>,
     *                                      status_hint?: string (optional override; planner still validates),
     *                                      }
     */
    public function plan(array $input): ResearchReceipt
    {
        $runId = $this->requireString($input, 'run_id');
        $question = $this->requireString($input, 'question');
        $receiptId = (string) ($input['receipt_id'] ?? 'rsch-'.Str::uuid());

        $sources = $this->buildSources((array) ($input['sources'] ?? []));
        $findings = $this->buildFindings((array) ($input['findings'] ?? []), count($sources));
        $openQuestions = $this->buildOpenQuestions((array) ($input['open_questions'] ?? []));
        $evidenceRefs = AtlasDevStringListNormalizer::requireNonBlankStrings(
            (array) ($input['evidence_refs'] ?? []),
            'evidence_refs',
        );
        $blockerReasons = AtlasDevStringListNormalizer::requireNonBlankStrings(
            (array) ($input['blocker_reasons'] ?? []),
            'blocker_reasons',
        );

        $statusHint = isset($input['status_hint']) && is_string($input['status_hint'])
            ? (string) $input['status_hint']
            : null;

        $derivedStatus = $this->deriveStatus(
            sources: $sources,
            findings: $findings,
            openQuestions: $openQuestions,
            blockerReasons: $blockerReasons,
        );

        // Synthesise the minimum invariants for the derived status so the
        // schema constructor accepts the receipt. NEVER fabricate findings —
        // only fill in the blocker_reasons / open_questions that document
        // the gap honestly.
        if ($derivedStatus === ResearchReceipt::STATUS_BLOCKED_INSUFFICIENT_CONTEXT && $blockerReasons === []) {
            $blockerReasons = ['no_sources_or_findings_supplied'];
        }
        if ($derivedStatus === ResearchReceipt::STATUS_NO_CANONICAL_SOURCE && $openQuestions === []) {
            $openQuestions = [
                new ResearchOpenQuestion(
                    questionId: 'oq-no-canonical-doc-'.substr(hash('sha256', $question), 0, 8),
                    question: 'No canonical doc answers: '.Str::limit($question, 200),
                    severity: ResearchOpenQuestion::SEVERITY_MEDIUM,
                    suggestedNextStep: 'escalate_to_human_or_request_canonical_source',
                ),
            ];
        }

        $status = $statusHint !== null && in_array($statusHint, ResearchReceipt::ALLOWED_STATUSES, true)
            ? $statusHint
            : $derivedStatus;

        $confidence = $this->deriveConfidence($findings);

        return ResearchReceipt::issue(
            receiptId: $receiptId,
            runId: $runId,
            question: $question,
            status: $status,
            sources: $sources,
            findings: $findings,
            openQuestions: $openQuestions,
            confidence: $confidence,
            evidenceRefs: $evidenceRefs,
            blockerReasons: $blockerReasons,
            createdAt: now()->toIso8601String(),
        );
    }

    /**
     * @param  list<ResearchSource>  $sources
     * @param  list<ResearchFinding>  $findings
     * @param  list<ResearchOpenQuestion>  $openQuestions
     * @param  list<string>  $blockerReasons
     */
    private function deriveStatus(array $sources, array $findings, array $openQuestions, array $blockerReasons): string
    {
        if ($blockerReasons !== []) {
            return ResearchReceipt::STATUS_BLOCKED_INSUFFICIENT_CONTEXT;
        }
        if ($findings === []) {
            if ($sources === []) {
                return ResearchReceipt::STATUS_BLOCKED_INSUFFICIENT_CONTEXT;
            }

            return ResearchReceipt::STATUS_NO_CANONICAL_SOURCE;
        }
        if ($openQuestions !== []) {
            return ResearchReceipt::STATUS_PARTIAL;
        }

        return ResearchReceipt::STATUS_ANSWERED;
    }

    /**
     * @param  list<ResearchFinding>  $findings
     */
    private function deriveConfidence(array $findings): float
    {
        if ($findings === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($findings as $f) {
            $sum += $f->confidence;
        }
        $mean = $sum / count($findings);

        return max(0.0, min(1.0, $mean));
    }

    /**
     * @param  list<array<string,mixed>>  $raw
     * @return list<ResearchSource>
     */
    private function buildSources(array $raw): array
    {
        $out = [];
        foreach ($raw as $i => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException("sources[{$i}] must be an array.");
            }
            $out[] = new ResearchSource(
                kind: (string) ($entry['kind'] ?? ''),
                ref: (string) ($entry['ref'] ?? ''),
                hash: isset($entry['hash']) && is_string($entry['hash']) ? $entry['hash'] : null,
                excerpt: isset($entry['excerpt']) && is_string($entry['excerpt']) ? $entry['excerpt'] : null,
            );
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $raw
     * @return list<ResearchFinding>
     */
    private function buildFindings(array $raw, int $sourcesCount): array
    {
        $out = [];
        foreach ($raw as $i => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException("findings[{$i}] must be an array.");
            }
            $supports = [];
            foreach ((array) ($entry['supports'] ?? []) as $s) {
                if (! is_int($s) && ! (is_string($s) && ctype_digit($s))) {
                    throw new InvalidArgumentException("findings[{$i}].supports must be int indexes.");
                }
                $supports[] = (int) $s;
            }
            if ($supports === []) {
                throw new InvalidArgumentException("findings[{$i}].supports must reference at least one source.");
            }
            foreach ($supports as $sIdx) {
                if ($sIdx < 0 || $sIdx >= $sourcesCount) {
                    throw new InvalidArgumentException("findings[{$i}].supports references source index {$sIdx} but {$sourcesCount} sources exist.");
                }
            }

            $out[] = new ResearchFinding(
                findingId: (string) ($entry['finding_id'] ?? ''),
                claim: (string) ($entry['claim'] ?? ''),
                confidence: isset($entry['confidence']) && is_numeric($entry['confidence']) ? (float) $entry['confidence'] : 0.0,
                supports: $supports,
                caveat: isset($entry['caveat']) && is_string($entry['caveat']) ? $entry['caveat'] : null,
            );
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $raw
     * @return list<ResearchOpenQuestion>
     */
    private function buildOpenQuestions(array $raw): array
    {
        $out = [];
        foreach ($raw as $i => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException("open_questions[{$i}] must be an array.");
            }
            $out[] = new ResearchOpenQuestion(
                questionId: (string) ($entry['question_id'] ?? ''),
                question: (string) ($entry['question'] ?? ''),
                severity: (string) ($entry['severity'] ?? ResearchOpenQuestion::SEVERITY_LOW),
                suggestedNextStep: isset($entry['suggested_next_step']) && is_string($entry['suggested_next_step'])
                    ? $entry['suggested_next_step']
                    : null,
            );
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function requireString(array $input, string $key): string
    {
        if (! isset($input[$key]) || ! is_string($input[$key])) {
            throw new InvalidArgumentException("DevResearchPlannerService input '{$key}' must be a string.");
        }
        $value = trim($input[$key]);
        if ($value === '') {
            throw new InvalidArgumentException("DevResearchPlannerService input '{$key}' must not be empty.");
        }

        return $value;
    }
}
