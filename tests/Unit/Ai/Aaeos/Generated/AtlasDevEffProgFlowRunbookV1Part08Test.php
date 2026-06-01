<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEffProgFlowRunbookV1Part08Service;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev efficient programming flow runbook rules
 * (Parte 8 · §12.2 escalation target mapping, §15.1.5 Run-intake error
 * precedence, §15.1.4 confirmation-token verdict, §15.1.3 stage gate,
 * §15.1.2 APP_KEY floor, §15.1.7 path redaction, §15.1.9 flow identity).
 *
 * Pure, deterministic, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-08.md
 */
class AtlasDevEffProgFlowRunbookV1Part08Test extends TestCase
{
    private function service(): AtlasDevEffProgFlowRunbookV1Part08Service
    {
        return new AtlasDevEffProgFlowRunbookV1Part08Service();
    }

    /**
     * §12.2 PR 5.1 step 3-4 — score >= 7 OR risk >= R4 forces forge (with human
     * action required, never auto-creating an Obra); 4 <= score < 7 is the
     * obra_candidate band; score < 4 below R4 yields no escalation.
     */
    public function test_escalation_target_thresholds(): void
    {
        $s = $this->service();

        // score >= 7 -> forge, human action required, no auto-Obra.
        $forgeByScore = $s->decideEscalationTarget(8, 'R2');
        $this->assertSame($s::TARGET_FORGE, $forgeByScore['target']);
        $this->assertTrue($forgeByScore['escalated']);
        $this->assertTrue($forgeByScore['human_action_required']);
        $this->assertFalse($forgeByScore['auto_creates_obra']);

        // low score but R4 still forces forge (risk_level >= R4 branch).
        $forgeByRisk = $s->decideEscalationTarget(2, 'R4');
        $this->assertSame($s::TARGET_FORGE, $forgeByRisk['target']);
        $this->assertTrue($forgeByRisk['force_forge']);
        $this->assertTrue($forgeByRisk['human_action_required']);

        // 4..6 band -> obra_candidate, human action NOT required.
        $obra = $s->decideEscalationTarget(5, 'R2');
        $this->assertSame($s::TARGET_OBRA_CANDIDATE, $obra['target']);
        $this->assertTrue($obra['escalated']);
        $this->assertFalse($obra['human_action_required']);

        // boundary: exactly 7 is still forge; exactly 4 is obra; exactly 3 is none.
        $this->assertSame($s::TARGET_FORGE, $s->decideEscalationTarget(7, 'R0')['target']);
        $this->assertSame($s::TARGET_OBRA_CANDIDATE, $s->decideEscalationTarget(4, 'R0')['target']);

        // score < 4 and risk < R4 -> no escalation.
        $none = $s->decideEscalationTarget(3, 'R1');
        $this->assertSame($s::TARGET_NONE, $none['target']);
        $this->assertFalse($none['escalated']);
    }

    /**
     * §15.1.5 — Run-intake error precedence (first failure wins):
     * operator_confirmed (400) > task_contract_hash (422) > confirmation_token (403).
     * operator_confirmed must be the STRICT boolean true: "true" string and 1
     * are both rejected as OPERATOR_NOT_CONFIRMED.
     */
    public function test_run_intake_error_precedence(): void
    {
        $s = $this->service();

        // truthy-string "true" -> still not confirmed.
        $notConfirmed = $s->validateRunIntake(['operator_confirmed' => 'true']);
        $this->assertFalse($notConfirmed['accepted']);
        $this->assertSame($s::ERR_OPERATOR_NOT_CONFIRMED, $notConfirmed['error']);
        $this->assertSame(400, $notConfirmed['http_status']);

        // integer 1 -> still not confirmed.
        $this->assertSame(
            $s::ERR_OPERATOR_NOT_CONFIRMED,
            $s->validateRunIntake(['operator_confirmed' => 1])['error'],
        );

        // operator confirmed but hash mismatches -> 422, and this outranks a
        // (separately) bad token, proving precedence.
        $hashMismatch = $s->validateRunIntake(
            ['operator_confirmed' => true, 'task_contract_hash' => 'wrong', 'confirmation_token' => ''],
            ['task_contract_hash' => 'abc', 'token_present' => false],
        );
        $this->assertSame($s::ERR_TASK_CONTRACT_HASH_MISMATCH, $hashMismatch['error']);
        $this->assertSame(422, $hashMismatch['http_status']);

        // confirmed + matching hash + missing token -> 403 missing token.
        $missingToken = $s->validateRunIntake(
            ['operator_confirmed' => true, 'task_contract_hash' => 'abc'],
            ['task_contract_hash' => 'abc', 'token_present' => false],
        );
        $this->assertSame($s::ERR_CONFIRMATION_TOKEN_MISSING, $missingToken['error']);
        $this->assertSame(403, $missingToken['http_status']);

        // a consumed token surfaces the canonical ALREADY_CONSUMED 403.
        $consumed = $s->validateRunIntake(
            ['operator_confirmed' => true, 'task_contract_hash' => 'abc', 'confirmation_token' => 'tok'],
            ['task_contract_hash' => 'abc', 'token_present' => true, 'token_status' => 'consumed'],
        );
        $this->assertSame($s::ERR_CONFIRMATION_TOKEN_ALREADY_CONSUMED, $consumed['error']);

        // fully valid intake is accepted.
        $ok = $s->validateRunIntake(
            ['operator_confirmed' => true, 'task_contract_hash' => 'abc', 'confirmation_token' => 'tok'],
            ['task_contract_hash' => 'abc', 'token_present' => true, 'token_status' => 'valid'],
        );
        $this->assertTrue($ok['accepted']);
        $this->assertNull($ok['error']);
    }

    /**
     * §15.1.4 — confirmation token: valid only within TTL (default 300s), with a
     * matching binding and not yet consumed. Consumption beats expiry.
     */
    public function test_confirmation_token_verdict(): void
    {
        $s = $this->service();

        $valid = $s->evaluateConfirmationToken(120, true, false);
        $this->assertTrue($valid['valid']);
        $this->assertSame('valid', $valid['token_status']);
        $this->assertSame($s::CONFIRMATION_TOKEN_TTL_SECONDS_DEFAULT, $valid['ttl_seconds']);

        // age > default TTL -> expired.
        $expired = $s->evaluateConfirmationToken(301, true, false);
        $this->assertFalse($expired['valid']);
        $this->assertSame('expired', $expired['token_status']);

        // consumed AND stale -> reported as consumed (single-use atomic mark wins).
        $consumed = $s->evaluateConfirmationToken(9_999, true, true);
        $this->assertSame('consumed', $consumed['token_status']);

        // binding mismatch -> contract_mismatch.
        $this->assertSame('contract_mismatch', $s->evaluateConfirmationToken(10, false, false)['token_status']);
    }

    /**
     * §15.1.3 — Run requires Plan green first; a disabled flag returns the
     * canonical 503 envelope with no side effects.
     */
    public function test_stage_gate_ordering(): void
    {
        $s = $this->service();

        // Run requested while Plan disabled -> RUN_DISABLED, no side effects.
        $runNoPlan = $s->resolveStageGate($s::STAGE_RUN, false, true);
        $this->assertFalse($runNoPlan['enabled']);
        $this->assertSame($s::ERR_RUN_DISABLED, $runNoPlan['error']);
        $this->assertSame(503, $runNoPlan['http_status']);
        $this->assertFalse($runNoPlan['side_effects']);
        $this->assertSame('run_requires_plan_first', $runNoPlan['reason']);

        // Plan can be enabled standalone (zero-provider, safe first).
        $plan = $s->resolveStageGate($s::STAGE_PLAN, true, false);
        $this->assertTrue($plan['enabled']);
        $this->assertNull($plan['error']);

        // Plan disabled -> PLAN_DISABLED 503.
        $planOff = $s->resolveStageGate($s::STAGE_PLAN, false, false);
        $this->assertSame($s::ERR_PLAN_DISABLED, $planOff['error']);

        // Run enabled only when both flags on.
        $this->assertTrue($s->resolveStageGate($s::STAGE_RUN, true, true)['enabled']);
    }

    /**
     * §15.1.2 — APP_KEY must decode to >= 32 bytes, else fail-closed
     * ATLAS_DEV_KEY_MISSING (500). §15.1.7 (F-04) — responses redact absolute
     * paths to a basename label + relative receipts refs.
     */
    public function test_app_key_floor_and_path_redaction(): void
    {
        $s = $this->service();

        // 5-byte "short" decoded -> below floor -> key missing.
        $short = $s->evaluateAppKey('base64:'.base64_encode('short'));
        $this->assertFalse($short['ok']);
        $this->assertSame($s::ERR_KEY_MISSING, $short['error']);
        $this->assertSame(500, $short['http_status']);

        // a full 32-byte key passes.
        $okKey = $s->evaluateAppKey('base64:'.base64_encode(str_repeat('k', 32)));
        $this->assertTrue($okKey['ok']);
        $this->assertSame(32, $okKey['decoded_bytes']);

        // redaction: absolute workspace -> basename label, absolute artifact ->
        // relative receipts/<run_id>/<file>, and no absolute leak reported.
        $red = $s->redactHttpResponse(
            '/Users/op/develop/Atlas/atlas-server',
            'run-123',
            ['/Users/op/develop/Atlas/atlas-server/storage/atlas-dev/receipts/run-123/verification.json'],
            'wsh_abc123',
        );
        $this->assertSame('atlas-server', $red['workspace_label']);
        $this->assertSame(['receipts/run-123/verification.json'], $red['persisted_artifact_refs']);
        $this->assertSame('wsh_abc123', $red['workspace_hash']);
        $this->assertFalse($red['absolute_path_leak']);
        $this->assertSame([], $red['leaked']);
    }

    /**
     * §15.1.9 — flow_id must be exactly atlas_dev; flow_origin must be one of the
     * two accepted origins; command_intent is optional.
     */
    public function test_flow_identity_invariants(): void
    {
        $s = $this->service();

        $canonical = $s->validateFlowIdentity(
            ['flow_id' => 'atlas_dev', 'flow_origin' => 'atlas_ai_router', 'command_intent' => 'patch'],
        );
        $this->assertTrue($canonical['canonical']);
        $this->assertSame('patch', $canonical['command_intent']);

        // direct origin is also canonical; command_intent optional (absent).
        $direct = $s->validateFlowIdentity(['flow_id' => 'atlas_dev', 'flow_origin' => 'direct']);
        $this->assertTrue($direct['canonical']);
        $this->assertNull($direct['command_intent']);

        // unknown origin -> violation.
        $badOrigin = $s->validateFlowIdentity(['flow_id' => 'atlas_dev', 'flow_origin' => 'somewhere_else']);
        $this->assertFalse($badOrigin['canonical']);
        $this->assertContains('flow_origin_not_allowed', $badOrigin['violations']);

        // wrong flow_id -> violation.
        $badFlow = $s->validateFlowIdentity(['flow_id' => 'other_flow', 'flow_origin' => 'direct']);
        $this->assertFalse($badFlow['canonical']);
        $this->assertContains('flow_id_must_be_atlas_dev', $badFlow['violations']);
    }
}
