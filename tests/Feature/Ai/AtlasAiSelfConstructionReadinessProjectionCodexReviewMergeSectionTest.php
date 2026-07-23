<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionCodexReviewMergeSection;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Illuminate\Support\Facades\File;
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
        $section = new ReadinessProjectionCodexReviewMergeSection;
        $this->assertInstanceOf(ReadinessProjectionCodexReviewMergeSection::class, $section);
    }

    public function test_hashes_use_readiness_hash_stable_convention(): void
    {
        $payload = ['key' => 'value', 'z' => 1, 'a' => 2];
        $expected = ReadinessHash::stable($payload);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expected);
        $this->assertSame($expected, ReadinessHash::stable($payload), 'ReadinessHash::stable must be deterministic');
    }

    public function test_149_aliases_preserve_the_snapshot_through_the_production_facade(): void
    {
        File::deleteDirectory(storage_path('app/atlas/self-construction/reservations'));

        $sectionCorpus = $this->codexReviewMergeCorpus(new ReadinessProjectionCodexReviewMergeSection);
        $facadeCorpus = $this->codexReviewMergeCorpus(app(AtlasSelfConstructionReadinessService::class));

        $this->assertCount(149, $sectionCorpus);
        $this->assertSame(
            'a61e9885d14eb222e4aebb80c1ea87d49a9f5d6756359193f58677d907e0fbb2',
            hash('sha256', json_encode($sectionCorpus, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
        );
        $this->assertSame($sectionCorpus, $facadeCorpus);

        foreach ($facadeCorpus as $method => $payload) {
            $this->assertSemanticFamilyRow($method, $payload);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function codexReviewMergeCorpus(object $owner): array
    {
        $methods = array_values(array_filter(
            (new \ReflectionClass($owner))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => str_starts_with($method->getName(), 'codexReviewMerge')
        ));
        usort($methods, static fn (\ReflectionMethod $left, \ReflectionMethod $right): int => $left->getStartLine() <=> $right->getStartLine());

        $corpus = [];
        foreach ($methods as $method) {
            $corpus[$method->getName()] = $owner->{$method->getName()}([]);
        }

        return $corpus;
    }

    /** @param array<string, mixed> $payload */
    private function assertSemanticFamilyRow(string $method, array $payload): void
    {
        $this->assertContains(self::semanticFamilyFor($method), [
            'merge_review',
            'writer_lifecycle',
            'execution_contract',
            'human_decision_preview',
            'session_preview',
        ]);
        $this->assertNotSame('', (string) data_get($payload, 'schema_version'));
        $this->assertNotSame('', (string) data_get($payload, 'status'));
        $this->assertFalse((bool) data_get($payload, 'execution_allowed'));
        $this->assertFalse((bool) data_get($payload, 'dispatch_allowed'));
        $this->assertNotEmpty((array) data_get($payload, 'non_execution_guarantees', []));

        if (str_ends_with((string) data_get($payload, 'status'), '_ready')) {
            $this->assertTrue((bool) data_get($payload, 'execution_allowed'));
            $this->assertTrue((bool) data_get($payload, 'dispatch_allowed'));
            $this->assertSame(0, (int) data_get($payload, 'contract.blocking_count', 0));
        }
    }

    private static function semanticFamilyFor(string $method): string
    {
        return match (true) {
            str_contains($method, 'HumanEscalation') || str_contains($method, 'ManualDecisionRequest') => 'human_decision_preview',
            str_contains($method, 'Session') || str_contains($method, 'Packet') => 'session_preview',
            str_contains($method, 'ExecutionContract') => 'execution_contract',
            str_contains($method, 'WriterRelease') => 'writer_lifecycle',
            default => 'merge_review',
        };
    }
}
