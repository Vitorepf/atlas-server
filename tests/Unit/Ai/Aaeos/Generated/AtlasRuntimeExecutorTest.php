<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasRuntimeExecutorService;
use Tests\TestCase;

/**
 * Pins the documented Runtime Executor laws: the runtime executes inside the
 * receipt and may never decide Kernel fields, amplify scope, run escaping
 * commands, or claim a run without evidence.
 *
 * @see docs/engineering-knowledge-base/system-graph/runtime-executor.md
 */
final class AtlasRuntimeExecutorTest extends TestCase
{
    private AtlasRuntimeExecutorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasRuntimeExecutorService;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'active' => true,
            'allowed_scope' => ['app/Services/**', 'tests/**'],
            'forbidden_scope' => ['app/Services/**/Secrets/**', '.env'],
            'allowed_commands' => ['php artisan test --filter WidgetServiceTest'],
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function request(array $overrides = []): array
    {
        return array_merge([
            'files' => ['app/Services/Example/WidgetService.php'],
            'commands' => ['php artisan test --filter WidgetServiceTest'],
            'decided_fields' => [],
            'evidence_ref' => 'evidence://run/demo',
        ], $overrides);
    }

    /** Fluxo — an authorized, in-scope request executes and hands off to quality gates. */
    public function test_in_scope_request_executes_and_hands_off(): void
    {
        $d = $this->service->decide($this->receipt(), $this->request());

        $this->assertSame('execute', $d['verdict']);
        $this->assertTrue($d['allowed']);
        $this->assertSame('execute_within_contract', $d['reason']);
        // The executor never closes the loop: it forwards to quality gates and
        // names the evidence reference for the ledger.
        $this->assertSame('pending', $d['next']['quality_gates']);
        $this->assertSame('evidence://run/demo', $d['next']['evidence_ledger']);
    }

    /** "Receipt autoriza." — without an active receipt nothing runs. */
    public function test_no_receipt_refuses(): void
    {
        $none = $this->service->decide(null, $this->request());
        $this->assertSame('refuse', $none['verdict']);
        $this->assertSame('no_receipt', $none['reason']);

        $inactive = $this->service->decide($this->receipt(['active' => false]), $this->request());
        $this->assertSame('refuse', $inactive['verdict']);
        $this->assertSame('receipt_not_active', $inactive['reason']);
        // A refused run never advances the pipeline.
        $this->assertSame('not_reached', $inactive['next']['quality_gates']);
    }

    /** Risk "Runtime virar segundo Kernel" — deciding a provider/scope field is refused. */
    public function test_runtime_deciding_kernel_field_refuses(): void
    {
        $d = $this->service->decide($this->receipt(), $this->request([
            'decided_fields' => ['provider' => 'some.model', 'allowed_scope' => ['/']],
        ]));

        $this->assertSame('refuse', $d['verdict']);
        $this->assertSame('runtime_decided_kernel_field', $d['reason']);
        $this->assertContains('provider', $d['detail']['kernel_owned_fields']);
        $this->assertContains('allowed_scope', $d['detail']['kernel_owned_fields']);
    }

    /** Regra para IA — a file outside scope is NOT a silent write; it needs a new receipt. */
    public function test_out_of_scope_file_requires_new_receipt(): void
    {
        $d = $this->service->decide($this->receipt(), $this->request([
            'files' => ['app/Services/Example/WidgetService.php', 'config/app.php'],
        ]));

        $this->assertSame('refuse', $d['verdict']);
        $this->assertSame('scope_amplification_requires_new_receipt', $d['reason']);
        $this->assertSame(['config/app.php'], $d['detail']['out_of_scope_files']);
    }

    /** Risk "Runtime ignorar escopo proibido" — forbidden wins even inside the allowed tree. */
    public function test_forbidden_scope_wins_over_allowed(): void
    {
        $d = $this->service->decide($this->receipt(), $this->request([
            'files' => ['app/Services/Vault/Secrets/ApiKeyService.php'],
        ]));

        $this->assertSame('refuse', $d['verdict']);
        $this->assertSame('scope_amplification_requires_new_receipt', $d['reason']);
        $this->assertContains('app/Services/Vault/Secrets/ApiKeyService.php', $d['detail']['out_of_scope_files']);
    }

    /** Risk "Comando manual escapar do receipt" — an unsanctioned command is refused. */
    public function test_command_outside_receipt_refuses(): void
    {
        $d = $this->service->decide($this->receipt(), $this->request([
            'commands' => ['php artisan test --filter WidgetServiceTest', 'rm -rf build'],
        ]));

        $this->assertSame('refuse', $d['verdict']);
        $this->assertSame('command_escapes_receipt', $d['reason']);
        $this->assertSame(['rm -rf build'], $d['detail']['escaping_commands']);
    }

    public function test_runtime_lists_normalize_without_changing_contract(): void
    {
        $d = $this->service->decide(
            $this->receipt([
                'allowed_scope' => [' app/Services/** ', 'app/Services/**', '', null],
                'forbidden_scope' => [],
                'allowed_commands' => [' php artisan test --filter WidgetServiceTest '],
            ]),
            $this->request([
                'files' => [' app/Services/Example/WidgetService.php '],
                'commands' => [' php artisan test --filter WidgetServiceTest '],
            ]),
        );

        $this->assertSame('execute', $d['verdict']);
        $this->assertSame(['app/Services/**'], $d['detail']['allowed_scope']);
        $this->assertSame(['app/Services/Example/WidgetService.php'], $d['detail']['files']);
        $this->assertSame(['php artisan test --filter WidgetServiceTest'], $d['detail']['commands']);
    }

    /** Risk "terminal parecer real mas nao registrar evidence" — a run with no evidence ref is refused. */
    public function test_execution_without_evidence_reference_refuses(): void
    {
        $d = $this->service->decide($this->receipt(), $this->request(['evidence_ref' => '']));

        $this->assertSame('refuse', $d['verdict']);
        $this->assertSame('evidence_reference_required', $d['reason']);

        // mayExecute is the convenience predicate over the same decision.
        $this->assertFalse($this->service->mayExecute($this->receipt(), $this->request(['evidence_ref' => ''])));
        $this->assertTrue($this->service->mayExecute($this->receipt(), $this->request()));
    }

    /** An empty request (no files, no commands) is a contract-respecting no-op, evidence not required. */
    public function test_empty_request_is_noop_within_contract(): void
    {
        $d = $this->service->decide($this->receipt(), $this->request([
            'files' => [],
            'commands' => [],
            'evidence_ref' => '',
        ]));

        $this->assertSame('execute', $d['verdict']);
        $this->assertSame('noop_within_contract', $d['reason']);
    }
}
