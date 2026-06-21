<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExternalResearchService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopResearchTopicDeriver;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use Tests\TestCase;

/**
 * §5.5 — the research TOPIC producer must be: (1) default-OFF / byte-identical, (2) egress-safe BY
 * CONSTRUCTION (only public engineering concepts, never repo data) AND under the live pull's own egress
 * filter (defence in depth), (3) shape-aware with an honest fallback.
 */
final class AtlasLoopResearchTopicDeriverTest extends TestCase
{
    private function deriver(): AtlasLoopResearchTopicDeriver
    {
        return new AtlasLoopResearchTopicDeriver;
    }

    public function test_default_off_derives_nothing_byte_identical(): void
    {
        // No flag set ⇒ default OFF ⇒ no topic, and options() is [] so the generator's options never change.
        $d = $this->deriver();
        $this->assertNull($d->derive(['shape' => AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS], base_path()));
        $this->assertSame([], $d->options(['shape' => AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS], base_path()));
    }

    public function test_armed_derives_a_concept_topic_per_known_shape(): void
    {
        config(['atlas.loop.research_authoring_enabled' => true]);
        $d = $this->deriver();

        foreach ([
            AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS,
            AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE,
            AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX,
            AtlasLoopWorkShapeRouter::SHAPE_REFACTOR,
        ] as $shape) {
            $topic = $d->derive(['shape' => $shape], base_path());
            $this->assertIsString($topic);
            $this->assertNotSame('', trim((string) $topic), "shape {$shape} must derive a topic");
        }
    }

    public function test_distinct_shapes_derive_distinct_topics(): void
    {
        config(['atlas.loop.research_authoring_enabled' => true]);
        $d = $this->deriver();

        $extract = $d->derive(['shape' => AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS], base_path());
        $edge = $d->derive(['shape' => AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX], base_path());
        $this->assertNotSame($extract, $edge, 'the topic is shape-aware, not a single constant');
    }

    public function test_unknown_or_absent_shape_falls_back_to_a_broad_concept(): void
    {
        config(['atlas.loop.research_authoring_enabled' => true]);
        $d = $this->deriver();

        $fallback = $d->derive(['shape' => 'something_unmapped'], base_path());
        $this->assertIsString($fallback);
        $this->assertNotSame('', trim((string) $fallback));
        // No shape key at all ⇒ same honest fallback (never null when armed).
        $this->assertSame($fallback, $d->derive([], base_path()));
    }

    public function test_every_derived_topic_is_egress_safe_against_the_real_repo(): void
    {
        // The load-bearing sovereignty property: a derived topic carries NO repo path / diff / secret, so it
        // passes the SAME egress filter the live pull uses — verified against the REAL repo root.
        config(['atlas.loop.research_authoring_enabled' => true]);
        $d = $this->deriver();
        $egress = new AtlasLoopExternalResearchService;

        foreach ([
            AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS,
            AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE,
            AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX,
            AtlasLoopWorkShapeRouter::SHAPE_REFACTOR,
            'unmapped_default',
        ] as $shape) {
            $topic = (string) $d->derive(['shape' => $shape], base_path());
            $this->assertStringNotContainsStringIgnoringCase('.php', $topic, "topic for {$shape} must carry no file path");
            $verdict = $egress->egressCheck($topic, base_path());
            $this->assertTrue($verdict['allowed'] ?? false, "the derived topic for {$shape} must pass the egress filter");
        }
    }

    public function test_options_carries_topic_and_repo_root_when_armed(): void
    {
        config(['atlas.loop.research_authoring_enabled' => true]);
        $opts = $this->deriver()->options(['shape' => AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX], '/some/repo/root');

        $this->assertArrayHasKey('research_topic', $opts);
        $this->assertArrayHasKey('repo_root', $opts);
        $this->assertSame('/some/repo/root', $opts['repo_root']);
        $this->assertIsString($opts['research_topic']);
    }
}
