<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationMode;
use PHPUnit\Framework\TestCase;

/**
 * E2 — Definition of Done prompt section.
 *
 * Covers VAL-E2-001, VAL-E2-002, VAL-E2-003, VAL-E2-013.
 *
 * The `## Definition of Done` section is sourced from
 * MiniProgrammingSpec.expectedBehavior[] + completionCriteria[] and is
 * rendered between `## Acceptance Criteria` and `## Stop Conditions` using
 * the conditional-empty pattern (mirrors `## Known Failure Modes`). When
 * both sources are empty (or e2.mode=off), the rendered prompt is
 * byte-identical to the pre-E2 baseline.
 *
 * Gated by atlas_dev.elevations.e2.mode: off => byte-identical regardless
 * of inputs and no E2 flag is raised.
 */
final class DefinitionOfDoneSectionTest extends TestCase
{
    use PromptProjectionFixtures;

    private function mapper(): PromptSectionsMapper
    {
        return new PromptSectionsMapper;
    }

    private function renderer(): PromptRenderer
    {
        return new PromptRenderer;
    }

    private function makeBuilder(): ProviderPromptBuilder
    {
        return new ProviderPromptBuilder(
            new PromptSectionsMapper,
            new PromptRenderer,
            new PromptQualityChecker,
        );
    }

    private function e2Off(): ElevationConfig
    {
        return ElevationConfig::for('e2', ['mode' => 'off']);
    }

    private function e2Advisory(): ElevationConfig
    {
        return ElevationConfig::for('e2', ['mode' => 'advisory']);
    }

    /**
     * Render the prompt directly from a PromptSections instance so the test
     * exercises the renderer's conditional-empty contract in isolation.
     */
    private function render(PromptSections $sections): string
    {
        return $this->renderer()->render(
            runId: 'run-e2',
            provider: 'claude_cli',
            modelFamily: 'sonnet',
            upstreamHashes: ['envelope_hash' => 'env-hash'],
            sections: $sections,
        );
    }

    /**
     * Build the baseline PromptSections (no definitionOfDone) — equivalent to
     * the pre-E2 projection. Used to assert byte-identical baseline behavior.
     */
    private function baselineSections(): PromptSections
    {
        return $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            e2Config: $this->e2Off(),
        );
    }

    // -- VAL-E2-001: populated sources render the section with every entry ---

    public function test_populated_sources_render_definition_of_done_with_every_entry(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            e2Config: $this->e2Advisory(),
        );

        $text = $this->render($sections);

        $this->assertStringContainsString('## Definition of Done', $text, 'DoD header must be present');

        // Every expected_behavior entry must appear.
        foreach ($this->miniSpec()->expectedBehavior as $behavior) {
            if (! is_array($behavior)) {
                continue;
            }
            $description = isset($behavior['description']) && is_string($behavior['description'])
                ? $behavior['description']
                : '';
            if ($description !== '') {
                $this->assertStringContainsString(
                    $description,
                    $text,
                    'every expected_behavior entry must appear in the rendered DoD',
                );
            }
        }

        // Every completion_criterion entry must appear.
        foreach ($this->miniSpec()->completionCriteria as $criterion) {
            if (! is_string($criterion) || trim($criterion) === '') {
                continue;
            }
            $this->assertStringContainsString(
                $criterion,
                $text,
                'every completion_criterion entry must appear in the rendered DoD',
            );
        }
    }

    public function test_definition_of_done_section_is_between_acceptance_criteria_and_stop_conditions(): void
    {
        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            e2Config: $this->e2Advisory(),
        );

        $text = $this->render($sections);

        $acPos = strpos($text, '## Acceptance Criteria');
        $dodPos = strpos($text, '## Definition of Done');
        $stopPos = strpos($text, '## Stop Conditions');

        $this->assertNotFalse($acPos, 'Acceptance Criteria must be present');
        $this->assertNotFalse($dodPos, 'Definition of Done must be present');
        $this->assertNotFalse($stopPos, 'Stop Conditions must be present');

        $this->assertLessThan(
            $dodPos,
            $acPos,
            '## Definition of Done must come AFTER ## Acceptance Criteria',
        );
        $this->assertLessThan(
            $stopPos,
            $dodPos,
            '## Definition of Done must come BEFORE ## Stop Conditions',
        );
    }

    // -- VAL-E2-002: empty sources => byte-identical to pre-E2 baseline ------

    public function test_empty_sources_render_byte_identical_to_pre_e2_baseline(): void
    {
        $emptySpec = $this->miniSpec([
            'expected_behavior' => [],
            'completion_criteria' => [],
        ]);

        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $emptySpec,
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            e2Config: $this->e2Advisory(),
        );

        $this->assertSame([], $sections->definitionOfDone, 'definitionOfDone must be empty when both sources are empty');

        $rendered = $this->render($sections);

        // Pre-E2 baseline = the same inputs rendered with e2 off (the
        // section is gated off entirely).
        $baselineSections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $emptySpec,
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            e2Config: $this->e2Off(),
        );
        $baseline = $this->render($baselineSections);

        $this->assertSame(
            $baseline,
            $rendered,
            'VAL-E2-002: empty sources must produce byte-identical output to baseline (no empty header, no whitespace delta)',
        );
        $this->assertStringNotContainsString(
            '## Definition of Done',
            $rendered,
            'VAL-E2-002: no empty DoD header when both sources are empty',
        );
    }

    // -- VAL-E2-003: single-source populated => well-formed (no placeholders) -

    public function test_only_expected_behavior_populated_renders_only_its_entries(): void
    {
        $onlyBehavior = $this->miniSpec([
            'completion_criteria' => [],
        ]);

        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $onlyBehavior,
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            e2Config: $this->e2Advisory(),
        );

        $text = $this->render($sections);

        $this->assertStringContainsString('## Definition of Done', $text, 'section present when expected_behavior populated');
        // The single behavior entry appears.
        $this->assertStringContainsString(
            $this->miniSpec()->expectedBehavior[0]['description'],
            $text,
        );
        // No empty/placeholder bullets.
        $this->assertStringNotContainsString("- \n", $text, 'no empty bullet');
        $this->assertStringNotContainsString("- \r", $text, 'no empty bullet');
    }

    public function test_only_completion_criteria_populated_renders_only_its_entries(): void
    {
        $onlyCompletion = $this->miniSpec([
            'expected_behavior' => [],
        ]);

        $sections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $onlyCompletion,
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            e2Config: $this->e2Advisory(),
        );

        $text = $this->render($sections);

        $this->assertStringContainsString('## Definition of Done', $text, 'section present when completion_criteria populated');
        foreach ($this->miniSpec()->completionCriteria as $criterion) {
            if (is_string($criterion) && trim($criterion) !== '') {
                $this->assertStringContainsString($criterion, $text);
            }
        }
        $this->assertStringNotContainsString("- \n", $text, 'no empty bullet');
    }

    // -- VAL-E2-013: e2.mode=off => byte-identical regardless of inputs ------

    public function test_mode_off_is_byte_identical_to_baseline_regardless_of_inputs(): void
    {
        // Fully-populated spec under e2.mode=off.
        $populatedSections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            e2Config: $this->e2Off(),
        );

        $this->assertSame(
            [],
            $populatedSections->definitionOfDone,
            'VAL-E2-013: e2.mode=off must NOT populate definitionOfDone even when sources are populated',
        );

        $populatedRendered = $this->render($populatedSections);

        // Empty-spec under e2.mode=off.
        $emptySpec = $this->miniSpec([
            'expected_behavior' => [],
            'completion_criteria' => [],
        ]);
        $emptySections = $this->mapper()->map(
            envelope: $this->envelope(),
            miniSpec: $emptySpec,
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            e2Config: $this->e2Off(),
        );
        $emptyRendered = $this->render($emptySections);

        // off-mode populated == off-mode empty == baseline (no DoD section at all).
        $this->assertSame(
            $emptyRendered,
            $populatedRendered,
            'VAL-E2-013: off-mode must be byte-identical regardless of inputs',
        );

        $this->assertStringNotContainsString(
            '## Definition of Done',
            $populatedRendered,
            'VAL-E2-013: off-mode must not render the DoD header',
        );
    }

    public function test_mode_off_does_not_append_e2_honesty_flag_to_sections(): void
    {
        $off = $this->e2Off();

        $this->assertSame(ElevationMode::OFF, $off->mode());
        $this->assertNull($off->honestyFlagName(), 'off mode must not produce an honesty flag name');
        $this->assertFalse($off->shouldAppendHonestyFlag(), 'off mode must not append a flag');
    }

    // -- Full builder integration: ProviderPromptBuilder threads the section --

    public function test_provider_prompt_builder_renders_definition_of_done_when_enabled(): void
    {
        // The builder uses the live config (e2 defaults to advisory in tests).
        // Force advisory by setting the env-backed config inline.
        $_ENV['ATLAS_DEV_ELEVATION_E2_MODE'] = 'advisory';
        try {
            $projection = $this->makeBuilder()->build(
                envelope: $this->envelope(),
                compactSdd: $this->compactSdd(),
                miniSpec: $this->miniSpec(),
                taskContract: $this->taskContract(),
                discovery: $this->codeDiscovery(),
                projection: $this->openBrainProjection(),
            );
        } finally {
            unset($_ENV['ATLAS_DEV_ELEVATION_E2_MODE']);
        }

        $this->assertStringContainsString(
            '## Definition of Done',
            $projection->renderedPromptText,
            'ProviderPromptBuilder must thread the DoD section through to the rendered prompt when e2 is enabled',
        );
    }
}
