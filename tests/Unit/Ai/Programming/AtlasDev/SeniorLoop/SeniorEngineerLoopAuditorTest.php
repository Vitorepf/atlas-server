<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\SeniorLoop;

use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopAuditor;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\PromptProjection\PromptProjectionFixtures;

final class SeniorEngineerLoopAuditorTest extends TestCase
{
    use PromptProjectionFixtures;

    public function test_ambiguity_resolution_prefers_task_contract_allowed_file_over_validation_test_path(): void
    {
        $workspace = '/tmp/atlas-dev-audit-fixture';
        $allowed = 'app/Services/Ai/Programming/AtlasDev/Support/AtlasDevStringListNormalizer.php';
        $test = 'tests/Unit/Ai/Programming/AtlasDev/Support/AtlasDevStringListNormalizerTest.php';

        $plan = new PlanOnlyResult(
            envelope: $this->envelope(['workspace' => $workspace]),
            classification: new TaskClassification(
                taskKind: TaskClassification::KIND_PATCH,
                intentClarityLevel: 'high',
                matchedRules: ['fixture'],
                writeImplied: true,
            ),
            riskLevel: 'R2',
            compactSdd: $this->compactSdd(),
            contextPlan: ContextRetrievalPlan::fromArray($this->loadFixture('context/valid_context_plan_desktop_r2.json')),
            discovery: $this->discoveryWithLikelyFiles($workspace, $allowed, $test),
            projection: $this->openBrainProjection(),
            miniSpec: MiniProgrammingSpec::fromArray(array_replace(
                $this->loadFixture($this->manifest()['mini_spec_fixture']),
                [
                    'allowed_files' => [$allowed],
                    'expected_files' => [$allowed],
                    'forbidden_files' => ['vendor/*'],
                ],
            )),
            taskContract: LightTaskContract::fromArray(array_replace(
                $this->loadFixture($this->manifest()['task_contract_fixture']),
                [
                    'allowed_files' => [$allowed],
                    'watched_files' => [$allowed],
                    'forbidden_files' => ['vendor/*'],
                    'validation_commands' => ['/opt/homebrew/bin/php vendor/bin/phpunit '.$test.' --stop-on-failure'],
                    'max_files_changed' => 1,
                ],
            )),
            promptProjection: ProviderPromptProjection::fromArray($this->loadFixture('context/valid_provider_prompt_projection.json')),
            routing: new RoutingDecision(
                kind: RoutingDecision::ATLAS_DEV_FAST_PATH,
                reasons: ['fixture'],
                blockers: [],
            ),
            persistedArtifactPaths: [
                'code_discovery_manifest' => '/tmp/code_discovery_manifest.json',
                'task_contract' => '/tmp/task_contract.json',
                'routing_decision' => '/tmp/routing_decision.json',
                'mini_programming_spec' => '/tmp/mini_programming_spec.json',
            ],
            blockers: [],
        );

        $audit = (new SeniorEngineerLoopAuditor())->audit($plan);
        $ambiguity = $audit->ambiguityResolution;

        $this->assertSame('passed', $audit->status);
        $this->assertSame('edit_or_inspect:'.$allowed, $ambiguity['selected_interpretation']);
        $this->assertSame('workspace://'.$allowed, $ambiguity['hypotheses'][0]['evidence_ref']);
        $this->assertSame('workspace://'.$test, $ambiguity['hypotheses'][1]['evidence_ref']);
    }

    private function discoveryWithLikelyFiles(string $workspace, string $allowed, string $test): CodeDiscoveryManifest
    {
        return CodeDiscoveryManifest::fromArray(array_replace(
            $this->loadFixture($this->manifest()['code_discovery_fixture']),
            [
                'confidence' => 'confirmed_fact',
                'likely_files' => [
                    [
                        'path' => $workspace.'/'.$test,
                        'reason' => 'path mentioned in intent',
                        'confidence' => 1.0,
                        'symbols' => ['AtlasDevStringListNormalizerTest'],
                    ],
                    [
                        'path' => $workspace.'/'.$allowed,
                        'reason' => 'path mentioned in intent',
                        'confidence' => 0.95,
                        'symbols' => ['AtlasDevStringListNormalizer'],
                    ],
                ],
            ],
        ));
    }
}
