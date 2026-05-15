<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsProviderPerformanceLedgerCertification;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Provider Performance Ledger v1 cert + CLI contract tests.
 *
 * Asserts the 9-invariant certification ships available end-to-end and the
 * audit action exposes the new cert payload alongside the legacy certs.
 * Also verifies the three new CLI sub-actions return canonical envelopes.
 */
final class AtlasForgeRivalsProviderPerformanceLedgerCertificationTest extends TestCase
{
    public function test_cert_declares_all_nine_canonical_invariants(): void
    {
        $cert = new AtlasForgeRivalsProviderPerformanceLedgerCertification;
        $payload = $cert->evaluate();

        $this->assertCount(9, AtlasForgeRivalsProviderPerformanceLedgerCertification::REQUIRED_INVARIANTS);
        $this->assertCount(9, $payload['invariants']);
        $this->assertSame(
            'atlas.forge_rivals_provider_performance_ledger_certification.v1',
            $payload['schema_version']
        );
        $this->assertSame(
            'atlas_forge_rivals_provider_performance_ledger_certification',
            $payload['certification_key']
        );
        $this->assertSame('external_rivals_certification', $payload['separated_from']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
    }

    public function test_cert_reaches_available_when_ledger_is_wired(): void
    {
        $payload = (new AtlasForgeRivalsProviderPerformanceLedgerCertification)->evaluate();

        $this->assertSame(
            AtlasForgeRivalsProviderPerformanceLedgerCertification::STATUS_AVAILABLE,
            $payload['status'],
            'ledger cert must report available once services + doc + dispatcher actions are wired'
        );
        $this->assertTrue($payload['ok']);
        foreach ($payload['invariants'] as $name => $row) {
            $this->assertTrue(
                (bool) $row['ok'],
                "invariant '{$name}' must be ok=true. status='".(string) $row['status']."'"
            );
        }
    }

    public function test_external_rivals_remains_blocked_invariant_green(): void
    {
        $payload = (new AtlasForgeRivalsProviderPerformanceLedgerCertification)->evaluate();

        $this->assertTrue(
            (bool) $payload['invariants']['external_rivals_remains_blocked']['ok'],
            'external_rivals_certification MUST remain blocked by this cert'
        );
    }

    public function test_audit_action_exposes_ledger_cert_payload(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'audit',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('provider_performance_ledger_certification', $payload);
        $this->assertSame(
            'atlas.forge_rivals_provider_performance_ledger_certification.v1',
            $payload['provider_performance_ledger_certification']['schema_version']
        );
        $this->assertArrayHasKey('certifications', $payload);
        $this->assertArrayHasKey(
            'atlas_forge_rivals_provider_performance_ledger_certification',
            $payload['certifications']
        );
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
    }

    public function test_ledger_action_returns_canonical_envelope_when_empty(): void
    {
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-ledger-cli-'.bin2hex(random_bytes(4));
        @mkdir($tmp, 0o755, true);
        config(['atlas_rivals.ledger_root' => $tmp]);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'ledger',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ledger', $payload['action']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.provider_performance_ledger.v1', $payload['schema_version']);
        $this->assertSame(0, $payload['total_entries']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['separated_from_external_rivals_certification']);

        $this->purge($tmp);
    }

    public function test_decide_signal_action_returns_advisory_envelope(): void
    {
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-decide-cli-'.bin2hex(random_bytes(4));
        @mkdir($tmp, 0o755, true);
        config(['atlas_rivals.ledger_root' => $tmp]);

        Artisan::call('atlas:forge:rivals', [
            'action' => 'decide-signal',
            '--task-category' => 'frontend',
            '--role' => 'builder',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('decide-signal', $payload['action']);
        $this->assertSame('atlas.forge.rivals.decide_signal.v1', $payload['schema_version']);
        $this->assertSame('insufficient_evidence', $payload['signal']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);

        $this->purge($tmp);
    }

    public function test_ledger_record_action_blocks_without_run_id(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'ledger-record',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('ledger-record', $payload['action']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('run_id_required', $payload['blockers']);
    }

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
