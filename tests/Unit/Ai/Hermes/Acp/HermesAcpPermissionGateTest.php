<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Hermes\Acp;

use App\Services\Ai\Hermes\Acp\HermesAcpPermissionGate;
use Tests\TestCase;

class HermesAcpPermissionGateTest extends TestCase
{
    private function gate(): HermesAcpPermissionGate
    {
        return new HermesAcpPermissionGate();
    }

    /**
     * A canonical mid-prompt `session/request_permission` request, as the agent
     * sends it over ACP: an id, options[] of {optionId,name,kind}, and a toolCall
     * with a write target under `locations[]`.
     *
     * @param  array<int,array<string,mixed>>  $options
     * @return array<string,mixed>
     */
    private function request(array $options, string $targetPath = '/repo/src/file.php'): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 42,
            'method' => 'session/request_permission',
            'params' => [
                'options' => $options,
                'toolCall' => [
                    'toolCallId' => 'tc_1',
                    'title' => 'Edit file',
                    'kind' => 'edit',
                    'locations' => [
                        ['path' => $targetPath],
                    ],
                    'rawInput' => ['file_path' => $targetPath, 'content' => 'x'],
                ],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function allowDenyOptions(): array
    {
        return [
            ['optionId' => 'allow-once', 'name' => 'Allow once', 'kind' => 'allow_once'],
            ['optionId' => 'allow-always', 'name' => 'Allow always', 'kind' => 'allow_always'],
            ['optionId' => 'reject-once', 'name' => 'Reject', 'kind' => 'reject_once'],
        ];
    }

    /**
     * @param  array<int,string>  $allowed
     * @param  array<int,string>  $forbidden
     * @return array<string,mixed>
     */
    private function scope(array $allowed = ['/repo'], array $forbidden = ['/repo/.git']): array
    {
        return ['allowed_paths' => $allowed, 'forbidden_paths' => $forbidden];
    }

    // ---- happy path -------------------------------------------------------

    public function test_write_mode_in_scope_allows_once_and_selects_allow_option(): void
    {
        $receipt = $this->gate()->decide($this->request($this->allowDenyOptions()), $this->scope(), 'write');

        $this->assertSame('atlas.hermes.acp_permission_decision.v1', $receipt['schema_version']);
        $this->assertSame('allow', $receipt['decision']);
        $this->assertSame('allow-once', $receipt['option_id']);
        $this->assertSame('write', $receipt['permission_mode']);
        $this->assertTrue($receipt['path_in_scope']);
        $this->assertSame('in_scope_allow_once', $receipt['reason']);
        $this->assertSame('allowed', $receipt['status']);
    }

    public function test_danger_mode_in_scope_also_allows(): void
    {
        $receipt = $this->gate()->decide($this->request($this->allowDenyOptions()), $this->scope(), 'danger');

        $this->assertSame('allow', $receipt['decision']);
        $this->assertSame('allow-once', $receipt['option_id']);
        $this->assertSame('danger', $receipt['permission_mode']);
    }

    public function test_allow_option_resolved_by_id_when_kind_absent(): void
    {
        // No kind hints — must fall back to id/name "allow" detection and skip
        // the "always" variant.
        $options = [
            ['optionId' => 'opt_reject', 'name' => 'No'],
            ['optionId' => 'opt_allow_always', 'name' => 'Allow always'],
            ['optionId' => 'opt_allow', 'name' => 'Allow this once'],
        ];

        $receipt = $this->gate()->decide($this->request($options), $this->scope(), 'write');

        $this->assertSame('allow', $receipt['decision']);
        $this->assertSame('opt_allow', $receipt['option_id']);
    }

    // ---- fail-closed: read mode ------------------------------------------

    public function test_read_mode_always_denies_even_with_valid_allow_option_in_scope(): void
    {
        $receipt = $this->gate()->decide($this->request($this->allowDenyOptions()), $this->scope(), 'read');

        $this->assertSame('deny', $receipt['decision']);
        $this->assertNull($receipt['option_id']);
        $this->assertSame('read', $receipt['permission_mode']);
        $this->assertSame('permission_mode_read', $receipt['reason']);
        $this->assertSame('denied', $receipt['status']);
    }

    public function test_unknown_permission_mode_normalizes_to_read_and_denies(): void
    {
        foreach (['', 'ADMIN', 'yolo', 'WRITE ', 'execute'] as $mode) {
            $receipt = $this->gate()->decide($this->request($this->allowDenyOptions()), $this->scope(), $mode);

            // "WRITE " trims/lowercases to a valid mode; everything else folds to read.
            if (trim(strtolower($mode)) === 'write') {
                $this->assertSame('write', $receipt['permission_mode'], "mode [$mode]");
                $this->assertSame('allow', $receipt['decision'], "mode [$mode]");

                continue;
            }

            $this->assertSame('read', $receipt['permission_mode'], "mode [$mode]");
            $this->assertSame('deny', $receipt['decision'], "mode [$mode]");
            $this->assertSame('permission_mode_read', $receipt['reason'], "mode [$mode]");
        }
    }

    // ---- fail-closed: options absent/empty -------------------------------

    public function test_empty_options_deny_in_write_mode(): void
    {
        $receipt = $this->gate()->decide($this->request([]), $this->scope(), 'write');

        $this->assertSame('deny', $receipt['decision']);
        $this->assertSame('no_permission_options', $receipt['reason']);
        $this->assertSame(0, $receipt['option_count']);
        $this->assertNull($receipt['options_hash']);
    }

    public function test_absent_options_key_denies(): void
    {
        $request = [
            'id' => 7,
            'params' => [
                'toolCall' => ['locations' => [['path' => '/repo/x.php']]],
            ],
        ];

        $receipt = $this->gate()->decide($request, $this->scope(), 'write');

        $this->assertSame('deny', $receipt['decision']);
        $this->assertSame('no_permission_options', $receipt['reason']);
    }

    public function test_options_present_but_none_has_usable_id_denies(): void
    {
        $options = [
            ['name' => 'Allow', 'kind' => 'allow_once'], // no optionId
            ['optionId' => '   ', 'name' => 'blank'],
        ];

        $receipt = $this->gate()->decide($this->request($options), $this->scope(), 'write');

        $this->assertSame('deny', $receipt['decision']);
        $this->assertSame('no_permission_options', $receipt['reason']);
        $this->assertSame(0, $receipt['option_count']);
    }

    // ---- fail-closed: out-of-scope escalates (never silently allows) ------

    public function test_path_outside_allowed_paths_escalates(): void
    {
        $request = $this->request($this->allowDenyOptions(), '/etc/passwd');

        $receipt = $this->gate()->decide($request, $this->scope(allowed: ['/repo']), 'write');

        $this->assertSame('escalate', $receipt['decision']);
        $this->assertNull($receipt['option_id']);
        $this->assertSame('path_outside_allowed_paths', $receipt['reason']);
        $this->assertSame('escalated', $receipt['status']);
        $this->assertFalse($receipt['path_in_scope']);
    }

    public function test_path_in_forbidden_paths_escalates_even_if_under_allowed(): void
    {
        $request = $this->request($this->allowDenyOptions(), '/repo/.git/config');

        $receipt = $this->gate()->decide($request, $this->scope(allowed: ['/repo'], forbidden: ['/repo/.git']), 'write');

        $this->assertSame('escalate', $receipt['decision']);
        $this->assertSame('path_in_forbidden_paths', $receipt['reason']);
    }

    public function test_no_allowed_paths_grant_escalates(): void
    {
        $request = $this->request($this->allowDenyOptions(), '/repo/src/x.php');

        $receipt = $this->gate()->decide($request, ['allowed_paths' => [], 'forbidden_paths' => []], 'write');

        $this->assertSame('escalate', $receipt['decision']);
        $this->assertSame('out_of_scope_no_allowed_paths', $receipt['reason']);
    }

    public function test_tool_call_without_inspectable_path_escalates_under_path_gated_scope(): void
    {
        $request = [
            'id' => 1,
            'params' => [
                'options' => $this->allowDenyOptions(),
                'toolCall' => ['title' => 'Run', 'kind' => 'execute'], // no path anywhere
            ],
        ];

        $receipt = $this->gate()->decide($request, $this->scope(allowed: ['/repo']), 'write');

        $this->assertSame('escalate', $receipt['decision']);
        $this->assertSame('tool_call_has_no_inspectable_path', $receipt['reason']);
    }

    // ---- fail-closed: no clearly-allowing option -------------------------

    public function test_in_scope_but_no_clear_allow_option_escalates(): void
    {
        $options = [
            ['optionId' => 'reject-once', 'name' => 'Reject', 'kind' => 'reject_once'],
            ['optionId' => 'allow-always', 'name' => 'Allow always', 'kind' => 'allow_always'],
        ];

        $receipt = $this->gate()->decide($this->request($options), $this->scope(), 'write');

        $this->assertSame('escalate', $receipt['decision']);
        $this->assertNull($receipt['option_id']);
        $this->assertSame('no_clear_allow_option', $receipt['reason']);
        $this->assertTrue($receipt['path_in_scope']);
    }

    public function test_reject_named_option_is_never_chosen_as_allow(): void
    {
        // An option literally named to look like "allow" inside a reject word
        // must not flip to allow.
        $options = [
            ['optionId' => 'deny', 'name' => 'Disallow'],
        ];

        $receipt = $this->gate()->decide($this->request($options), $this->scope(), 'write');

        $this->assertSame('escalate', $receipt['decision']);
        $this->assertSame('no_clear_allow_option', $receipt['reason']);
    }

    // ---- malformed frames -------------------------------------------------

    public function test_bare_params_without_envelope_is_accepted(): void
    {
        // Transport may hand us the inner `params` directly.
        $bareParams = [
            'options' => $this->allowDenyOptions(),
            'toolCall' => ['locations' => [['path' => '/repo/a.php']]],
        ];

        $receipt = $this->gate()->decide($bareParams, $this->scope(), 'write');

        $this->assertSame('allow', $receipt['decision']);
        $this->assertSame('allow-once', $receipt['option_id']);
    }

    public function test_completely_empty_request_denies(): void
    {
        $receipt = $this->gate()->decide([], $this->scope(), 'write');

        $this->assertSame('deny', $receipt['decision']);
        $this->assertSame('no_permission_options', $receipt['reason']);
        $this->assertNull($receipt['tool_call_hash']);
        $this->assertNull($receipt['options_hash']);
        $this->assertNull($receipt['request_id']);
    }

    public function test_non_array_options_and_toolcall_are_ignored_and_deny(): void
    {
        $request = [
            'id' => 'abc',
            'params' => [
                'options' => 'not-an-array',
                'toolCall' => 'not-an-array',
            ],
        ];

        $receipt = $this->gate()->decide($request, $this->scope(), 'write');

        $this->assertSame('deny', $receipt['decision']);
        $this->assertSame('no_permission_options', $receipt['reason']);
        $this->assertSame(0, $receipt['option_count']);
        $this->assertSame('abc', $receipt['request_id']);
    }

    public function test_garbage_option_entries_are_skipped(): void
    {
        $options = [
            'string-not-array',
            42,
            ['optionId' => 'allow-once', 'kind' => 'allow_once', 'name' => 'Allow once'],
        ];

        $receipt = $this->gate()->decide($this->request($options), $this->scope(), 'write');

        $this->assertSame(1, $receipt['option_count']);
        $this->assertSame('allow', $receipt['decision']);
        $this->assertSame('allow-once', $receipt['option_id']);
    }

    // ---- governance invariants -------------------------------------------

    public function test_every_receipt_carries_sovereignty_invariants_and_hashes_not_raw(): void
    {
        $request = $this->request($this->allowDenyOptions(), '/repo/secret.php');
        $receipt = $this->gate()->decide($request, $this->scope(), 'write');

        $this->assertSame('atlas', $receipt['authority']);
        $this->assertFalse($receipt['hermes_acp_can_decide']);
        $this->assertSame('hermes_acp_permission_gate', $receipt['gate']);

        // Hashes present, raw tool input / option names absent.
        $this->assertIsString($receipt['tool_call_hash']);
        $this->assertIsString($receipt['options_hash']);
        $encoded = json_encode($receipt);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('secret.php', $encoded);
        $this->assertStringNotContainsString('Allow once', $encoded);
        $this->assertStringNotContainsString('content', $encoded);
        $this->assertArrayHasKey('receipt_hash', $receipt);

        // schema_version is the FIRST key.
        $this->assertSame('schema_version', array_key_first($receipt));
    }

    public function test_receipt_hash_is_deterministic_for_identical_input(): void
    {
        $request = $this->request($this->allowDenyOptions());

        $a = $this->gate()->decide($request, $this->scope(), 'write');
        $b = $this->gate()->decide($request, $this->scope(), 'write');

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
        $this->assertSame($a, $b);
    }

    public function test_receipt_hash_changes_when_decision_changes(): void
    {
        $allow = $this->gate()->decide($this->request($this->allowDenyOptions()), $this->scope(), 'write');
        $deny = $this->gate()->decide($this->request($this->allowDenyOptions()), $this->scope(), 'read');

        $this->assertNotSame($allow['receipt_hash'], $deny['receipt_hash']);
        $this->assertSame('allow', $allow['decision']);
        $this->assertSame('deny', $deny['decision']);
    }

    public function test_receipt_hash_matches_recomputed_sha256_over_unhashed_body(): void
    {
        $receipt = $this->gate()->decide($this->request($this->allowDenyOptions()), $this->scope(), 'write');

        $stored = $receipt['receipt_hash'];
        $body = $receipt;
        unset($body['receipt_hash']);
        $expected = hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->assertSame($expected, $stored);
    }
}
