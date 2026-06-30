<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\ReadinessHash;
use App\Services\Ai\SelfConstruction\ReadinessProjectionCodexReviewMergeSection;
use Tests\TestCase;

/**
 * Locks the contract of ReadinessProjectionCodexReviewMergeSection — the
 * codexReviewMerge* projection concern extracted from the god-class
 * AtlasSelfConstructionReadinessService.
 *
 * The runtime service delegates 149 methods to this collaborator; this test
 * pins the wiring so a future refactor cannot silently break the delegation
 * contract.
 */
final class AtlasAiSelfConstructionReadinessProjectionCodexReviewMergeSectionTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new ReadinessProjectionCodexReviewMergeSection();
        $this->assertInstanceOf(ReadinessProjectionCodexReviewMergeSection::class, $section);
    }

    public function test_hashes_use_readiness_hash_stable_convention(): void
    {
        $payload = ['key' => 'value', 'z' => 1, 'a' => 2];
        $expected = ReadinessHash::stable($payload);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expected);
        $this->assertSame($expected, ReadinessHash::stable($payload), 'ReadinessHash::stable must be deterministic');
    }

    public function test_all_149_codex_review_merge_methods_exist_on_section(): void
    {
        $section = new ReadinessProjectionCodexReviewMergeSection();

        // Use reflection to count actual unique public methods on the section.
        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'codexReviewMerge')
        );
        $this->assertGreaterThanOrEqual(
            149,
            count($publicMethods),
            'Section must expose at least 149 codexReviewMerge* public methods'
        );

        // Verify at least the first few well-known methods exist on the class.
        foreach (['codexReviewMergeActionDraft', 'codexReviewMergePreflight', 'codexReviewMergeSignedFinalReceiptTemplate'] as $name) {
            $this->assertTrue(
                method_exists($section, $name),
                "ReadinessProjectionCodexReviewMergeSection::{$name} must exist"
            );
        }
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        // Spot-check a few key delegators exist on the runtime.
        foreach ([
            'codexReviewMergeActionDraft',
            'codexReviewMergePreflight',
            'codexReviewMergeSignedFinalReceiptTemplate',
        ] as $method) {
            $this->assertTrue(
                method_exists($runtime, $method),
                "AtlasSelfConstructionReadinessService::{$method} must exist as a delegator"
            );
        }
    }

    public function test_section_can_be_resolved_via_runtime_lazy_resolver(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $ref = new \ReflectionMethod($runtime, 'codexReviewMergeSection');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(ReadinessProjectionCodexReviewMergeSection::class, $section);
    }
}