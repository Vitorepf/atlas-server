<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasScopeValidatorContractService;
use Tests\TestCase;

/**
 * Pins the documented Scope Validator classification + status rules.
 *
 * @see docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
 */
class AtlasScopeValidatorContractTest extends TestCase
{
    private function service(): AtlasScopeValidatorContractService
    {
        return new AtlasScopeValidatorContractService();
    }

    /** Safe Example: only allowed paths => status=pass, continue, completion allowed. */
    public function test_only_allowed_paths_pass_and_allow_completion(): void
    {
        $r = $this->service()->validate([
            'packet_id' => 'AIP-20260601-0001',
            'allowed_files' => ['docs/engineering-knowledge-base/self-construction/scope-validator-contract.md'],
            'changed_files' => ['docs/engineering-knowledge-base/self-construction/scope-validator-contract.md'],
        ]);

        $this->assertSame(AtlasScopeValidatorContractService::STATUS_PASS, $r['status']);
        $this->assertSame(AtlasScopeValidatorContractService::NEXT_CONTINUE, $r['required_next_action']);
        $this->assertTrue($r['completion_allowed']);
        $this->assertSame(1, $r['summary']['allowed_count']);
        $this->assertSame([], $r['blocking_violations']);
        $this->assertSame(AtlasScopeValidatorContractService::CLASS_ALLOWED, $r['files'][0]['classification']);
        $this->assertFalse($r['files'][0]['blocking']);
    }

    /**
     * Decision: "Forbidden files always override allowed files."
     * Same path is listed in BOTH allowed and forbidden => forbidden wins,
     * blocking, status=fail, required_next_action=request_review.
     */
    public function test_forbidden_overrides_allowed(): void
    {
        $r = $this->service()->validate([
            'packet_id' => 'AIP-20260601-0002',
            'allowed_files' => ['routes/api.php'],
            'forbidden_files' => ['routes/api.php'],
            'changed_files' => ['routes/api.php'],
        ]);

        $this->assertSame(AtlasScopeValidatorContractService::CLASS_FORBIDDEN, $r['files'][0]['classification']);
        $this->assertTrue($r['files'][0]['blocking']);
        $this->assertSame(AtlasScopeValidatorContractService::STATUS_FAIL, $r['status']);
        $this->assertSame(AtlasScopeValidatorContractService::NEXT_REQUEST_REVIEW, $r['required_next_action']);
        $this->assertFalse($r['completion_allowed']);
        $this->assertSame(1, $r['summary']['forbidden_count']);
    }

    /**
     * Blocked Example: a hot_external path (another active front) => status=blocked,
     * required_next_action=stop, even though nothing is forbidden by name.
     */
    public function test_hot_external_blocks_and_stops(): void
    {
        $r = $this->service()->validate([
            'packet_id' => 'AIP-20260601-0003',
            'allowed_files' => ['app/Services/Ai/Aaeos/Generated/Foo.php'],
            'hot_scopes' => ['runtimes/python/voice_realtime/'],
            'changed_files' => [
                'app/Services/Ai/Aaeos/Generated/Foo.php',
                'runtimes/python/voice_realtime/atlas_voice_agent/main.py',
            ],
        ]);

        $this->assertSame(AtlasScopeValidatorContractService::STATUS_BLOCKED, $r['status']);
        $this->assertSame(AtlasScopeValidatorContractService::NEXT_STOP, $r['required_next_action']);
        $this->assertSame(1, $r['summary']['hot_external_count']);
        $this->assertSame(1, $r['summary']['allowed_count']);
        $this->assertFalse($this->service()->mayCompletionProceed([
            'packet_id' => 'AIP-20260601-0003',
            'hot_scopes' => ['runtimes/python/voice_realtime/'],
            'changed_files' => ['runtimes/python/voice_realtime/atlas_voice_agent/main.py'],
        ]));
    }

    /**
     * Decision: "Unknown writes are unsafe until classified."
     * A path neither allowed nor forbidden => unknown + blocking, status=fail,
     * required_next_action=refresh_packet (the packet contract is stale).
     * Also proves untracked files are NOT ignored (Non-Goal).
     */
    public function test_unknown_untracked_path_blocks_and_requests_refresh(): void
    {
        $r = $this->service()->validate([
            'packet_id' => 'AIP-20260601-0004',
            'allowed_files' => ['app/Services/Ai/Aaeos/Generated/Foo.php'],
            'changed_files' => ['app/Services/Ai/Aaeos/Generated/Foo.php'],
            'untracked_files' => ['app/Services/SomewhereElse/Bar.php'],
        ]);

        $unknown = array_values(array_filter(
            $r['files'],
            static fn ($f) => $f['path'] === 'app/Services/SomewhereElse/Bar.php',
        ))[0];

        $this->assertSame(AtlasScopeValidatorContractService::CLASS_UNKNOWN, $unknown['classification']);
        $this->assertTrue($unknown['blocking']);
        $this->assertSame(AtlasScopeValidatorContractService::STATUS_FAIL, $r['status']);
        $this->assertSame(AtlasScopeValidatorContractService::NEXT_REFRESH_PACKET, $r['required_next_action']);
        $this->assertSame(1, $r['summary']['unknown_count']);
    }

    /**
     * Blocking Files And Actions: a migration path is forbidden UNLESS explicitly
     * allowed. Not in allowed => forbidden+blocking. Listed in allowed => allowed.
     */
    public function test_blocking_migration_needs_explicit_allow(): void
    {
        $blocked = $this->service()->validate([
            'packet_id' => 'AIP-20260601-0005',
            'changed_files' => ['database/migrations/2026_06_01_000000_create_things.php'],
        ]);
        $this->assertSame(
            AtlasScopeValidatorContractService::CLASS_FORBIDDEN,
            $blocked['files'][0]['classification'],
        );
        $this->assertTrue($blocked['files'][0]['blocking']);
        $this->assertSame(AtlasScopeValidatorContractService::STATUS_FAIL, $blocked['status']);

        $allowed = $this->service()->validate([
            'packet_id' => 'AIP-20260601-0005',
            'allowed_files' => ['database/migrations/2026_06_01_000000_create_things.php'],
            'changed_files' => ['database/migrations/2026_06_01_000000_create_things.php'],
        ]);
        $this->assertSame(
            AtlasScopeValidatorContractService::CLASS_ALLOWED,
            $allowed['files'][0]['classification'],
        );
        $this->assertSame(AtlasScopeValidatorContractService::STATUS_PASS, $allowed['status']);
    }

    /**
     * Classification Rules: `generated` is blocking unless the packet allows it;
     * `evidence_only` (reports/logs) is non-blocking. With a --packet present the
     * scope source is reported as work_splitter_packet.
     */
    public function test_generated_blocks_unless_allowed_and_evidence_is_non_blocking(): void
    {
        $r = $this->service()->validate([
            'packet_id' => 'AIP-SPLIT-20260601-0006',
            'requested_packet_id' => 'AIP-SPLIT-20260601-0006',
            'packet_scope_source' => AtlasScopeValidatorContractService::SOURCE_WORK_SPLITTER,
            'generated_globs' => ['docs/code-intel/'],
            'changed_files' => ['docs/code-intel/symbols.json'],
            'untracked_files' => ['storage/reports/scope.report.json'],
        ]);

        $generated = array_values(array_filter(
            $r['files'],
            static fn ($f) => $f['classification'] === AtlasScopeValidatorContractService::CLASS_GENERATED,
        ))[0];
        $evidence = array_values(array_filter(
            $r['files'],
            static fn ($f) => $f['classification'] === AtlasScopeValidatorContractService::CLASS_EVIDENCE_ONLY,
        ))[0];

        $this->assertTrue($generated['blocking']);
        $this->assertFalse($evidence['blocking']);
        $this->assertSame(AtlasScopeValidatorContractService::STATUS_FAIL, $r['status']);
        $this->assertSame(AtlasScopeValidatorContractService::NEXT_REQUEST_REVIEW, $r['required_next_action']);
        $this->assertSame(
            AtlasScopeValidatorContractService::SOURCE_WORK_SPLITTER,
            $r['packet_scope_source'],
        );
    }
}
