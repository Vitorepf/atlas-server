<?php

namespace Tests\Unit\Console;

use Tests\TestCase;

/**
 * AP-814 · M-8 Fase-3 — guard for the atlas:cross-domain:analytics command.
 *
 * We assert ONLY the deterministic, IO-free branches: the flag-OFF 'disabled'
 * SUCCESS and the invalid --op rejection. Both short-circuit before any DB read
 * or python invoke, so no tables are booted here. The flag-ON real run (which
 * crosses the governed venv boundary) is proven live separately — the venv may
 * be absent in CI, so this test never asserts python actually ran.
 */
class AtlasCrossDomainAnalyticsCommandTest extends TestCase
{
    public function test_reports_disabled_and_succeeds_when_flag_off(): void
    {
        config()->set('atlas.cross_domain_graph.enabled', false);

        $this->artisan('atlas:cross-domain:analytics')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);
    }

    public function test_disabled_json_payload_carries_the_requested_op_and_succeeds(): void
    {
        config()->set('atlas.cross_domain_graph.enabled', false);

        // The --op passthrough proves arg wiring; the disabled status is covered above.
        // (expectsOutputToContain matches one line, so we assert a single line here.)
        $this->artisan('atlas:cross-domain:analytics --json --op=communities')
            ->expectsOutputToContain('"op": "communities"')
            ->assertExitCode(0);
    }

    public function test_rejects_an_unsupported_op_before_touching_anything(): void
    {
        // Even with the flag ON, an unknown op is rejected up-front (no DB, no invoke).
        config()->set('atlas.cross_domain_graph.enabled', true);

        $this->artisan('atlas:cross-domain:analytics --op=pagerank')
            ->expectsOutputToContain('Unsupported')
            ->assertExitCode(1);
    }
}
