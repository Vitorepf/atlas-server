<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosClaimDefinitionOfDoneValidator;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosClaimDefinitionOfDoneValidatorTest extends TestCase
{
    private AtlasAaeosClaimDefinitionOfDoneValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AtlasAaeosClaimDefinitionOfDoneValidator();
    }

    /**
     * A complete claim with non-partial states and all canonical fields populated.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function completeClaim(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'S99 finished',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-thing.md',
            'documental_state' => 'complete',
            'runtime_state' => 'runtime_verified',
            'code_command_path' => 'app/Console/Commands/AtlasThingCommand.php',
            'proof' => 'php artisan test --filter=AtlasThingTest green',
            'caveat' => 'none',
        ], $overrides);
    }

    public function testSchemaVersionIsStable(): void
    {
        $result = $this->validator->validate($this->completeClaim());

        $this->assertSame('atlas.aaeos.claim_definition_of_done.v1', $result['schema_version']);
        $this->assertSame(
            'atlas-agentic-engineering-os-implementation-reality.md:244',
            $result['evaluated_against'],
        );
    }

    public function testRequiredFieldsAreTheCanonicalUnconditionalMandatoryKeys(): void
    {
        $this->assertSame(
            ['owner_doc', 'documental_state', 'runtime_state', 'proof'],
            $this->validator->requiredFields(),
        );
    }

    public function testCompleteClaimWithAllSixFieldsYieldsEvidence(): void
    {
        $result = $this->validator->validate($this->completeClaim());

        $this->assertSame('evidence', $result['verdict']);
        $this->assertTrue($result['passes']);
        $this->assertSame([], $result['missing_fields']);
        $this->assertSame('S99 finished', $result['subject']);
        $this->assertSame(
            ['owner_doc', 'documental_state', 'runtime_state', 'code_command_path', 'proof', 'caveat'],
            $result['present_fields'],
        );
        $this->assertFalse($result['partial_claim']);
    }

    public function testMissingOwnerDocAndProofYieldsNarrativeInCanonicalOrder(): void
    {
        $claim = $this->completeClaim();
        unset($claim['owner_doc'], $claim['proof']);

        $result = $this->validator->validate($claim);

        $this->assertSame('narrative', $result['verdict']);
        $this->assertFalse($result['passes']);
        $this->assertSame(['owner_doc', 'proof'], $result['missing_fields']);
        $this->assertSame('missing', $result['field_status']['owner_doc']);
        $this->assertSame('missing', $result['field_status']['proof']);
        $this->assertSame('present', $result['field_status']['documental_state']);
    }

    public function testPartialStateWithoutCaveatRequiresCaveatAndIsNarrative(): void
    {
        $claim = $this->completeClaim(['documental_state' => 'partial']);
        unset($claim['caveat']);

        $result = $this->validator->validate($claim);

        $this->assertTrue($result['partial_claim']);
        $this->assertContains('caveat', $result['missing_fields']);
        $this->assertSame('narrative', $result['verdict']);
        $this->assertFalse($result['passes']);
        $this->assertSame('missing', $result['field_status']['caveat']);
    }

    public function testPartialStateWithCaveatPresentIsEvidence(): void
    {
        $result = $this->validator->validate(
            $this->completeClaim(['documental_state' => 'partial', 'caveat' => 'runtime still fixture'])
        );

        $this->assertTrue($result['partial_claim']);
        $this->assertNotContains('caveat', $result['missing_fields']);
        $this->assertSame('evidence', $result['verdict']);
        $this->assertTrue($result['passes']);
        $this->assertSame('present', $result['field_status']['caveat']);
    }

    public function testPartialDetectedViaRuntimeStateAndIsCaseInsensitive(): void
    {
        $claim = $this->completeClaim(['documental_state' => 'complete', 'runtime_state' => '  Partial  ']);
        unset($claim['caveat']);

        $result = $this->validator->validate($claim);

        $this->assertTrue($result['partial_claim']);
        $this->assertContains('caveat', $result['missing_fields']);
    }

    public function testCodePathNotApplicableIsExcludedFromMissingFields(): void
    {
        $result = $this->validator->validate(
            $this->completeClaim(['code_command_applicable' => false, 'code_command_path' => ''])
        );

        $this->assertSame('not_applicable', $result['field_status']['code_command_path']);
        $this->assertNotContains('code_command_path', $result['missing_fields']);
        $this->assertSame('evidence', $result['verdict']);
        $this->assertTrue($result['passes']);
    }

    public function testDefaultApplicableEmptyCodePathIsListedAsMissing(): void
    {
        $claim = $this->completeClaim(['code_command_path' => '']);
        unset($claim['code_command_applicable']);

        $result = $this->validator->validate($claim);

        $this->assertSame('missing', $result['field_status']['code_command_path']);
        $this->assertContains('code_command_path', $result['missing_fields']);
        $this->assertSame('narrative', $result['verdict']);
        $this->assertFalse($result['passes']);
    }

    public function testWhitespaceOnlyFieldValueIsTreatedAsMissing(): void
    {
        $result = $this->validator->validate($this->completeClaim(['owner_doc' => '   ']));

        $this->assertSame('missing', $result['field_status']['owner_doc']);
        $this->assertContains('owner_doc', $result['missing_fields']);
        $this->assertNotContains('owner_doc', $result['present_fields']);
        $this->assertSame('narrative', $result['verdict']);
    }

    public function testSubjectEchoesEmptyStringWhenAbsent(): void
    {
        $claim = $this->completeClaim();
        unset($claim['subject']);

        $result = $this->validator->validate($claim);

        $this->assertSame('', $result['subject']);
    }

    public function testValidationIsDeterministic(): void
    {
        $claim = $this->completeClaim([
            'documental_state' => 'partial',
            'caveat' => 'none',
            'code_command_applicable' => false,
            'code_command_path' => '',
        ]);

        $this->assertSame(
            $this->validator->validate($claim),
            $this->validator->validate($claim),
        );
    }
}
