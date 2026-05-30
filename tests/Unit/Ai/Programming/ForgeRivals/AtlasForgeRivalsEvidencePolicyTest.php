<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModeRegistry;
use PHPUnit\Framework\TestCase;

final class AtlasForgeRivalsEvidencePolicyTest extends TestCase
{
    public function testConstantsAreDefined(): void
    {
        $this->assertSame('pre_adjudication', AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION);
        $this->assertSame('final', AtlasForgeRivalsEvidencePolicy::STAGE_FINAL);
        $this->assertSame('required', AtlasForgeRivalsEvidencePolicy::POLICY_REQUIRED);
        $this->assertSame('optional', AtlasForgeRivalsEvidencePolicy::POLICY_OPTIONAL);
        $this->assertSame(
            ['pre_adjudication', 'final'],
            AtlasForgeRivalsEvidencePolicy::ALL_STAGES
        );
    }

    public function testNormalizeStageReturnsPreAdjudicationForPreAdjudication(): void
    {
        $this->assertSame(
            AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            AtlasForgeRivalsEvidencePolicy::normalizeStage('pre_adjudication')
        );
    }

    public function testNormalizeStageIsCaseInsensitive(): void
    {
        $this->assertSame(
            AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            AtlasForgeRivalsEvidencePolicy::normalizeStage('PRE_ADJUDICATION')
        );
        $this->assertSame(
            AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            AtlasForgeRivalsEvidencePolicy::normalizeStage('Pre_Adjudication')
        );
        $this->assertSame(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            AtlasForgeRivalsEvidencePolicy::normalizeStage('FINAL')
        );
        $this->assertSame(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            AtlasForgeRivalsEvidencePolicy::normalizeStage('Final')
        );
    }

    public function testNormalizeStageReturnsFinalForFinal(): void
    {
        $this->assertSame(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            AtlasForgeRivalsEvidencePolicy::normalizeStage('final')
        );
    }

    public function testNormalizeStageReturnsFinalForEmptyString(): void
    {
        $this->assertSame(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            AtlasForgeRivalsEvidencePolicy::normalizeStage('')
        );
    }

    public function testNormalizeStageReturnsFinalForNull(): void
    {
        $this->assertSame(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            AtlasForgeRivalsEvidencePolicy::normalizeStage(null)
        );
    }

    public function testNormalizeStageReturnsFinalForUnknownValue(): void
    {
        $this->assertSame(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            AtlasForgeRivalsEvidencePolicy::normalizeStage('unknown_stage')
        );
    }

    public function testPlanReturnsCorrectStructure(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'comparable', 'mode' => 'fair']
        );

        $this->assertArrayHasKey('stage', $result);
        $this->assertArrayHasKey('required', $result);
        $this->assertArrayHasKey('optional', $result);
        $this->assertArrayHasKey('policy', $result);
        $this->assertArrayHasKey('is_comparable_real_run', $result);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('mode', $result);
    }

    public function testPlanManifestEventsJsonlIntentJsonAlwaysRequired(): void
    {
        $verdicts = ['comparable', 'invalid_malformed_manifest', 'unknown', 'inconclusive'];
        $modes = [AtlasForgeRivalsModeRegistry::MODE_FAIR, AtlasForgeRivalsModeRegistry::MODE_FULL_POWER, 'local_fake'];

        foreach ($verdicts as $verdict) {
            foreach ($modes as $mode) {
                $result = AtlasForgeRivalsEvidencePolicy::plan(
                    AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
                    ['verdict' => $verdict, 'mode' => $mode]
                );

                $this->assertContains('manifest', $result['required']);
                $this->assertContains('events_jsonl', $result['required']);
                $this->assertContains('intent_json', $result['required']);
                $this->assertSame('required', $result['policy']['manifest']);
                $this->assertSame('required', $result['policy']['events_jsonl']);
                $this->assertSame('required', $result['policy']['intent_json']);
            }
        }
    }

    public function testPlanComparableVerdictRequiresReceiptsAndHashes(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'comparable', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
        );

        $this->assertContains('atlas_receipt', $result['required']);
        $this->assertContains('rival_receipt', $result['required']);
        $this->assertContains('workspace_hashes', $result['required']);
    }

    public function testPlanComparableVerdictWithRealModeRequiresPatchAndLogs(): void
    {
        $modes = [AtlasForgeRivalsModeRegistry::MODE_FAIR, AtlasForgeRivalsModeRegistry::MODE_FULL_POWER];

        foreach ($modes as $mode) {
            $result = AtlasForgeRivalsEvidencePolicy::plan(
                AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
                ['verdict' => 'comparable', 'mode' => $mode]
            );

            $this->assertContains('atlas_patch', $result['required']);
            $this->assertContains('rival_patch', $result['required']);
            $this->assertContains('atlas_test_log', $result['required']);
            $this->assertContains('rival_test_log', $result['required']);
            $this->assertSame('required', $result['policy']['atlas_patch']);
            $this->assertSame('required', $result['policy']['rival_patch']);
            $this->assertSame('required', $result['policy']['atlas_test_log']);
            $this->assertSame('required', $result['policy']['rival_test_log']);
        }
    }

    public function testPlanComparableVerdictWithLocalFakeMakesPatchAndLogsOptional(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'comparable', 'mode' => 'local_fake']
        );

        $this->assertContains('atlas_patch', $result['optional']);
        $this->assertContains('rival_patch', $result['optional']);
        $this->assertContains('atlas_test_log', $result['optional']);
        $this->assertContains('rival_test_log', $result['optional']);
        $this->assertSame('optional', $result['policy']['atlas_patch']);
        $this->assertSame('optional', $result['policy']['rival_patch']);
        $this->assertSame('optional', $result['policy']['atlas_test_log']);
        $this->assertSame('optional', $result['policy']['rival_test_log']);
    }

    public function testPlanInvalidVerdictMakesPerArmArtifactsOptional(): void
    {
        $invalidVerdicts = [
            'invalid_malformed_manifest',
            'invalid_missing_artifacts',
            'invalid_unknown_error',
        ];

        foreach ($invalidVerdicts as $verdict) {
            $result = AtlasForgeRivalsEvidencePolicy::plan(
                AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
                ['verdict' => $verdict, 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
            );

            $this->assertNotContains('atlas_receipt', $result['required']);
            $this->assertNotContains('rival_receipt', $result['required']);
            $this->assertNotContains('workspace_hashes', $result['required']);
            $this->assertNotContains('atlas_patch', $result['required']);
            $this->assertNotContains('rival_patch', $result['required']);
            $this->assertNotContains('atlas_test_log', $result['required']);
            $this->assertNotContains('rival_test_log', $result['required']);

            $this->assertContains('atlas_receipt', $result['optional']);
            $this->assertContains('rival_receipt', $result['optional']);
            $this->assertContains('workspace_hashes', $result['optional']);
            $this->assertContains('atlas_patch', $result['optional']);
            $this->assertContains('rival_patch', $result['optional']);
            $this->assertContains('atlas_test_log', $result['optional']);
            $this->assertContains('rival_test_log', $result['optional']);
        }
    }

    public function testPlanUnknownVerdictMakesPerArmArtifactsOptional(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'unknown', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
        );

        $this->assertNotContains('atlas_receipt', $result['required']);
        $this->assertNotContains('rival_receipt', $result['required']);
        $this->assertNotContains('workspace_hashes', $result['required']);

        $this->assertContains('atlas_receipt', $result['optional']);
        $this->assertContains('rival_receipt', $result['optional']);
        $this->assertContains('workspace_hashes', $result['optional']);
    }

    public function testPlanInconclusiveVerdictMakesPerArmArtifactsOptional(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'inconclusive', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
        );

        $this->assertNotContains('atlas_receipt', $result['required']);
        $this->assertNotContains('rival_receipt', $result['required']);
        $this->assertNotContains('workspace_hashes', $result['required']);

        $this->assertContains('atlas_receipt', $result['optional']);
        $this->assertContains('rival_receipt', $result['optional']);
        $this->assertContains('workspace_hashes', $result['optional']);
    }

    public function testPlanFinalStageRequiresScorecard(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'comparable', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
        );

        $this->assertContains('scorecard', $result['required']);
        $this->assertSame('required', $result['policy']['scorecard']);
    }

    public function testPlanPreAdjudicationStageDoesNotRequireScorecard(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            ['verdict' => 'comparable', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
        );

        $this->assertNotContains('scorecard', $result['required']);
        $this->assertNotContains('scorecard', $result['optional']);
        $this->assertArrayNotHasKey('scorecard', $result['policy']);
    }

    public function testPlanIsComparableRealRunTrueForComparableWithRealMode(): void
    {
        $modes = [AtlasForgeRivalsModeRegistry::MODE_FAIR, AtlasForgeRivalsModeRegistry::MODE_FULL_POWER];

        foreach ($modes as $mode) {
            $result = AtlasForgeRivalsEvidencePolicy::plan(
                AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
                ['verdict' => 'comparable', 'mode' => $mode]
            );

            $this->assertTrue($result['is_comparable_real_run']);
        }
    }

    public function testPlanIsComparableRealRunFalseForComparableWithLocalFake(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'comparable', 'mode' => 'local_fake']
        );

        $this->assertFalse($result['is_comparable_real_run']);
    }

    public function testPlanIsComparableRealRunFalseForInvalidVerdict(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'invalid_malformed_manifest', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
        );

        $this->assertFalse($result['is_comparable_real_run']);
    }

    public function testPlanReturnsCanonicalLowercaseVerdict(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'COMPARABLE', 'mode' => 'FAIR']
        );

        $this->assertSame('comparable', $result['verdict']);
        $this->assertSame('fair', $result['mode']);
    }

    public function testPlanDefaultsMissingManifestFields(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            []
        );

        $this->assertSame('unknown', $result['verdict']);
        $this->assertSame('unknown', $result['mode']);
    }

    public function testPlanNormalizesStageToFinalForEmptyString(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            '',
            ['verdict' => 'comparable', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
        );

        $this->assertSame(AtlasForgeRivalsEvidencePolicy::STAGE_FINAL, $result['stage']);
        $this->assertContains('scorecard', $result['required']);
    }

    public function testPlanRequiredAndOptionalAreUnique(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'comparable', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
        );

        $this->assertSame($result['required'], array_unique($result['required']));
        $this->assertSame($result['optional'], array_unique($result['optional']));
    }

    public function testPlanRequiredAndOptionalAreDisjoint(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'comparable', 'mode' => 'local_fake']
        );

        $intersection = array_intersect($result['required'], $result['optional']);
        $this->assertEmpty($intersection);
    }

    public function testPlanPolicyKeysMatchRequiredAndOptional(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'comparable', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
        );

        $allKeys = array_merge($result['required'], $result['optional']);
        $policyKeys = array_keys($result['policy']);

        $this->assertSame(sort($allKeys), sort($policyKeys));
    }

    public function testKeysForStagePreAdjudicationReturnsTenKeys(): void
    {
        $keys = AtlasForgeRivalsEvidencePolicy::keysForStage(
            AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION
        );

        $this->assertCount(10, $keys);
    }

    public function testKeysForStageFinalReturnsElevenKeys(): void
    {
        $keys = AtlasForgeRivalsEvidencePolicy::keysForStage(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL
        );

        $this->assertCount(11, $keys);
    }

    public function testKeysForStagePreAdjudicationExcludesScorecard(): void
    {
        $keys = AtlasForgeRivalsEvidencePolicy::keysForStage(
            AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION
        );

        $this->assertNotContains('scorecard', $keys);
    }

    public function testKeysForStageFinalIncludesScorecard(): void
    {
        $keys = AtlasForgeRivalsEvidencePolicy::keysForStage(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL
        );

        $this->assertContains('scorecard', $keys);
    }

    public function testKeysForStageReturnsCanonicalOrder(): void
    {
        $keys = AtlasForgeRivalsEvidencePolicy::keysForStage(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL
        );

        $expected = [
            'manifest',
            'events_jsonl',
            'intent_json',
            'atlas_receipt',
            'rival_receipt',
            'workspace_hashes',
            'atlas_patch',
            'rival_patch',
            'atlas_test_log',
            'rival_test_log',
            'scorecard',
        ];

        $this->assertSame($expected, $keys);
    }

    public function testKeysForStageNormalizesUnknownStageToFinal(): void
    {
        $keys = AtlasForgeRivalsEvidencePolicy::keysForStage('unknown_stage');

        $this->assertCount(11, $keys);
        $this->assertContains('scorecard', $keys);
    }

    public function testKeysForStageNormalizesEmptyStageToFinal(): void
    {
        $keys = AtlasForgeRivalsEvidencePolicy::keysForStage('');

        $this->assertCount(11, $keys);
        $this->assertContains('scorecard', $keys);
    }

    public function testFullWorkflowComparableFairModeFinalStage(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'comparable', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR]
        );

        $this->assertSame(AtlasForgeRivalsEvidencePolicy::STAGE_FINAL, $result['stage']);
        $this->assertTrue($result['is_comparable_real_run']);
        $this->assertSame('comparable', $result['verdict']);
        $this->assertSame('fair', $result['mode']);

        $expectedRequired = [
            'manifest',
            'events_jsonl',
            'intent_json',
            'atlas_receipt',
            'rival_receipt',
            'workspace_hashes',
            'atlas_patch',
            'rival_patch',
            'atlas_test_log',
            'rival_test_log',
            'scorecard',
        ];

        $this->assertSame($expectedRequired, $result['required']);
        $this->assertEmpty($result['optional']);
    }

    public function testFullWorkflowComparableLocalFakePreAdjudicationStage(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            ['verdict' => 'comparable', 'mode' => 'local_fake']
        );

        $this->assertSame(AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION, $result['stage']);
        $this->assertFalse($result['is_comparable_real_run']);

        $this->assertContains('manifest', $result['required']);
        $this->assertContains('events_jsonl', $result['required']);
        $this->assertContains('intent_json', $result['required']);
        $this->assertContains('atlas_receipt', $result['required']);
        $this->assertContains('rival_receipt', $result['required']);
        $this->assertContains('workspace_hashes', $result['required']);

        $this->assertNotContains('scorecard', $result['required']);
        $this->assertNotContains('scorecard', $result['optional']);

        $this->assertContains('atlas_patch', $result['optional']);
        $this->assertContains('rival_patch', $result['optional']);
        $this->assertContains('atlas_test_log', $result['optional']);
        $this->assertContains('rival_test_log', $result['optional']);
    }

    public function testFullWorkflowInvalidVerdictPreAdjudicationStage(): void
    {
        $result = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            ['verdict' => 'invalid_missing_artifacts', 'mode' => AtlasForgeRivalsModeRegistry::MODE_FULL_POWER]
        );

        $this->assertSame(AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION, $result['stage']);
        $this->assertFalse($result['is_comparable_real_run']);
        $this->assertSame('invalid_missing_artifacts', $result['verdict']);

        $this->assertContains('manifest', $result['required']);
        $this->assertContains('events_jsonl', $result['required']);
        $this->assertContains('intent_json', $result['required']);

        $this->assertNotContains('atlas_receipt', $result['required']);
        $this->assertNotContains('rival_receipt', $result['required']);
        $this->assertNotContains('workspace_hashes', $result['required']);
        $this->assertNotContains('scorecard', $result['required']);

        $this->assertContains('atlas_receipt', $result['optional']);
        $this->assertContains('rival_receipt', $result['optional']);
        $this->assertContains('workspace_hashes', $result['optional']);
        $this->assertContains('atlas_patch', $result['optional']);
        $this->assertContains('rival_patch', $result['optional']);
        $this->assertContains('atlas_test_log', $result['optional']);
        $this->assertContains('rival_test_log', $result['optional']);
    }
}