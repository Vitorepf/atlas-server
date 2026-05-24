<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Security;

use App\Models\AtlasDevConfirmationToken;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenKeyMissingException;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenResult;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class ConfirmationTokenServiceTest extends TestCase
{
    private ConfirmationTokenService $service;

    private Capsule $db;

    private Repository $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Capsule;
        $this->db->addConnection([
            'database' => ':memory:',
            'driver' => 'sqlite',
            'prefix' => '',
        ]);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();

        $this->createTokensTable();
        $this->config = new Repository([
            'app' => [
                'key' => 'base64:'.base64_encode(str_repeat('A', 32)),
            ],
            'atlas_dev' => [
                'confirmation_token' => [
                    'plaintext_bytes' => 32,
                    'ttl_seconds' => 300,
                ],
            ],
        ]);
        // F-09: HMAC requires a real APP_KEY ≥ 32 bytes. Tests use the same
        // base64:... shape Laravel emits via `php artisan key:generate`.
        $this->service = new ConfirmationTokenService($this->config);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->db->schema()->dropIfExists('atlas_dev_confirmation_tokens');
        parent::tearDown();
    }

    public function test_issue_returns_plaintext_and_never_persists_it_plaintext_in_db(): void
    {
        $issue = $this->service->issue('run-001', 'task-contract-aaa', 'cli');

        $this->assertNotSame('', $issue->plaintext);
        $this->assertGreaterThanOrEqual(32, strlen($issue->plaintext));
        $this->assertNotNull($issue->tokenId);

        $row = AtlasDevConfirmationToken::query()->firstOrFail();
        $this->assertNotSame($issue->plaintext, $row->token_hash);
        $this->assertSame($this->service->hashSecret($issue->plaintext), $row->token_hash);
        $this->assertNull($row->used_at);
        $this->assertSame('cli', $row->surface_id);
    }

    public function test_valid_token_consumes_exactly_once(): void
    {
        $issue = $this->service->issue('run-002', 'task-contract-bbb', 'cli');

        $first = $this->service->validateAndConsume('run-002', 'task-contract-bbb', $issue->plaintext);
        $this->assertTrue($first->ok);
        $this->assertSame(ConfirmationTokenResult::REASON_OK, $first->reason);

        $row = AtlasDevConfirmationToken::query()->firstOrFail();
        $this->assertNotNull($row->used_at);

        $second = $this->service->validateAndConsume('run-002', 'task-contract-bbb', $issue->plaintext);
        $this->assertFalse($second->ok);
        $this->assertSame(ConfirmationTokenResult::REASON_ALREADY_USED, $second->reason);
    }

    public function test_expired_token_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-16 10:00:00'));
        $this->config->set('atlas_dev.confirmation_token.ttl_seconds', 60);

        $issue = $this->service->issue('run-003', 'task-contract-ccc', 'cli');

        Carbon::setTestNow(Carbon::parse('2026-05-16 10:05:00'));

        $result = $this->service->validateAndConsume('run-003', 'task-contract-ccc', $issue->plaintext);

        $this->assertFalse($result->ok);
        $this->assertSame(ConfirmationTokenResult::REASON_EXPIRED, $result->reason);
    }

    public function test_run_id_mismatch_fails(): void
    {
        $issue = $this->service->issue('run-004', 'task-contract-ddd', 'cli');

        $result = $this->service->validateAndConsume('run-other', 'task-contract-ddd', $issue->plaintext);

        $this->assertFalse($result->ok);
        $this->assertSame(ConfirmationTokenResult::REASON_RUN_ID_MISMATCH, $result->reason);

        $this->assertNull(AtlasDevConfirmationToken::query()->firstOrFail()->used_at, 'token must not be consumed on mismatch');
    }

    public function test_task_contract_mismatch_fails(): void
    {
        $issue = $this->service->issue('run-005', 'task-contract-eee', 'cli');

        $result = $this->service->validateAndConsume('run-005', 'task-contract-different', $issue->plaintext);

        $this->assertFalse($result->ok);
        $this->assertSame(ConfirmationTokenResult::REASON_TASK_CONTRACT_MISMATCH, $result->reason);
    }

    public function test_unknown_token_fails(): void
    {
        $this->service->issue('run-006', 'task-contract-fff', 'cli');

        $result = $this->service->validateAndConsume('run-006', 'task-contract-fff', 'fabricated-plaintext-token');

        $this->assertFalse($result->ok);
        $this->assertSame(ConfirmationTokenResult::REASON_NOT_FOUND, $result->reason);
    }

    public function test_empty_inputs_return_invalid(): void
    {
        $this->assertSame(
            ConfirmationTokenResult::REASON_INVALID,
            $this->service->validateAndConsume('', 'ctx', 'pt')->reason,
        );
        $this->assertSame(
            ConfirmationTokenResult::REASON_INVALID,
            $this->service->validateAndConsume('run', '', 'pt')->reason,
        );
        $this->assertSame(
            ConfirmationTokenResult::REASON_INVALID,
            $this->service->validateAndConsume('run', 'ctx', '')->reason,
        );
    }

    public function test_issue_rejects_empty_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->issue('', 'ctx', 'cli');
    }

    public function test_token_hash_is_constant_time_comparable_via_hash_equals(): void
    {
        // Documents that we intentionally use the HMAC + hash_equals path.
        $service = new ConfirmationTokenService($this->config);
        $this->assertSame($service->hashSecret('abc'), $service->hashSecret('abc'));
        $this->assertNotSame($service->hashSecret('abc'), $service->hashSecret('abcd'));
    }

    public function test_issue_fails_closed_when_app_key_is_missing(): void
    {
        // F-09: no public fallback. Issue MUST throw, not return a "token".
        $this->config->set('app.key', '');

        $this->expectException(ConfirmationTokenKeyMissingException::class);
        $this->service->issue('run-key-1', 'task-contract-key', 'cli');
    }

    public function test_issue_fails_closed_when_app_key_is_short(): void
    {
        // F-09: anything shorter than 32 decoded bytes is treated as missing.
        $this->config->set('app.key', 'base64:'.base64_encode('too-short'));

        $this->expectException(ConfirmationTokenKeyMissingException::class);
        $this->service->issue('run-key-2', 'task-contract-key', 'cli');
    }

    public function test_validate_returns_key_missing_reason_when_app_key_absent(): void
    {
        // First issue under a real key so a row exists.
        $issue = $this->service->issue('run-key-3', 'task-contract-key', 'cli');

        // Then drop the key — any subsequent consume MUST fail closed and
        // never accidentally succeed by hashing under the same default.
        $this->config->set('app.key', '');

        $result = $this->service->validateAndConsume('run-key-3', 'task-contract-key', $issue->plaintext);

        $this->assertFalse($result->ok);
        $this->assertSame(ConfirmationTokenResult::REASON_KEY_MISSING, $result->reason);

        // And the token row remains unconsumed — Run is blocked, not "burned".
        $this->assertNull(
            AtlasDevConfirmationToken::query()->firstOrFail()->used_at,
            'F-09: a missing APP_KEY must NOT burn the token row.',
        );
    }

    public function test_validate_with_raw_app_key_below_32_bytes_fails_closed(): void
    {
        $issue = $this->service->issue('run-key-4', 'task-contract-key', 'cli');

        $this->config->set('app.key', 'short-raw-key');

        $result = $this->service->validateAndConsume('run-key-4', 'task-contract-key', $issue->plaintext);

        $this->assertFalse($result->ok);
        $this->assertSame(ConfirmationTokenResult::REASON_KEY_MISSING, $result->reason);
    }

    public function test_issue_persists_compact_sdd_hash_pin(): void
    {
        $pin = str_repeat('c', 64);
        $issue = $this->service->issue('run-pin-1', 'task-contract-pin', 'cli', $pin);
        $this->assertNotSame('', $issue->plaintext);

        $row = AtlasDevConfirmationToken::query()->where('run_id', 'run-pin-1')->firstOrFail();
        $this->assertSame($pin, $row->compact_sdd_hash);
    }

    public function test_validate_returns_pinned_compact_sdd_hash_on_success(): void
    {
        $pin = str_repeat('d', 64);
        $issue = $this->service->issue('run-pin-2', 'task-contract-pin-2', 'cli', $pin);

        $result = $this->service->validateAndConsume('run-pin-2', 'task-contract-pin-2', $issue->plaintext);

        $this->assertTrue($result->ok);
        $this->assertSame($pin, $result->expectedCompactSddHash);
    }

    public function test_validate_returns_null_pin_for_legacy_row_without_hash(): void
    {
        $issue = $this->service->issue('run-pin-3', 'task-contract-pin-3', 'cli');

        $result = $this->service->validateAndConsume('run-pin-3', 'task-contract-pin-3', $issue->plaintext);

        $this->assertTrue($result->ok);
        $this->assertNull($result->expectedCompactSddHash);
    }

    public function test_client_supplied_hash_cannot_override_server_pin(): void
    {
        // Sanity: validateAndConsume only takes the plaintext; nothing in the
        // public surface allows the client to override the pinned hash. The
        // ok() result always reflects what the SERVER stored at issue() time.
        $pin = str_repeat('e', 64);
        $issue = $this->service->issue('run-pin-4', 'task-contract-pin-4', 'cli', $pin);

        // No matter the API caller's input, only (runId, taskContractHash,
        // plaintext) are passed in. The pin lives server-side.
        $result = $this->service->validateAndConsume('run-pin-4', 'task-contract-pin-4', $issue->plaintext);

        $this->assertTrue($result->ok);
        $this->assertSame($pin, $result->expectedCompactSddHash);
        $this->assertNotSame('forged-by-client', $result->expectedCompactSddHash);
    }

    private function createTokensTable(): void
    {
        $this->db->schema()->dropIfExists('atlas_dev_confirmation_tokens');
        $this->db->schema()->create('atlas_dev_confirmation_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('run_id', 128)->index();
            $table->string('token_hash', 128)->unique();
            $table->string('surface_id', 80)->index();
            $table->string('task_contract_hash', 128)->index();
            $table->string('compact_sdd_hash', 128)->nullable()->index();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }
}
