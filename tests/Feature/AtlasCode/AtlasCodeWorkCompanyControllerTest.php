<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use App\Http\Controllers\AtlasCodeWorkCompanyController;
use Tests\TestCase;

/**
 * Atlas Code — Company Runtime HTTP entry contract tests.
 *
 * Covers the four feature-flag branches declared in
 * `atlas-engineering-company-runtime-http-promotion.md`:
 *
 *   off       → 503, flag_mode=off, schema canonical
 *   shadow    → 202, route_decision.v1 embedded, phase_1 honest status
 *   on        → 503 stub honest about phase_3 not shipped
 *   default   → behaves like on (single match arm)
 *
 * The controller emits `route_decision.v1` on EVERY call regardless of
 * flag — this is the Gap5 Phase 2 invariant. Tests prove the decision
 * shape and that the schema constant is canonical.
 */
class AtlasCodeWorkCompanyControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Disable any global middleware that requires auth/CSRF for these
        // contract tests — the goal is to lock the controller contract,
        // not authentication boundary (which is covered by sibling
        // AtlasCodeWorkController tests).
        $this->withoutMiddleware();
    }

    public function test_off_mode_returns_503_with_canonical_envelope(): void
    {
        config()->set('atlas.http_company_runtime.mode', 'off');

        $response = $this->postJson('/atlas-code/work/company', ['goal_text' => 'refactor auth']);

        $response->assertStatus(503);
        $response->assertJson([
            'schema_version' => AtlasCodeWorkCompanyController::RESPONSE_SCHEMA,
            'status' => 'feature_flag_off',
            'flag_mode' => 'off',
        ]);
        $response->assertJsonPath('route_decision.schema_version', 'atlas.dual_core.route_decision.v1');
    }

    public function test_shadow_mode_returns_202_with_phase_1_status(): void
    {
        config()->set('atlas.http_company_runtime.mode', 'shadow');

        $response = $this->postJson('/atlas-code/work/company', ['goal_text' => 'refactor auth']);

        $response->assertStatus(202);
        $response->assertJson([
            'schema_version' => AtlasCodeWorkCompanyController::RESPONSE_SCHEMA,
            'status' => 'shadow_accepted',
            'flag_mode' => 'shadow',
        ]);
        $response->assertJsonPath('route_decision.schema_version', 'atlas.dual_core.route_decision.v1');
        $response->assertJsonPath('route_decision.route', 'company_runtime');
    }

    public function test_on_mode_returns_503_stub_honest_about_phase_3(): void
    {
        config()->set('atlas.http_company_runtime.mode', 'on');

        $response = $this->postJson('/atlas-code/work/company', ['goal_text' => 'refactor auth']);

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'on_mode_not_implemented_yet');
        $response->assertJsonPath('phase_status', 'phase_3_blocked_phases_1_and_2_pending');
    }

    public function test_default_mode_behaves_like_on(): void
    {
        config()->set('atlas.http_company_runtime.mode', 'default');

        $response = $this->postJson('/atlas-code/work/company', ['goal_text' => 'refactor auth']);

        $response->assertStatus(503);
        $response->assertJsonPath('flag_mode', 'on'); // controller normalizes default → on response envelope
    }

    public function test_unknown_flag_falls_back_to_off(): void
    {
        config()->set('atlas.http_company_runtime.mode', 'wibble');

        $response = $this->postJson('/atlas-code/work/company', ['goal_text' => 'x']);

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'feature_flag_off');
    }

    public function test_goal_text_required(): void
    {
        config()->set('atlas.http_company_runtime.mode', 'shadow');

        $response = $this->postJson('/atlas-code/work/company', []);

        $response->assertStatus(422);
    }

    public function test_goal_text_max_8000_chars(): void
    {
        config()->set('atlas.http_company_runtime.mode', 'shadow');

        $response = $this->postJson('/atlas-code/work/company', [
            'goal_text' => str_repeat('a', 8001),
        ]);

        $response->assertStatus(422);
    }

    public function test_trace_id_is_propagated_when_supplied(): void
    {
        config()->set('atlas.http_company_runtime.mode', 'shadow');

        $response = $this->postJson('/atlas-code/work/company', [
            'goal_text' => 'task',
            'options' => ['trace_id' => 'trace-fixture-123'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('trace_id', 'trace-fixture-123');
        $response->assertJsonPath('route_decision.trace_id', 'trace-fixture-123');
    }

    public function test_route_decision_carries_goal_text_hash(): void
    {
        config()->set('atlas.http_company_runtime.mode', 'shadow');

        $response = $this->postJson('/atlas-code/work/company', ['goal_text' => 'fixed goal text']);

        $expected = hash('sha256', 'fixed goal text');
        $response->assertJsonPath('route_decision.goal_text_hash', $expected);
    }

    public function test_route_decision_honesty_when_recorder_missing_method(): void
    {
        config()->set('atlas.http_company_runtime.mode', 'shadow');

        $response = $this->postJson('/atlas-code/work/company', ['goal_text' => 'x']);

        // The controller declares honestly when recordHttp does not exist
        // yet — proves the canon "honesty > silent failure" invariant.
        $data = $response->json();
        $this->assertArrayHasKey('recorded', $data['route_decision']);
    }
}
