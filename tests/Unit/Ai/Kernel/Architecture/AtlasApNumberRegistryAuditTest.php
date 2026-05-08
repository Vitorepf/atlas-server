<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApNumberRegistryAudit;
use Tests\TestCase;

final class AtlasApNumberRegistryAuditTest extends TestCase
{
    public function test_current_ap_directory_has_unique_numbers_and_valid_filenames(): void
    {
        $payload = app(AtlasApNumberRegistryAudit::class)->audit();

        $this->assertSame('atlas.ap_number_registry_audit.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('read_only_audit', $payload['mode']);
        $this->assertSame('documentation_number_registry_only_no_file_writes', $payload['authority']);
        $this->assertSame(0, $payload['duplicate_number_count']);
        $this->assertSame(0, $payload['duplicate_slug_count']);
        $this->assertSame(0, $payload['malformed_count']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.renumbers_files'));
        $this->assertTrue(data_get($payload, 'guardrails.blocks_on_duplicate_numbers'));
        $this->assertGreaterThan(185, $payload['next_suggested_number']);
    }

    public function test_audit_detects_duplicate_numbers_duplicate_slugs_and_malformed_files(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-audit-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-200-first-contract.md', "# AP-200 First Contract\n");
            file_put_contents($dir.'/AP-200-second-contract.md', "# AP-200 Second Contract\n");
            file_put_contents($dir.'/AP-201-first-contract.md', "# AP-201 First Contract\n");
            file_put_contents($dir.'/AP-bad-name.md', "# Bad Name\n");

            $payload = app(AtlasApNumberRegistryAudit::class)->audit($dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame(1, $payload['duplicate_number_count']);
            $this->assertSame(1, $payload['duplicate_slug_count']);
            $this->assertSame(1, $payload['malformed_count']);
            $this->assertSame('200', data_get($payload, 'duplicate_numbers.0.key'));
            $this->assertSame('first-contract', data_get($payload, 'duplicate_slugs.0.key'));
            $this->assertSame('AP-bad-name.md', data_get($payload, 'malformed_files.0.filename'));
            $this->assertSame(202, $payload['next_suggested_number']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
