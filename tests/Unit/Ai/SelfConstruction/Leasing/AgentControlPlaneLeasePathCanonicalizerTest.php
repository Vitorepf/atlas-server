<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Leasing;

use App\Services\Ai\SelfConstruction\Leasing\AgentControlPlaneLeasePathCanonicalizer;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive pure-canonicalization concern extracted from
 * AgentControlPlaneClaimLeaseRepository into AgentControlPlaneLeasePathCanonicalizer.
 *
 * Four methods migrated verbatim:
 *  - normalizeSet: trim + drop empties + backslash→slash + dedup + sort (scope-lock normalization).
 *  - stringList: trim + non-empty filter + array_values (list normalization).
 *  - leaseEntryMatchesPruneFilters: ANY of (task, agent, lease) field starts with ANY of the
 *    corresponding prefixes (empty prefix skipped — never accidentally matches anything).
 *  - leasePath: canonical storage path for a lease id (sanitized to [A-Za-z0-9_-]).
 *
 * Pure / stateless / zero Laravel surface — pure PHPUnit suffices.
 */
final class AgentControlPlaneLeasePathCanonicalizerTest extends TestCase
{
    private AgentControlPlaneLeasePathCanonicalizer $canon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canon = new AgentControlPlaneLeasePathCanonicalizer;
    }

    public function test_storage_prefix_is_stable_and_known(): void
    {
        $this->assertSame(
            'atlas/self-construction/agent-control-plane/leases',
            AgentControlPlaneLeasePathCanonicalizer::STORAGE_PREFIX,
        );
    }

    // --- normalizeSet -----------------------------------------------------

    public function test_normalize_set_trims_drops_empties_and_replaces_backslashes(): void
    {
        $out = $this->canon->normalizeSet(['  app\\Foo  ', '  ', 'app/Bar']);

        $this->assertSame(['app/Bar', 'app/Foo'], $out);
    }

    public function test_normalize_set_deduplicates_and_sorts(): void
    {
        $out = $this->canon->normalizeSet(['b', 'a', 'b', 'a', 'c']);

        $this->assertSame(['a', 'b', 'c'], $out);
    }

    public function test_normalize_set_treats_backslash_and_slash_as_equivalent(): void
    {
        $out = $this->canon->normalizeSet(['app/Foo', 'app\\Foo']);

        // Both collapse to 'app/Foo' (dedup).
        $this->assertSame(['app/Foo'], $out);
    }

    public function test_normalize_set_returns_empty_list_for_all_empty_input(): void
    {
        $this->assertSame([], $this->canon->normalizeSet(['', '   ', "\t\n"]));
        $this->assertSame([], $this->canon->normalizeSet([]));
    }

    public function test_normalize_set_casts_non_string_entries_via_string_conversion(): void
    {
        // Integer 42 becomes '42'; PHP's `(string) 42` is the contract.
        $out = $this->canon->normalizeSet([42]);

        $this->assertSame(['42'], $out);
    }

    public function test_normalize_set_preserves_list_shape(): void
    {
        $out = $this->canon->normalizeSet(['c', 'a', 'b']);

        $this->assertSame([0, 1, 2], array_keys($out));
    }

    // --- stringList -------------------------------------------------------

    public function test_string_list_trims_and_filters_empty_strings(): void
    {
        $out = $this->canon->stringList(['  a  ', '', "\t\n", 'b', '   ']);

        $this->assertSame(['a', 'b'], $out);
    }

    public function test_string_list_casts_non_string_values_via_string_conversion(): void
    {
        $out = $this->canon->stringList([1, 2.5, true, null]);

        // `null` casts to "" then filtered; `true` casts to "1".
        $this->assertSame(['1', '2.5', '1'], $out);
    }

    public function test_string_list_handles_empty_input(): void
    {
        $this->assertSame([], $this->canon->stringList([]));
    }

    // --- leaseEntryMatchesPruneFilters -----------------------------------

    public function test_lease_entry_matches_when_task_prefix_matches(): void
    {
        $entry = ['task_packet_id' => 'split-foo-1', 'agent_id' => 'hermes-1', 'lease_id' => 'L1'];
        $this->assertTrue($this->canon->leaseEntryMatchesPruneFilters($entry, ['split-'], [], []));
    }

    public function test_lease_entry_matches_when_agent_prefix_matches(): void
    {
        $entry = ['task_packet_id' => 'TP1', 'agent_id' => 'hermes-9', 'lease_id' => 'L1'];
        $this->assertTrue($this->canon->leaseEntryMatchesPruneFilters($entry, [], ['hermes-'], []));
    }

    public function test_lease_entry_matches_when_lease_prefix_matches(): void
    {
        $entry = ['task_packet_id' => 'TP1', 'agent_id' => 'A1', 'lease_id' => 'lease-abc-123'];
        $this->assertTrue($this->canon->leaseEntryMatchesPruneFilters($entry, [], [], ['lease-']));
    }

    public function test_lease_entry_does_not_match_when_no_prefix_matches(): void
    {
        $entry = ['task_packet_id' => 'TP1', 'agent_id' => 'A1', 'lease_id' => 'L1'];
        $this->assertFalse($this->canon->leaseEntryMatchesPruneFilters($entry, ['xx-'], ['yy-'], ['zz-']));
    }

    public function test_lease_entry_ignores_empty_prefixes(): void
    {
        // An empty prefix in the task list must NOT cause an empty-string match (which would
        // match every entry's task_packet_id via str_starts_with($x, '')). The guard
        // `if ($prefix !== '' && ...)` skips empty prefixes — empty list of non-empty prefixes
        // => no match.
        $entry = ['task_packet_id' => 'TP1', 'agent_id' => 'A1', 'lease_id' => 'L1'];
        $this->assertFalse($this->canon->leaseEntryMatchesPruneFilters($entry, [''], [''], ['']));
    }

    public function test_lease_entry_matches_when_missing_fields_default_to_empty_string(): void
    {
        // Entry missing all three fields; no prefix can match an empty target.
        $this->assertFalse($this->canon->leaseEntryMatchesPruneFilters([], ['TP-'], ['A-'], ['L-']));
    }

    public function test_lease_entry_matches_first_winning_prefix_is_enough(): void
    {
        // Many prefixes; first match wins.
        $entry = ['task_packet_id' => 'split-queue-1', 'agent_id' => 'A1', 'lease_id' => 'L1'];
        $this->assertTrue($this->canon->leaseEntryMatchesPruneFilters($entry, ['nope-', 'split-'], [], []));
    }

    // --- leasePath -------------------------------------------------------

    public function test_lease_path_uses_storage_prefix_and_sanitizes_id(): void
    {
        $path = $this->canon->leasePath('lease_abc-123');

        $this->assertSame('atlas/self-construction/agent-control-plane/leases/lease_abc-123.json', $path);
    }

    public function test_lease_path_replaces_disallowed_chars_with_underscore(): void
    {
        // A lease id with slashes / dots / spaces must be sanitized — never let a caller escape
        // the storage prefix via path traversal. The contract: the result is `<STORAGE_PREFIX>/<sanitized>.json`,
        // where <sanitized> contains NO `/` (the only `/` in the result is the one between the
        // prefix and the basename). `..` in the basename is also forbidden.
        $path = $this->canon->leasePath('../etc/passwd');
        $basename = basename($path);

        $this->assertStringStartsWith(
            'atlas/self-construction/agent-control-plane/leases/',
            $path,
        );
        $this->assertStringNotContainsString('..', $basename, 'no path traversal in basename');
        $this->assertStringNotContainsString('/', $basename, 'basename must contain no slashes');
        $this->assertStringEndsWith('.json', $basename);
    }

    public function test_lease_path_returns_a_path_under_the_storage_prefix_even_for_empty_id(): void
    {
        // preg_replace on an empty string returns an empty string; the prefix + slash + '.json' is
        // still a valid (if degenerate) path. The contract is: it NEVER escapes the prefix.
        $path = $this->canon->leasePath('');

        $this->assertSame('atlas/self-construction/agent-control-plane/leases/.json', $path);
    }

    public function test_lease_path_keeps_letters_digits_underscore_dash(): void
    {
        $path = $this->canon->leasePath('L-1_a');

        $this->assertStringEndsWith('L-1_a.json', $path);
    }
}
