<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Quaternity\IntentResolver;

use App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver\AtlasLoopIntentAmbiguityClarifierProposer;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopIntentAmbiguityClarifierProposerTest extends TestCase
{
    public function test_output_is_byte_identical_and_sorted_by_stable_hash_key(): void
    {
        $proposer = new AtlasLoopIntentAmbiguityClarifierProposer;

        $first = $proposer->propose($this->capturedIntent(), $this->ambiguityFindings(), $this->ledgerSnapshot());
        $second = $proposer->propose($this->capturedIntent(), $this->ambiguityFindings(), $this->ledgerSnapshot());

        $this->assertSame($first, $second);
        $this->assertSame(array_keys($first), array_values(array_keys($first)));

        $encodedFirst = json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $encodedSecond = json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertSame(hash('sha256', $encodedFirst), hash('sha256', $encodedSecond));
        $this->assertSame(array_keys($first), $this->sortedKeys($first));
    }

    public function test_throws_when_source_span_is_not_found_verbatim_in_captured_intent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ambiguity_source_span_not_found_verbatim');

        (new AtlasLoopIntentAmbiguityClarifierProposer)->propose(
            $this->capturedIntent(),
            [[
                'ambiguity_finding_id' => 'finding-missing',
                'dimension' => 'scope-undefined',
                'source_span' => 'nonexistent span',
            ]],
            $this->ledgerSnapshot(),
        );
    }

    public function test_class_has_no_provider_constructor_dependencies(): void
    {
        $constructor = (new ReflectionClass(AtlasLoopIntentAmbiguityClarifierProposer::class))->getConstructor();

        $this->assertTrue($constructor === null || $constructor->getNumberOfParameters() === 0);
    }

    public function test_each_question_carries_span_candidates_and_i_do_not_know_without_external_candidates(): void
    {
        $packets = (new AtlasLoopIntentAmbiguityClarifierProposer)->propose(
            $this->capturedIntent(),
            $this->ambiguityFindings(),
            $this->ledgerSnapshot(),
        );

        $allowedCandidates = array_values(array_unique(array_merge(
            array_map(
                static fn (array $entry): string => (string) ($entry['resolution'] ?? $entry['value'] ?? ''),
                $this->ledgerSnapshot(),
            ),
            $this->capturedIntent()['enumerated_tokens'],
        )));

        foreach ($packets as $packet) {
            $this->assertNotSame('', (string) $packet['span']);
            $this->assertNotSame('', (string) $packet['question']);
            $this->assertSame('I do not know', $packet['i_do_not_know']);
            $this->assertNotEmpty($packet['candidates']);

            foreach ((array) $packet['candidates'] as $candidate) {
                $this->assertContains($candidate, $allowedCandidates);
            }
        }
    }

    /**
     * @return array{intent_id:string,text:string,enumerated_tokens:list<string>}
     */
    private function capturedIntent(): array
    {
        return [
            'intent_id' => 'intent-1',
            'text' => 'Improve billing flow and define success for dashboard ownership.',
            'enumerated_tokens' => ['billing', 'dashboard', 'ownership', 'success'],
        ];
    }

    /**
     * @return list<array{ambiguity_finding_id:string,dimension:string,source_span:string}>
     */
    private function ambiguityFindings(): array
    {
        return [
            [
                'ambiguity_finding_id' => 'finding-1',
                'dimension' => 'scope-undefined',
                'source_span' => 'billing flow',
            ],
            [
                'ambiguity_finding_id' => 'finding-2',
                'dimension' => 'success-criterion-missing',
                'source_span' => 'success',
            ],
        ];
    }

    /**
     * @return list<array{dimension:string,resolution:string}>
     */
    private function ledgerSnapshot(): array
    {
        return [
            ['dimension' => 'scope-undefined', 'resolution' => 'billing'],
            ['dimension' => 'scope-undefined', 'resolution' => 'dashboard'],
            ['dimension' => 'success-criterion-missing', 'resolution' => 'success'],
            ['dimension' => 'success-criterion-missing', 'resolution' => 'ownership'],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $packets
     * @return list<string>
     */
    private function sortedKeys(array $packets): array
    {
        $keys = array_keys($packets);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
