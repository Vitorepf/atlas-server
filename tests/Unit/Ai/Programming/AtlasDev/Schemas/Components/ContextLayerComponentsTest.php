<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\MissingRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\QualityChecks;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContextLayerComponentsTest extends TestCase
{
    public function test_context_ref_round_trips(): void
    {
        $ref = ContextRef::fromArray([
            'kind' => 'symbol',
            'ref' => 'code_intelligence://symbol/X',
            'reason' => 'classe alvo',
        ]);

        $this->assertSame('symbol', $ref->kind);
        $this->assertSame(
            ['kind' => 'symbol', 'reason' => 'classe alvo', 'ref' => 'code_intelligence://symbol/X'],
            $ref->toCanonicalArray(),
        );
    }

    public function test_context_ref_rejects_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ContextRef(kind: '', ref: 'x', reason: 'y');
    }

    public function test_code_candidate_round_trips_and_validates_confidence(): void
    {
        $c = CodeCandidate::fromArray([
            'path' => '/abs/x.php',
            'reason' => 'r',
            'confidence' => 0.5,
            'symbols' => ['A', 'B'],
        ]);

        $this->assertSame(0.5, $c->confidence);
        $this->assertSame(['A', 'B'], $c->symbols);
    }

    public function test_code_candidate_rejects_empty_symbol(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CodeCandidate(path: '/x', reason: 'r', confidence: 0.5, symbols: ['']);
    }

    public function test_missing_ref_requires_non_empty_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MissingRef(what: '', whyMissing: 'why');
    }

    public function test_prompt_sections_canonical_array_is_alphabetical(): void
    {
        $sections = new PromptSections(
            objective: 'o',
            operatingRules: ['r1'],
            miniSpecRef: 'spec',
            taskContractRef: 'tc',
            contextRefs: ['c1'],
            codeDiscoveryRef: 'cd',
            allowedFiles: ['a.php'],
            forbiddenFiles: ['b.php'],
            expectedTests: ['t.php'],
            acceptanceCriteria: ['ac'],
            stopConditions: ['stop'],
            escalationConditions: ['esc'],
            outputContract: ['diff'],
        );

        $keys = array_keys($sections->toCanonicalArray());
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    public function test_quality_checks_helpers(): void
    {
        $all = QualityChecks::allPassing();
        $this->assertTrue($all->allPassed());
        $this->assertSame([], $all->failedChecks());

        $partial = new QualityChecks(
            noMissingRequiredSections: true,
            noUnboundedScope: false,
            noHiddenBenchmarkInstruction: true,
            noConflictingFileRules: true,
            noForgeOrCouncilLeakage: true,
            providerSafe: true,
        );
        $this->assertFalse($partial->allPassed());
        $this->assertSame(['no_unbounded_scope'], $partial->failedChecks());
    }
}
