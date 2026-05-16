<?php

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Requests\AtlasDev\PlanRequest;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use Illuminate\Http\Request;

/**
 * Contract assertions for the PlanRequest payload boundary, mirroring what
 * Desktop sends through `atlasDevPlanHttpBody.ts`.
 *
 * The Desktop helper guarantees:
 *   - `policy_hints` is always TOP-LEVEL (driven by `request.decision_mode`).
 *   - `surface_context.composer_mode` defaults to `programming`.
 *   - A stray `surface_context.policy_hints` is allowed to ride along but
 *     MUST NOT be promoted to the top level by the backend either.
 *
 * These tests pin down both halves of the contract on the server side.
 */
final class PlanRequestPolicyHintsTest extends AtlasDevHttpTestCase
{
    public function test_top_level_policy_hints_is_honored_as_surface_hint(): void
    {
        $request = $this->makePlanRequest([
            'surface_id' => 'atlas_desktop_ai',
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'fix something',
            'policy_hints' => ['decision_mode' => 'atlas_decide', 'cost_budget' => 'low'],
            'surface_context' => ['composer_mode' => 'programming'],
        ]);

        $hints = $request->surfaceHints();
        $this->assertArrayHasKey('policy_hints', $hints);
        $this->assertSame('atlas_decide', $hints['policy_hints']['decision_mode']);
        $this->assertSame('low', $hints['policy_hints']['cost_budget']);
        $this->assertSame('programming', $hints['composer_mode']);
    }

    public function test_policy_hints_nested_inside_surface_context_is_NOT_promoted_to_top_level(): void
    {
        $request = $this->makePlanRequest([
            'surface_id' => 'atlas_desktop_ai',
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'fix something',
            'surface_context' => [
                'composer_mode' => 'programming',
                // Attempted smuggle: nested policy_hints inside surface_context.
                'policy_hints' => ['decision_mode' => 'manual_override', 'allow_dangerous' => true],
            ],
        ]);

        $hints = $request->surfaceHints();
        $this->assertArrayNotHasKey('policy_hints', $hints, 'Nested policy_hints must not leak to surface hints.');
    }

    public function test_composer_mode_programming_propagates_into_persisted_envelope(): void
    {
        $payload = $this->defaultRepairPayload();
        $payload['surface_context'] = ['composer_mode' => 'programming', 'composer_task' => 'dev'];

        $data = $this->postPlan($payload);

        $storage = $this->app->make(ReceiptStorage::class);
        $envelope = $storage->read($data['run_id'], ArtifactNames::OPERATION_ENVELOPE);
        $this->assertIsArray($envelope);
        $this->assertIsArray($envelope['surface_context']);
        $this->assertSame('programming', $envelope['surface_context']['composer_mode']);
        $this->assertSame('dev', $envelope['surface_context']['composer_task']);
    }

    public function test_input_text_is_ignored_when_raw_intent_present(): void
    {
        // Desktop helper never sends input_text — but if a legacy client does,
        // the backend's PlanRequest validator simply ignores it because
        // rules() doesn't declare it. raw_intent is the only authority.
        $request = $this->makePlanRequest([
            'surface_id' => 'atlas_desktop_ai',
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'canon intent',
            'input_text' => 'ghost intent',
        ]);
        $this->assertTrue($request->authorize());
        $this->assertSame('canon intent', $request->input('raw_intent'));
        // The backend validator does not declare input_text in its rules, so
        // even if a legacy client sends it the validator simply ignores it;
        // the only authority is raw_intent.
        $this->assertArrayNotHasKey('input_text', $request->validated());
    }

    public function test_user_constraints_top_level_array_round_trips_into_hints(): void
    {
        $request = $this->makePlanRequest([
            'surface_id' => 'atlas_desktop_ai',
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'fix',
            'user_constraints' => ['no_db_writes', 'max_1_file'],
        ]);

        $this->assertSame(['no_db_writes', 'max_1_file'], $request->userConstraintsList());
    }

    /**
     * Build a validated PlanRequest with the provided body, simulating a real
     * POST without going through the HTTP kernel. Mirrors how Laravel binds
     * the FormRequest in the container.
     */
    private function makePlanRequest(array $body): PlanRequest
    {
        $request = PlanRequest::create('/ai/interactions/atlas-dev/plan', 'POST', $body);
        // FormRequest needs a container reference to resolve validator deps.
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(\Illuminate\Routing\Redirector::class));
        $request->validateResolved();

        return $request;
    }
}
