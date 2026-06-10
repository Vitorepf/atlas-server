<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Tests\TestCase;

/**
 * AOBG N1.F5 — contract for {@see CodeGraphWorkspaceIdentity::resolveWorkspaceOrId()},
 * the "path OR id" resolver the gateway surfaces (context-pack / write-back /
 * workspace-status) document and the operator + hook rely on.
 *
 * The bug it closes: `resolve()` treats its argument strictly as a PATH (realpath →
 * derive), so feeding back a previously-resolved id (e.g. 'atlas-server' or a
 * 'pkg-1a2b3c4d' derived id) hashed it into a NEW, empty workspace id — the
 * code-graph query then scoped to a graph with zero symbols. resolveWorkspaceOrId
 * passes a stable id through verbatim while still resolving real paths.
 *
 * Pure (no DB, no clock): identity is a deterministic string transform.
 */
class CodeGraphWorkspaceIdentityResolveOrIdTest extends TestCase
{
    private function identity(): CodeGraphWorkspaceIdentity
    {
        return new CodeGraphWorkspaceIdentity;
    }

    public function test_a_stable_id_is_passed_through_verbatim_not_re_derived(): void
    {
        $identity = $this->identity();

        // A bare, already-normalized id round-trips to ITSELF (the regression: it used to
        // become "<id>-<hash>" because realpath failed and it fell through to derive()).
        $this->assertSame('gold-dbfc1eec', $identity->resolveWorkspaceOrId('gold-dbfc1eec'));
        $this->assertSame('owner-repo', $identity->resolveWorkspaceOrId('owner-repo'));

        // A derived-shape id ("base-8hexchars") is also a stable id → passthrough.
        $this->assertSame('myproj-1a2b3c4d', $identity->resolveWorkspaceOrId('myproj-1a2b3c4d'));

        // A monorepo "base::sub" id is preserved through the sub() rule.
        $this->assertSame('owner-repo::packages-api', $identity->resolveWorkspaceOrId('owner-repo::packages-api'));
    }

    public function test_the_configured_default_id_round_trips_to_the_default(): void
    {
        $identity = $this->identity();

        // Feeding the primary id back resolves to the canonical default (not a hashed copy).
        $this->assertSame($identity->default(), $identity->resolveWorkspaceOrId($identity->default()));
        $this->assertSame('atlas-server', $identity->resolveWorkspaceOrId('atlas-server'));
    }

    public function test_a_clean_id_token_is_normalized_to_match_a_stored_id(): void
    {
        $identity = $this->identity();

        // A clean id token in mixed case normalizes to the same lowercase token a
        // stored/derived id uses, so the caller still scopes to the right graph.
        $this->assertSame('owner-repo', $identity->resolveWorkspaceOrId('OWNER-REPO'));
        $this->assertSame('myproj.api', $identity->resolveWorkspaceOrId('MyProj.Api'));

        // A value with a SPACE is neither a clean id token nor a path — conservatively it
        // is NOT treated as a passthrough id; it falls to resolve()'s derivation unchanged
        // (so a real stored id, which never contains spaces, can never be shadowed).
        $this->assertSame($identity->resolve('Owner Repo'), $identity->resolveWorkspaceOrId('Owner Repo'));
    }

    public function test_an_existing_path_is_resolved_as_a_path_not_treated_as_an_id(): void
    {
        $identity = $this->identity();

        // The running app's base_path() is the primary workspace → the default id.
        $this->assertSame($identity->default(), $identity->resolveWorkspaceOrId(base_path()));

        // An existing NON-primary dir resolves via the path branch (a derived id), exactly
        // as resolve() would — proving an id-shaped basename does not short-circuit a real
        // path. Use the system temp dir (always exists, never the primary).
        $tmp = sys_get_temp_dir();
        $this->assertSame($identity->resolve($tmp), $identity->resolveWorkspaceOrId($tmp));
    }

    public function test_empty_or_null_input_resolves_to_the_default(): void
    {
        $identity = $this->identity();

        $this->assertSame($identity->default(), $identity->resolveWorkspaceOrId(null));
        $this->assertSame($identity->default(), $identity->resolveWorkspaceOrId(''));
        $this->assertSame($identity->default(), $identity->resolveWorkspaceOrId('   '));
    }

    public function test_a_path_shaped_but_nonexistent_value_keeps_resolve_derivation(): void
    {
        $identity = $this->identity();

        // A value with a separator that is NOT an existing path is NOT an id — it falls back
        // to resolve() so its derivation is unchanged (no accidental passthrough of a path).
        $value = '/nonexistent/project/'.uniqid('aobg', true);
        $this->assertSame($identity->resolve($value), $identity->resolveWorkspaceOrId($value));
    }
}
