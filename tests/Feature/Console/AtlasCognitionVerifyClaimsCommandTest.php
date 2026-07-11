<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasCognitionVerifyClaimsCommandTest extends TestCase
{
    public function test_injected_drift_fails_with_named_facet(): void
    {
        $this->bindScorecard([
            'overall' => 9.91,
            'pipeline' => 9.8,
            'hash' => 'sha256:'.str_repeat('a', 64),
            'partials' => ['MEM-RECALL', 'AKIF'],
        ]);

        [$acosDoc, $partialsDoc] = $this->writeDocsWithStamp([
            'overall_score' => 9.91,
            'pipeline_score' => 9.8,
            'scorecard_hash' => 'sha256:'.str_repeat('a', 64),
            'partial_facets' => ['MEM-RECALL', 'FAKE-FACET'],
        ]);

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:cognition:scorecard:verify-claims', [
            '--acos-doc' => $acosDoc,
            '--partials-doc' => $partialsDoc,
        ], $output);
        $text = $output->fetch();

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('partial_facets', $text);
        $this->assertStringContainsString('AKIF', $text);
        $this->assertStringContainsString('FAKE-FACET', $text);
    }

    public function test_after_write_verifies_cleanly(): void
    {
        $this->bindScorecard([
            'overall' => 9.91,
            'pipeline' => 9.8,
            'hash' => 'sha256:'.str_repeat('b', 64),
            'partials' => ['MEM-RECALL', 'AKIF'],
        ]);

        [$acosDoc, $partialsDoc] = $this->writeDocsWithoutStamp();

        $writeOutput = new BufferedOutput;
        $writeExit = Artisan::call('atlas:cognition:scorecard:verify-claims', [
            '--acos-doc' => $acosDoc,
            '--partials-doc' => $partialsDoc,
            '--write' => true,
            '--json' => true,
        ], $writeOutput);

        $this->assertSame(0, $writeExit, $writeOutput->fetch());

        $verifyOutput = new BufferedOutput;
        $verifyExit = Artisan::call('atlas:cognition:scorecard:verify-claims', [
            '--acos-doc' => $acosDoc,
            '--partials-doc' => $partialsDoc,
            '--json' => true,
        ], $verifyOutput);
        $payload = json_decode($verifyOutput->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $verifyExit);
        $this->assertTrue($payload['ok']);
        $this->assertSame([], $payload['diffs']);
    }

    /**
     * @param  array{overall: float, pipeline: float, hash: string, partials: list<string>}  $state
     */
    private function bindScorecard(array $state): void
    {
        $this->app->instance(AtlasCognitionScoreCardService::class, new class($state) extends AtlasCognitionScoreCardService
        {
            /**
             * @param  array{overall: float, pipeline: float, hash: string, partials: list<string>}  $state
             */
            public function __construct(private readonly array $state) {}

            /**
             * @return array<string,mixed>
             */
            public function build(): array
            {
                $subsystems = [
                    $this->row('MEM-RECALL', in_array('MEM-RECALL', $this->state['partials'], true)),
                    $this->row('AKIF', in_array('AKIF', $this->state['partials'], true)),
                    $this->row('ACRS', in_array('ACRS', $this->state['partials'], true)),
                ];

                return [
                    'schema_version' => 'atlas.cognition.scorecard.v3',
                    'scorecard_hash' => $this->state['hash'],
                    'subsystems' => $subsystems,
                    'score' => [
                        'overall_out_of_10' => $this->state['overall'],
                        'dimensions' => [
                            'pipeline' => [
                                'score_out_of_10' => $this->state['pipeline'],
                            ],
                        ],
                    ],
                ];
            }

            /**
             * @return array<string,mixed>
             */
            private function row(string $acronym, bool $partial): array
            {
                return [
                    'acronym' => $acronym,
                    'name' => $acronym,
                    'group' => 'test',
                    'code_status' => 'ready',
                    'doc_status' => 'ready',
                    'pipeline_status' => $partial ? 'partial' : 'ready',
                ];
            }
        });
    }

    /**
     * @param  array{overall_score: float, pipeline_score: float, scorecard_hash: string, partial_facets: list<string>}  $stamp
     * @return array{string,string}
     */
    private function writeDocsWithStamp(array $stamp): array
    {
        [$acosDoc, $partialsDoc] = $this->docPaths();
        $json = json_encode([
            'schema' => 'atlas.acos.scorecard_claims.v1',
            'source' => 'AtlasCognitionScoreCardService::build()',
            'overall_score' => $stamp['overall_score'],
            'pipeline_score' => $stamp['pipeline_score'],
            'scorecard_hash' => $stamp['scorecard_hash'],
            'partial_facets' => $stamp['partial_facets'],
            'partial_facet_count' => count($stamp['partial_facets']),
            'updated_at' => '2026-07-11T00:00:00+00:00',
            'note' => 'Doc stamp mirror only; runtime scorecard is authoritative.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $stampBlock = "<!-- atlas:acos-scorecard-claims:start -->\n{$json}\n<!-- atlas:acos-scorecard-claims:end -->\n";

        File::put($acosDoc, "# ACOS fixture\n\n{$stampBlock}");
        File::put($partialsDoc, "# ACOS partials fixture\n\n{$stampBlock}");

        return [$acosDoc, $partialsDoc];
    }

    /**
     * @return array{string,string}
     */
    private function writeDocsWithoutStamp(): array
    {
        [$acosDoc, $partialsDoc] = $this->docPaths();

        File::put($acosDoc, "# ACOS fixture\n\nNo stamp yet.\n");
        File::put($partialsDoc, "# ACOS partials fixture\n\nNo stamp yet.\n");

        return [$acosDoc, $partialsDoc];
    }

    /**
     * @return array{string,string}
     */
    private function docPaths(): array
    {
        $dir = storage_path('framework/testing/acos-claims-'.str_replace('\\', '-', $this->name()));
        File::ensureDirectoryExists($dir);

        return [
            $dir.'/atlas-cognition-operating-system.md',
            $dir.'/atlas-cognition-operating-system-pipeline-partials.md',
        ];
    }
}
