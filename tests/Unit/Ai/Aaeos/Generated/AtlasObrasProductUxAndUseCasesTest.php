<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasObrasProductUxAndUseCasesService;
use Tests\TestCase;

/**
 * Pins the documented Obras product-UX surfaces: the Note/Task/Project/Obra
 * separation, the 13-field Obra card, the 12 workspace sections, the AI-session
 * "produce or improve" rule and the quick-action surfacing rule.
 *
 * @see docs/engineering-knowledge-base/obras/product-ux-and-use-cases.md
 */
class AtlasObrasProductUxAndUseCasesTest extends TestCase
{
    private function service(): AtlasObrasProductUxAndUseCasesService
    {
        return new AtlasObrasProductUxAndUseCasesService();
    }

    /**
     * The doc fixes exactly four entities, each answering one question. An item
     * that builds a relevant artifact until ready classifies as an Obra.
     */
    public function test_entity_classification_maps_obra_to_artifact_question(): void
    {
        $this->assertCount(4, AtlasObrasProductUxAndUseCasesService::ENTITY_QUESTIONS);

        $report = $this->service()->classifyEntity(['builds_artifact_until_ready' => true]);

        $this->assertSame('pass', $report['status']);
        $this->assertSame('obra', $report['entity']);
        $this->assertSame(
            'Which relevant artifact am I building until it is ready?',
            $report['question'],
        );
    }

    /**
     * Documented decision: "Obras must not be confused with notes, tasks or
     * simple projects." An item flagged as both an Obra and a plain project is
     * rejected as a confusion, not silently accepted.
     */
    public function test_obra_must_not_be_confused_with_a_simple_project(): void
    {
        $report = $this->service()->classifyEntity([
            'builds_artifact_until_ready' => true,
            'is_set_of_actions' => true,
        ]);

        $this->assertSame('fail', $report['status']);
        $this->assertContains(
            'confusion: an Obra must not be confused with notes, tasks or simple projects.',
            $report['reasons'],
        );
    }

    /** A captured item with no entity signal is unclassified (fail). */
    public function test_unsignalled_item_is_unclassified(): void
    {
        $report = $this->service()->classifyEntity([]);

        $this->assertSame('fail', $report['status']);
        $this->assertNull($report['entity']);
    }

    /** The Obra card must expose all 13 documented fields; one missing fails. */
    public function test_obra_card_requires_all_thirteen_fields(): void
    {
        $this->assertCount(13, AtlasObrasProductUxAndUseCasesService::CARD_FIELDS);

        $full = array_fill_keys(AtlasObrasProductUxAndUseCasesService::CARD_FIELDS, true);
        $ok = $this->service()->auditObraCard($full);
        $this->assertSame('pass', $ok['status']);
        $this->assertSame(13, $ok['present_count']);

        $missingRisk = $full;
        $missingRisk['main_risk'] = false;
        $fail = $this->service()->auditObraCard($missingRisk);
        $this->assertSame('fail', $fail['status']);
        $this->assertSame(['main_risk'], $fail['missing']);
        $this->assertContains('card_field_missing: main_risk', $fail['blocking_reasons']);
    }

    /**
     * The mature workspace must expose all 12 sections in the documented order.
     * The full ordered set passes; a swapped order fails on the order rule.
     */
    public function test_workspace_sections_must_be_complete_and_ordered(): void
    {
        $this->assertCount(12, AtlasObrasProductUxAndUseCasesService::WORKSPACE_SECTIONS);

        $ok = $this->service()->auditWorkspaceSections(
            AtlasObrasProductUxAndUseCasesService::WORKSPACE_SECTIONS,
        );
        $this->assertSame('pass', $ok['status']);
        $this->assertTrue($ok['order_ok']);

        // Composer (3) before Structure (2) breaks the documented sequence.
        $swapped = AtlasObrasProductUxAndUseCasesService::WORKSPACE_SECTIONS;
        [$swapped[1], $swapped[2]] = [$swapped[2], $swapped[1]];
        $fail = $this->service()->auditWorkspaceSections($swapped);
        $this->assertSame('fail', $fail['status']);
        $this->assertFalse($fail['order_ok']);

        // A missing section is reported.
        $missing = array_values(array_filter(
            AtlasObrasProductUxAndUseCasesService::WORKSPACE_SECTIONS,
            static fn (string $s): bool => $s !== 'output',
        ));
        $missingReport = $this->service()->auditWorkspaceSections($missing);
        $this->assertSame('fail', $missingReport['status']);
        $this->assertSame(['output'], $missingReport['missing']);
    }

    /**
     * Documented decision: "Every AI session inside Obras must produce or
     * improve an artifact, not remain loose conversation" + Composer rule "AI
     * works inside the Obra". A scoped session that improves an artifact passes;
     * a scoped session that does neither is rejected as loose conversation; an
     * unscoped session is rejected even when it produces an artifact.
     */
    public function test_ai_session_must_produce_or_improve_and_be_scoped(): void
    {
        $svc = $this->service();

        $ok = $svc->validateAiSession([
            'obra_id' => 'obra_tcc',
            'produces_artifact' => false,
            'improves_artifact' => true,
        ]);
        $this->assertSame('pass', $ok['status']);
        $this->assertTrue($ok['produces_or_improves']);

        $loose = $svc->validateAiSession([
            'obra_id' => 'obra_tcc',
            'produces_artifact' => false,
            'improves_artifact' => false,
        ]);
        $this->assertSame('fail', $loose['status']);
        $this->assertContains(
            'loose_conversation: every AI session must produce or improve an artifact, not remain loose conversation.',
            $loose['reasons'],
        );

        $unscoped = $svc->validateAiSession([
            'obra_id' => '   ',
            'produces_artifact' => true,
        ]);
        $this->assertSame('fail', $unscoped['status']);
        $this->assertFalse($unscoped['bound_to_obra']);
    }

    /**
     * Documented rule: quick actions "should appear as contextual buttons and
     * AI actions, not raw commands". A known action rendered as a button passes;
     * the same action rendered as a raw command fails.
     */
    public function test_quick_actions_must_surface_as_buttons_not_raw_commands(): void
    {
        $svc = $this->service();

        $button = $svc->resolveQuickActionSurface('run_quality_gates', false);
        $this->assertSame('pass', $button['status']);
        $this->assertSame('contextual_button', $button['render_as']);

        $raw = $svc->resolveQuickActionSurface('run_quality_gates', true);
        $this->assertSame('fail', $raw['status']);
        $this->assertContains(
            'raw_command_surface: quick actions must appear as contextual buttons and AI actions, not raw commands.',
            $raw['reasons'],
        );

        $unknown = $svc->resolveQuickActionSurface('delete_everything', false);
        $this->assertSame('fail', $unknown['status']);
        $this->assertFalse($unknown['known']);
    }

    /** The whole-product audit is green only when every surface passes. */
    public function test_full_audit_is_green_for_a_conformant_bundle(): void
    {
        $bundle = [
            'entity' => ['builds_artifact_until_ready' => true],
            'card' => array_fill_keys(AtlasObrasProductUxAndUseCasesService::CARD_FIELDS, true),
            'workspace_sections' => AtlasObrasProductUxAndUseCasesService::WORKSPACE_SECTIONS,
            'ai_session' => [
                'obra_id' => 'obra_atlas_kernel_v1',
                'produces_artifact' => true,
            ],
            'quick_action' => [
                'action' => 'publish_version',
                'render_as_raw_command' => false,
            ],
        ];

        $report = $this->service()->audit($bundle);
        $this->assertSame('pass', $report['status']);
        $this->assertSame([], $report['blocking_reasons']);

        // Flip the AI session to loose conversation -> whole audit fails.
        $bundle['ai_session']['produces_artifact'] = false;
        $failing = $this->service()->audit($bundle);
        $this->assertSame('fail', $failing['status']);
        $this->assertNotEmpty($failing['blocking_reasons']);
    }
}
