<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\ReadinessProjectionHumanCompletionReceiptSection;
use Tests\TestCase;

/**
 * Locks the contract of ReadinessProjectionHumanCompletionReceiptSection —
 * the atlasSelfConstructionHumanCompletionReceipt* projection concern
 * extracted from the god-class AtlasSelfConstructionReadinessService.
 */
final class AtlasAiSelfConstructionReadinessProjectionHumanCompletionReceiptSectionTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new ReadinessProjectionHumanCompletionReceiptSection(
            fn (array $payload): string => 'hash:'.md5((string) json_encode($payload))
        );
        $this->assertInstanceOf(ReadinessProjectionHumanCompletionReceiptSection::class, $section);
    }

    public function test_all_20_human_completion_receipt_methods_exist_on_section(): void
    {
        $section = new ReadinessProjectionHumanCompletionReceiptSection(
            fn (array $payload): string => 'hash'
        );

        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'atlasSelfConstructionHumanCompletionReceipt')
        );
        $this->assertGreaterThanOrEqual(
            20,
            count($publicMethods),
            'Section must expose at least 20 atlasSelfConstructionHumanCompletionReceipt* public methods'
        );

        foreach ([
            'atlasSelfConstructionHumanCompletionReceiptDraftContract',
            'atlasSelfConstructionHumanCompletionReceiptEndgameVerifierStatus',
        ] as $name) {
            $this->assertTrue(
                method_exists($section, $name),
                "ReadinessProjectionHumanCompletionReceiptSection::{$name} must exist"
            );
        }
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        foreach ([
            'atlasSelfConstructionHumanCompletionReceiptDraftContract',
            'atlasSelfConstructionHumanCompletionReceiptEndgameVerifierStatus',
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
        $ref = new \ReflectionMethod($runtime, 'humanCompletionReceiptSection');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(ReadinessProjectionHumanCompletionReceiptSection::class, $section);
    }
}