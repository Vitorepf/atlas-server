<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * S3.F1 — the RELEVANCE GATE (the load-bearing safety of the self-improvement loop).
 *
 * THE PROBLEM IT EXISTS TO STOP: a real self-construct run generated 412 lines of
 * IRRELEVANT garbage — a Hermes Kanban driver — for a TODO about "running/scheduled
 * tasks", because the generation prompt was not anchored to the signal and NOTHING
 * checked whether what came back had anything to do with what was asked. The brain
 * context (Salto 2) fixes the AIM; this gate is the independent CHECK that the result
 * actually hit it. Brain-anchoring + relevance gate are belt and braces.
 *
 * WHY OUT-OF-PROCESS / NOT GAMEABLE BY THE GENERATION PROMPT:
 *   - this gate runs AFTER delivery, on the FACTS of what was produced (the set of
 *     touched file paths the materializer/delivery reports) versus the FACTS of the
 *     signal (the file the signal named, its line, its concern terms) — it never reads
 *     the generation prompt and never asks the provider "is this relevant?" (which the
 *     provider could trivially answer "yes" to). The provider cannot talk its way past
 *     a deterministic comparison of two fact sets it does not control.
 *   - it mirrors the adversarial-verify discipline (the loop-proposal-adversarial-verify
 *     skill): an independent, default-refute-if-uncertain re-check before a result is
 *     presented as worthy. Here "default-refute" = a file-anchored signal whose named
 *     file was NOT touched is REJECTED (no branch is kept), logged honestly.
 *
 * THE RULE (file-anchored signals — code markers / target_file gaps):
 *   a delivery is RELEVANT iff it TOUCHED the file the signal named (the same file, or
 *   a sibling under the same path the operator can defend reviewing). An operator gap
 *   that names no file cannot be relevance-checked by target and is admitted as long as
 *   it produced a branch at all (the operator chose to spend on it; their judgement is
 *   the gate). The gate NEVER fabricates a pass: an empty delivery is never relevant.
 *
 * The gate is a pure DECISION FUNCTION (no I/O, deterministic) so it is trivially
 * testable and cannot itself spend or merge. The caller (the loop) discards the branch
 * on REJECT and records the honest reason.
 */
final class AtlasSelfImprovementRelevanceGate
{
    public const SCHEMA = 'atlas.ai.self_improvement_relevance.v1';

    /**
     * Evaluate a delivered self-improvement against the signal that requested it.
     *
     * @param  array{area?:string,file?:?string,line?:?int,signal?:string,request?:string,source?:string}  $signal
     * @param  array<string,mixed>  $delivery  the mission/orchestrator envelope (delivered, branch, delivery.files / files)
     * @return array{relevant:bool,reason:string,target_file:?string,touched_files:list<string>,matched_file:?string,schema_version:string}
     */
    public function evaluate(array $signal, array $delivery): array
    {
        $target = $this->normalisePath($signal['file'] ?? null);
        $touched = $this->touchedFiles($delivery);
        $delivered = (bool) ($delivery['delivered'] ?? false);

        // A delivery that produced no files is never relevant — the gate never
        // fabricates a pass for an empty/blocked result (default-refute).
        if (! $delivered || $touched === []) {
            return $this->verdict(false, 'no_delivery_to_check', $target, $touched, null);
        }

        // Operator gaps with no named file cannot be target-checked; the operator's
        // decision to spend on the request is itself the admission (it produced a
        // branch). Still honest: we report there was no target to verify against.
        if ($target === null) {
            return $this->verdict(true, 'no_target_unverifiable_admitted', null, $touched, null);
        }

        // File-anchored: the named file (or a sibling under the same directory) MUST
        // be among the touched files, otherwise the generation went off-target — the
        // exact 412-line-garbage failure mode — and is REJECTED.
        $matched = $this->matchTarget($target, $touched);
        if ($matched === null) {
            return $this->verdict(false, 'off_target_generation', $target, $touched, null);
        }

        return $this->verdict(true, 'on_target', $target, $touched, $matched);
    }

    /**
     * Collect the file paths a delivery touched, from either the flat product
     * envelope (delivery.files: list<string>) or the orchestrator's raw shape.
     *
     * @param  array<string,mixed>  $delivery
     * @return list<string>
     */
    private function touchedFiles(array $delivery): array
    {
        $raw = [];

        // AtlasMissionService / MissionDeliveryOrchestrator envelope: delivery.files
        // is a list of relative path strings.
        $nested = $delivery['delivery']['files'] ?? null;
        if (is_array($nested)) {
            foreach ($nested as $f) {
                if (is_string($f)) {
                    $raw[] = $f;
                } elseif (is_array($f) && isset($f['path']) && is_string($f['path'])) {
                    $raw[] = $f['path'];
                }
            }
        }

        // Fallback: a top-level files list (some shapes carry {path,...} entries).
        foreach ((array) ($delivery['files'] ?? []) as $f) {
            if (is_string($f)) {
                $raw[] = $f;
            } elseif (is_array($f) && isset($f['path']) && is_string($f['path'])) {
                $raw[] = $f['path'];
            }
        }

        $out = [];
        foreach ($raw as $p) {
            $norm = $this->normalisePath($p);
            if ($norm !== null) {
                $out[$norm] = true; // dedupe, deterministic order below
            }
        }
        $out = array_keys($out);
        sort($out);

        return $out;
    }

    /**
     * The named target counts as touched if an exact path matches, or a touched file
     * sits under the SAME directory as the target (a defensible adjacent change the
     * operator can review in context). Anything outside that directory is off-target.
     *
     * @param  list<string>  $touched
     */
    private function matchTarget(string $target, array $touched): ?string
    {
        if (in_array($target, $touched, true)) {
            return $target;
        }

        $dir = $this->dirOf($target);
        foreach ($touched as $t) {
            if ($this->dirOf($t) === $dir) {
                return $t;
            }
        }

        return null;
    }

    private function dirOf(string $path): string
    {
        $pos = strrpos($path, '/');

        return $pos === false ? '' : substr($path, 0, $pos);
    }

    /**
     * Normalise a path to a comparable relative form: trim, drop leading "./" and
     * a leading slash, collapse backslashes. Returns null for empty/non-path input.
     */
    private function normalisePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }
        $p = trim($path);
        if ($p === '') {
            return null;
        }
        $p = str_replace('\\', '/', $p);
        $p = preg_replace('#^\./#', '', $p) ?? $p;

        return ltrim($p, '/') !== '' ? ltrim($p, '/') : null;
    }

    /**
     * @param  list<string>  $touched
     * @return array{relevant:bool,reason:string,target_file:?string,touched_files:list<string>,matched_file:?string,schema_version:string}
     */
    private function verdict(bool $relevant, string $reason, ?string $target, array $touched, ?string $matched): array
    {
        return [
            'relevant' => $relevant,
            'reason' => $reason,
            'target_file' => $target,
            'touched_files' => $touched,
            'matched_file' => $matched,
            'schema_version' => self::SCHEMA,
        ];
    }
}
