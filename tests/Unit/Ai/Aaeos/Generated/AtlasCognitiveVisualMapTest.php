<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveVisualMapService;
use Tests\TestCase;

/**
 * Pins the canonical visual rules of the Cognitive Plane Visual Map spec.
 *
 * @see docs/engineering-knowledge-base/cognitive/visual-map.md
 */
class AtlasCognitiveVisualMapTest extends TestCase
{
    private function service(): AtlasCognitiveVisualMapService
    {
        return new AtlasCognitiveVisualMapService();
    }

    /** A fully-compliant proposal (all 10 obligatory rules satisfied). */
    private function compliant(): array
    {
        return [
            'pillars' => AtlasCognitiveVisualMapService::PILLARS,
            'movements' => AtlasCognitiveVisualMapService::MOVEMENTS,
            'pipeline_stages' => AtlasCognitiveVisualMapService::PIPELINE_STAGES,
            'elements' => AtlasCognitiveVisualMapService::REQUIRED_ELEMENTS,
            'learning_gated' => true,
            'forbidden' => [],
            'title' => AtlasCognitiveVisualMapService::CANONICAL_TITLE,
            'footer' => AtlasCognitiveVisualMapService::CANONICAL_FOOTER,
        ];
    }

    /**
     * Rules #2/#3/#5/#7: the canonical compliant diagram is valid, has exactly
     * 4 pillars and 5 movements, the 17-stage overlay, and the stable schema.
     */
    public function test_compliant_diagram_is_valid(): void
    {
        $result = $this->service()->validateDiagram($this->compliant());

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['violations']);
        $this->assertSame(4, $result['pillar_count']);
        $this->assertSame(5, $result['movement_count']);
        $this->assertSame(17, $result['pipeline_stages']);
        $this->assertSame('atlas.aaeos.cognitive_visual_map.v1', $result['schema']);
    }

    /**
     * Rule #5: the pipeline overlay is the canonical 17 stages — drawing 16 or
     * 18 is a pipeline_stage_count violation, not a rounding tolerance.
     */
    public function test_pipeline_must_be_seventeen_stages(): void
    {
        $proposal = $this->compliant();
        $proposal['pipeline_stages'] = 18;

        $result = $this->service()->validateDiagram($proposal);

        $this->assertFalse($result['valid']);
        $stageViolation = array_values(array_filter(
            $result['violations'],
            static fn ($v) => $v['rule'] === 'pipeline_stage_count',
        ));
        $this->assertSame(17, $stageViolation[0]['expected']);
        $this->assertSame(18, $stageViolation[0]['found']);
    }

    /**
     * Rule #7: dropping Human Review (and Curator) is flagged as missing
     * required elements.
     */
    public function test_missing_human_review_and_curator_are_flagged(): void
    {
        $proposal = $this->compliant();
        $proposal['elements'] = array_values(array_filter(
            AtlasCognitiveVisualMapService::REQUIRED_ELEMENTS,
            static fn ($e) => ! in_array($e, ['human-review', 'curator'], true),
        ));

        $result = $this->service()->validateDiagram($proposal);

        $this->assertFalse($result['valid']);
        $this->assertContains('human-review', $result['missing_elements']);
        $this->assertContains('curator', $result['missing_elements']);
    }

    /**
     * Rule #8: learning must NOT auto-alter critical behaviour without
     * proposal/review — an ungated diagram fails with learning_not_gated.
     */
    public function test_learning_must_be_gated_by_proposal_review(): void
    {
        $proposal = $this->compliant();
        $proposal['learning_gated'] = false;

        $result = $this->service()->validateDiagram($proposal);

        $this->assertFalse($result['valid']);
        $rules = array_column($result['violations'], 'rule');
        $this->assertContains('learning_not_gated', $rules);
    }

    /**
     * "Nao Fazer": drawing the plane as an isolated study app is a hard
     * forbidden_depiction violation carrying the doc rationale.
     */
    public function test_forbidden_isolated_study_app_is_rejected(): void
    {
        $proposal = $this->compliant();
        $proposal['forbidden'] = ['isolated-study-app'];

        $result = $this->service()->validateDiagram($proposal);

        $this->assertFalse($result['valid']);
        $forbidden = array_values(array_filter(
            $result['violations'],
            static fn ($v) => $v['rule'] === 'forbidden_depiction',
        ));
        $this->assertSame('isolated-study-app', $forbidden[0]['element']);
        $this->assertNotSame('unspecified', $forbidden[0]['reason']);
    }

    /**
     * "Conflito" precedence: principles.md beats the image, and the image can
     * never win a conflict.
     */
    public function test_conflict_precedence_principles_beats_image(): void
    {
        $service = $this->service();

        $resolved = $service->resolveConflict('principles', 'image');
        $this->assertTrue($resolved['resolvable']);
        $this->assertSame('principles', $resolved['winner']);
        $this->assertTrue($resolved['image_never_wins']);

        // pipeline-overlay outranks the specific AP and this doc.
        $this->assertLessThan(
            $service->authorityIndex('this-doc'),
            $service->authorityIndex('pipeline-overlay'),
        );
        // The image is the lowest authority of all named sources.
        $this->assertSame(
            count(AtlasCognitiveVisualMapService::CONFLICT_ORDER) - 1,
            $service->authorityIndex('image'),
        );
    }
}
