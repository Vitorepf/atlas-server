<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasClaimDefinitionOfDoneValidator is wired into a real call path: the
 * atlas:aaeos:department-status command now validates an optional completion claim file
 * against the Definition of Done. It is no longer an orphan.
 */
final class AtlasAaeosClaimDefinitionOfDoneValidatorWiringWiredTest extends TestCase
{
    private string $claimFile = '';

    protected function tearDown(): void
    {
        if ($this->claimFile !== '' && is_file($this->claimFile)) {
            unlink($this->claimFile);
        }
        parent::tearDown();
    }

    private function exec(array $claim): array
    {
        $this->claimFile = sys_get_temp_dir().'/atlas_dod_claim_'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->claimFile, (string) json_encode($claim));

        Artisan::call('atlas:aaeos:department-status', [
            '--claim-file' => $this->claimFile,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_narrative_claim_missing_required_fields_is_flagged(): void
    {
        $output = $this->exec([
            'subject' => 'some-capability',
        ]);

        $this->assertArrayHasKey('claim_validation', $output);
        $this->assertSame('narrative', $output['claim_validation']['verdict']);
        $this->assertFalse($output['claim_validation']['passes']);
        $this->assertContains('owner_doc', $output['claim_validation']['missing_fields']);
    }

    public function test_complete_claim_passes_as_evidence(): void
    {
        $output = $this->exec([
            'subject' => 'some-capability',
            'owner_doc' => 'docs/x.md',
            'documental_state' => 'complete',
            'runtime_state' => 'complete',
            'proof' => 'phpunit tests/Unit/XTest.php',
            'code_command_applicable' => false,
        ]);

        $this->assertSame('evidence', $output['claim_validation']['verdict']);
        $this->assertTrue($output['claim_validation']['passes']);
    }

    public function test_no_claim_file_omits_claim_validation(): void
    {
        Artisan::call('atlas:aaeos:department-status', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('claim_validation', $decoded);
    }
}
