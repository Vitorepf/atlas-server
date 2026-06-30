<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionCertificationService;
use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAntiGoodhartRefusalVerdict;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAntiGoodhartUnifiedRefusal;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopAntiGoodhartUnifiedRefusalTest extends TestCase
{
    public function test_empty_facts_yield_a_non_refused_verdict(): void
    {
        $verdict = AtlasLoopAntiGoodhartUnifiedRefusal::verdict([]);

        $this->assertFalse($verdict->refused());
        $this->assertSame([], $verdict->reasons());
        $this->assertSame([], $verdict->evidenceRefs());
    }

    public function test_verdict_carries_fact_only_reasons_with_canonical_shape(): void
    {
        $verdict = AtlasLoopAntiGoodhartUnifiedRefusal::verdict([
            [
                'source' => AtlasLoopAntiGoodhartUnifiedRefusal::SOURCE_PROXY,
                'pattern_id' => 'cyclomatic_shrink_no_bite',
                'fact' => ['edit_kind' => 'metric_shrink', 'mutation_kills' => 0],
                'severity' => AtlasLoopAntiGoodhartUnifiedRefusal::SEVERITY_HIGH,
                'evidence_refs' => ['receipt:abc', 'mutant:42'],
            ],
            [
                'source' => AtlasLoopAntiGoodhartUnifiedRefusal::SOURCE_FARM,
                'pattern_id' => 'doc_only_no_bite',
                'fact' => ['doc_only_edit' => true],
                'severity' => AtlasLoopAntiGoodhartUnifiedRefusal::SEVERITY_CRITICAL,
                'evidence_refs' => ['receipt:def'],
            ],
        ]);

        $this->assertTrue($verdict->refused());

        $reasons = $verdict->reasons();
        $this->assertCount(2, $reasons);
        foreach ($reasons as $reason) {
            foreach (['source', 'pattern_id', 'fact', 'severity', 'evidence_refs'] as $field) {
                $this->assertArrayHasKey($field, $reason);
            }
            $this->assertIsArray($reason['fact']);
            $this->assertIsString($reason['severity']);
            $this->assertIsArray($reason['evidence_refs']);
        }

        $this->assertSame(['receipt:abc', 'mutant:42', 'receipt:def'], $verdict->evidenceRefs());
    }

    public function test_verdict_class_carries_no_numeric_score_field(): void
    {
        $ref = new ReflectionClass(AtlasLoopAntiGoodhartRefusalVerdict::class);
        foreach ($ref->getProperties() as $prop) {
            $name = strtolower($prop->getName());
            $this->assertStringNotContainsString('score', $name, 'verdict must NOT carry a numeric score field');
        }
        foreach ($ref->getMethods() as $method) {
            $name = strtolower($method->getName());
            $this->assertStringNotContainsString('score', $name);
            $this->assertStringNotContainsString('toscalar', $name);
            $this->assertStringNotContainsString('magnitude', $name);
        }
    }

    public function test_invalid_source_is_dropped_silently_never_converted_into_refusal(): void
    {
        $verdict = AtlasLoopAntiGoodhartUnifiedRefusal::verdict([
            ['source' => 'bogus', 'pattern_id' => 'p1', 'fact' => ['x' => 1]],
            ['source' => '', 'pattern_id' => 'p2', 'fact' => ['x' => 1]],
            ['source' => AtlasLoopAntiGoodhartUnifiedRefusal::SOURCE_CONSTITUTION, 'pattern_id' => '', 'fact' => ['x' => 1]],
        ]);

        $this->assertFalse($verdict->refused(), 'malformed candidates must NOT produce a refusal');
    }

    public function test_template_fingerprint_returns_stable_hash_and_normalized_family_facts(): void
    {
        $ctx = [
            'objective' => 'Extend AtlasFoo with bar support for task 123',
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['Run php artisan test tests/Unit/FooTest.php'],
        ];
        $fp1 = AtlasLoopAntiGoodhartUnifiedRefusal::templateFingerprint($ctx);
        $fp2 = AtlasLoopAntiGoodhartUnifiedRefusal::templateFingerprint($ctx);

        $this->assertArrayHasKey('hash', $fp1);
        $this->assertArrayHasKey('normalized_objective', $fp1);
        $this->assertArrayHasKey('allowed_files_shape', $fp1);
        $this->assertArrayHasKey('acceptance_pattern', $fp1);
        $this->assertSame(64, strlen($fp1['hash']), 'hash must be a sha256 hex string');
        $this->assertSame($fp1['hash'], $fp2['hash'], 'fingerprint must be deterministic');
        // Numbers are stripped from objective
        $this->assertStringNotContainsString('123', $fp1['normalized_objective']);
    }

    public function test_different_objectives_yield_different_fingerprint_hashes(): void
    {
        $base = ['objective' => 'Extend Foo', 'allowed_files' => ['app/Foo.php'], 'acceptance_criteria' => ['Run tests']];
        $other = array_merge($base, ['objective' => 'Extend Bar']);

        $this->assertNotSame(
            AtlasLoopAntiGoodhartUnifiedRefusal::templateFingerprint($base)['hash'],
            AtlasLoopAntiGoodhartUnifiedRefusal::templateFingerprint($other)['hash']
        );
    }

    public function test_evaluate_appends_farm_refusal_when_fingerprints_repeat_above_threshold(): void
    {
        $ctx = ['objective' => 'Extend AtlasFoo with bar', 'allowed_files' => ['app/Foo.php'], 'acceptance_criteria' => ['Run test']];
        $fp = AtlasLoopAntiGoodhartUnifiedRefusal::templateFingerprint($ctx);
        $previousFps = array_fill(0, 3, $fp); // 3 matches at threshold=3

        $verdict = AtlasLoopAntiGoodhartUnifiedRefusal::evaluate($ctx, [], $previousFps, 3);

        $farmReasons = array_filter($verdict->reasons(), static fn (array $r): bool => $r['source'] === AtlasLoopAntiGoodhartUnifiedRefusal::SOURCE_FARM && $r['pattern_id'] === 'template-farm-repeat');
        $this->assertNotEmpty($farmReasons, 'a farm refusal must be appended when threshold is met');
        $farmReason = array_values($farmReasons)[0];
        $this->assertSame('high', $farmReason['severity']);
        $this->assertContains('template_fingerprint', $farmReason['evidence_refs']);
        $this->assertSame(3, $farmReason['fact']['repeat_count']);
    }

    public function test_evaluate_does_not_add_farm_fact_when_below_threshold(): void
    {
        $ctx = ['objective' => 'Extend AtlasFoo', 'allowed_files' => ['app/Foo.php'], 'acceptance_criteria' => ['Run test']];
        $fp = AtlasLoopAntiGoodhartUnifiedRefusal::templateFingerprint($ctx);
        $previousFps = array_fill(0, 2, $fp); // only 2, threshold=3

        $verdict = AtlasLoopAntiGoodhartUnifiedRefusal::evaluate($ctx, [], $previousFps, 3);

        $farmReasons = array_filter($verdict->reasons(), static fn (array $r): bool => ($r['pattern_id'] ?? '') === 'template-farm-repeat');
        $this->assertEmpty($farmReasons, 'below threshold must not add a farm fact');
    }

    public function test_certification_service_delegates_to_the_refusal_service(): void
    {
        // The runtime collaborator is final ⇒ build the certifier via reflection without invoking the
        // constructor; the delegation method under test does NOT touch $runtime.
        $ref = new ReflectionClass(AtlasAutonomousEvolutionCertificationService::class);
        /** @var AtlasAutonomousEvolutionCertificationService $certifier */
        $certifier = $ref->newInstanceWithoutConstructor();

        $verdict = $certifier->antiGoodhartRefusal([[
            'source' => AtlasLoopAntiGoodhartUnifiedRefusal::SOURCE_PARAPHRASE,
            'pattern_id' => 'restated_objective_no_acceptance_change',
            'fact' => ['restated' => true],
            'severity' => AtlasLoopAntiGoodhartUnifiedRefusal::SEVERITY_MEDIUM,
            'evidence_refs' => ['attempt:99'],
        ]]);

        $this->assertInstanceOf(AtlasLoopAntiGoodhartRefusalVerdict::class, $verdict);
        $this->assertTrue($verdict->refused());
        $this->assertSame('paraphrase', $verdict->reasons()[0]['source']);
    }
}
