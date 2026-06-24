<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;

/**
 * P1 MATERIAL-FUEL CONNECTION — the external bug-reproduction handle stamper.
 *
 * The bug-reproduction lane is wired but DRY-by-construction: the in-repo harvester only consumes the loop's
 * OWN phpunit JSON report, and the loop's own scope is green — so a failure handle from a REAL out-of-scope CI
 * artifact never reaches a target's signals['failure_test_path'] and the bug-fix lane is starved. This stamper
 * closes that gap: the operator drops a JSON artifact in storage/atlas-loop/ describing real failures, and the
 * stamper MERGES those operator-supplied failure handles onto the matching, already-discovered target rows.
 *
 * ANTI-GOODHART (the load-bearing invariant): the stamper NEVER fabricates a target row or a failure. It only
 * merges operator-supplied JSON onto targets that ALREADY EXIST in the campaign and are still in-flight. It
 * never inserts, never invents a failure handle, and skips pétreo self-targets. The bug lane's own
 * red_required + Guard-4 path still owns the certification — this only feeds it a real handle to grind.
 *
 * Fail-closed at every boundary: disabled / missing artifact / malformed JSON / no matching target ⇒ a 0-count
 * receipt, never a fabricated stamp.
 */
final class AtlasLoopFailureHandleSignalStamper
{
    public const SCHEMA_VERSION = 'atlas.loop.failure_handle_external_stamp.v1';

    /**
     * In-flight target statuses eligible to receive a freshly-stamped failure handle. The objective's
     * "discovered|deferred|claimed" maps to the loop's real target lifecycle: a freshly-discovered target is
     * STATUS_CANDIDATE and a pulled one is STATUS_CLAIMED (atlas_loop_targets has no 'discovered'/'deferred'
     * status — they are accepted here as literals for forward-compat, but no row carries them today). A
     * proposed/quarantined/exhausted target is past the point a new handle should redirect it, so it is NOT
     * eligible.
     *
     * @var list<string>
     */
    private const ELIGIBLE_STATUSES = [
        AtlasLoopTarget::STATUS_CANDIDATE, // a freshly-DISCOVERED target
        AtlasLoopTarget::STATUS_CLAIMED,
        'discovered',
        'deferred',
    ];

    /** The operator-supplied failure-handle keys merged onto a matched target's signals (never fabricated). */
    private const FAILURE_KEYS = ['failure_test_path', 'failure_command', 'failing_assertion', 'failure_message'];

    public function __construct(private readonly ?AtlasLoopHarnessGuard $guard = null) {}

    /**
     * Merge operator-supplied external failure handles onto matching, already-existing campaign targets.
     *
     * @return array{stamped:int, source:string}
     */
    public function stamp(AtlasLoopCampaign $campaign): array
    {
        // Gate — default OFF. OFF ⇒ an early-return no-op (the refill envelope carries source:'disabled').
        if (! (bool) config('atlas.loop.bug_reproduction_external_handles_enabled', false)) {
            return ['stamped' => 0, 'source' => 'disabled'];
        }

        // The operator-configured artifact path (a JSON file dropped in storage/atlas-loop/). Absent default.
        $path = config('atlas.loop.bug_reproduction_external_handles_path', null);
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return ['stamped' => 0, 'source' => 'no_artifact'];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['stamped' => 0, 'source' => 'no_artifact'];
        }

        $entries = json_decode($raw, true);
        // The artifact MUST be a JSON list of entries. A scalar / object / invalid JSON is malformed.
        if (! is_array($entries) || ! array_is_list($entries)) {
            return ['stamped' => 0, 'source' => 'malformed'];
        }

        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        $stamped = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $targetPath = isset($entry['target_path']) && is_string($entry['target_path']) ? trim($entry['target_path']) : '';
            if ($targetPath === '') {
                continue;
            }

            // PÉTREO — never point the bug-fix lane at a forbidden self-target (the judge/guard/gate the loop
            // may not edit). A handle on a pétreo path is skipped, never stamped.
            if ($guard->isForbiddenSelfTarget($targetPath)) {
                continue;
            }

            // The operator-supplied handle — only the keys actually present + non-empty are merged. The
            // failure_test_path is the handle the bug lane consumes; without it there is nothing to stamp.
            $failure = [];
            foreach (self::FAILURE_KEYS as $key) {
                if (array_key_exists($key, $entry) && is_string($entry[$key]) && trim($entry[$key]) !== '') {
                    $failure[$key] = $entry[$key];
                }
            }
            if (! isset($failure['failure_test_path'])) {
                continue;
            }

            // Match an EXISTING in-flight target row in THIS campaign — never fabricate one.
            $target = AtlasLoopTarget::query()
                ->where('campaign_id', $campaign->id)
                ->where('target_path', $targetPath)
                ->whereIn('status', self::ELIGIBLE_STATUSES)
                ->first();
            if ($target === null) {
                continue; // not in the campaign (or not in-flight) ⇒ skip; NEVER insert
            }

            $signals = is_array($target->signals) ? $target->signals : [];
            $target->signals = array_merge($signals, $failure);
            $target->save();
            $stamped++;
        }

        return ['stamped' => $stamped, 'source' => 'stamped'];
    }
}
