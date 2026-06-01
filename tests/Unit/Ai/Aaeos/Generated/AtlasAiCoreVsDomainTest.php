<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiCoreVsDomainService;
use Tests\TestCase;

/**
 * Pins the decidable contracts of the founding "Atlas AI Core Vs Domain" doc:
 * the Regra Rapida 3-way placement (multi-surface/domain -> Core; vertical
 * criterion -> Domain; pure IO -> Surface), the Teste De Decisao Q6 escalation
 * (another command would copy -> Core), the Profile/model inversion guard, the
 * `atlas dev`/`atlas fix` flow mapping, the domain status list (marketing is
 * scaffold, never ready), the blackink-is-a-business-context rule, and the
 * Surface + Domain prohibition audits. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
 */
class AtlasAiCoreVsDomainTest extends TestCase
{
    private function service(): AtlasAiCoreVsDomainService
    {
        return new AtlasAiCoreVsDomainService;
    }

    public function test_regra_rapida_places_capability_in_core_domain_or_surface(): void
    {
        $service = $this->service();

        // Line 1: serves more than one domain OR surface -> Core.
        $core = $service->placeCapability(['serves_multiple_surfaces' => true]);
        $this->assertSame('core', $core['layer']);
        $this->assertSame('regra_rapida_serves_more_than_one_domain_or_surface', $core['reason']);

        $coreByCount = $service->placeCapability(['domain_count' => 3, 'surface_count' => 1]);
        $this->assertSame('core', $coreByCount['layer']);

        // Line 2: bound to a single vertical's specialized criteria -> Domain.
        $domain = $service->placeCapability([
            'depends_on_vertical_criteria' => true,
            'vertical' => 'finance',
        ]);
        $this->assertSame('domain', $domain['layer']);
        $this->assertSame('finance', $domain['detail']['vertical']);

        // Line 3: only collects input or renders output -> Surface.
        $surface = $service->placeCapability(['only_collects_input_or_renders_output' => true]);
        $this->assertSame('surface', $surface['layer']);
    }

    public function test_teste_de_decisao_q6_escalates_to_core_when_another_command_would_copy(): void
    {
        $service = $this->service();

        // Even a pure-IO capability escalates to Core when a second command would
        // have to copy it (doc Teste De Decisao, question 6).
        $decision = $service->placeCapability([
            'only_collects_input_or_renders_output' => true,
            'another_command_would_copy' => true,
        ]);

        $this->assertSame('core', $decision['layer']);
        $this->assertSame('teste_de_decisao_q6_another_command_would_copy', $decision['reason']);
    }

    public function test_model_profile_must_never_define_a_flow(): void
    {
        $service = $this->service();

        // doc Profile rule: opus/codex-high/gemini-scout are model profiles.
        $this->assertTrue($service->isModelProfile('opus'));
        $this->assertTrue($service->isModelProfile('codex-high'));
        $this->assertFalse($service->isModelProfile('programming.dev'));

        $denied = $service->assertModelDoesNotDefineFlow('codex-high');
        $this->assertFalse($denied['allowed_as_flow']);
        $this->assertSame('a_model_profile_must_never_define_a_flow_profile', $denied['reason']);

        $allowed = $service->assertModelDoesNotDefineFlow('programming.forge');
        $this->assertTrue($allowed['allowed_as_flow']);
    }

    public function test_command_flow_mapping_and_fix_risk_routing(): void
    {
        $service = $this->service();

        // doc: atlas dev -> programming.dev; atlas forge -> programming.forge.
        $this->assertSame('programming.dev', $service->resolveFlowForCommand('atlas dev')['flow_profile']);
        $this->assertSame('programming.forge', $service->resolveFlowForCommand('atlas forge')['flow_profile']);

        // doc: atlas fix -> dev or forge with intent repair, depending on risk.
        $lowFix = $service->resolveFlowForCommand('atlas fix', 'low');
        $this->assertSame('programming.dev', $lowFix['flow_profile']);
        $this->assertSame('repair', $lowFix['intent']);

        $highFix = $service->resolveFlowForCommand('atlas fix', 'high');
        $this->assertSame('programming.forge', $highFix['flow_profile']);
        $this->assertSame('repair', $highFix['intent']);
    }

    public function test_domain_status_and_business_context_rules(): void
    {
        $service = $this->service();

        // doc Status operacional atual: these four are implemented/ready.
        $this->assertTrue($service->domainStatus('programming')['ready']);
        $this->assertTrue($service->domainStatus('finance')['ready']);
        $this->assertTrue($service->domainStatus('self_improvement')['ready']);

        // doc: marketing must NOT be treated as implemented/ready.
        $marketing = $service->domainStatus('marketing');
        $this->assertFalse($marketing['ready']);
        $this->assertSame('scaffold_catalog_ready', $marketing['status']);

        // doc: blackink is a Business Context, not an Atlas AI domain.
        $blackink = $service->classifyContextOrDomain('blackink');
        $this->assertFalse($blackink['is_atlas_ai_domain']);
        $this->assertSame('business_context', $blackink['kind']);
    }

    public function test_surface_and_domain_prohibitions(): void
    {
        $service = $this->service();

        // doc: Surface must not choose provider outside policy.
        $surface = $service->auditSurfaceAction('choose_provider');
        $this->assertFalse($surface['allowed']);
        $this->assertSame('Atlas.Policy', $surface['correct_owner']);

        // doc: Surface MAY collect input / render output.
        $this->assertTrue($service->auditSurfaceAction('collect_input')['allowed']);

        // doc: Domain must not reimplement provider routing / memory core etc.
        $domain = $service->auditDomainOwnership('provider_routing');
        $this->assertFalse($domain['domain_may_own']);
        $this->assertSame('core', $domain['owner_layer']);

        // doc: a Domain MAY own its own intent classifier.
        $this->assertTrue($service->auditDomainOwnership('intent_classifier')['domain_may_own']);
    }
}
