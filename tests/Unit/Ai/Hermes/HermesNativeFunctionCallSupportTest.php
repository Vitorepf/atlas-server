<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Hermes;

use App\Services\Ai\Hermes\HermesNativeFcCapabilityAttestor;
use App\Services\Ai\Hermes\HermesNativeFunctionCallSupport;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use Tests\TestCase;

final class HermesNativeFunctionCallSupportTest extends TestCase
{
    public function test_declaration_names_atlas_apply_patch(): void
    {
        $tool = HermesNativeFunctionCallSupport::atlasApplyPatchDeclaration();
        self::assertSame('atlas_apply_patch', $tool['name']);
        self::assertSame(['path', 'mode', 'next'], $tool['parameters']['required']);
    }

    public function test_parses_compact_tool_calls_json(): void
    {
        $text = json_encode([
            'tool_calls' => [[
                'name' => 'atlas_apply_patch',
                'arguments' => [
                    'path' => 'app/StructuredReply.php',
                    'mode' => 'modify',
                    'next' => "<?php\nreturn ['ok' => true];\n",
                ],
            ]],
        ], JSON_UNESCAPED_SLASHES);

        $calls = HermesNativeFunctionCallSupport::parseToolCallsFromText((string) $text);
        self::assertCount(1, $calls);
        self::assertSame('atlas_apply_patch', $calls[0]['function']['name']);
        self::assertSame('app/StructuredReply.php', $calls[0]['function']['arguments']['path']);
    }

    public function test_attestor_ignores_model_fc_suffix_when_disabled(): void
    {
        config(['atlas.ai.providers.hermes_cli.native_fc.enabled' => false]);

        self::assertSame([], HermesNativeFcCapabilityAttestor::capabilitiesFor('hermes_cli', 'kimi-k2.7-FC'));
        self::assertSame(
            'free_form',
            (new ProviderLock('hermes_cli', 'kimi-k2.7-FC'))
                ->responseContractFor('tool_use_function_calling', '', HermesNativeFcCapabilityAttestor::capabilitiesFor('hermes_cli', 'kimi-k2.7-FC'))['channel'],
        );
    }

    public function test_attestor_promotes_native_fc_when_enabled_for_hermes_only(): void
    {
        config(['atlas.ai.providers.hermes_cli.native_fc.enabled' => true]);

        self::assertSame(
            [ProviderLock::RESPONSE_CHANNEL_NATIVE_FUNCTION_CALL],
            HermesNativeFcCapabilityAttestor::capabilitiesFor('hermes_cli', 'anything'),
        );
        self::assertSame([], HermesNativeFcCapabilityAttestor::capabilitiesFor('openai', 'gpt-4o'));
        self::assertSame(
            'native_function_call',
            (new ProviderLock('hermes_cli', 'kimi-k2.7-FC'))
                ->responseContractFor(
                    'tool_use_function_calling',
                    '',
                    HermesNativeFcCapabilityAttestor::capabilitiesFor('hermes_cli', 'kimi-k2.7-FC'),
                )['channel'],
        );
    }

    public function test_job_requests_native_fc_lift_from_response_contract(): void
    {
        self::assertTrue(HermesNativeFcCapabilityAttestor::jobRequestsNativeFcLift([
            'response_contract' => ['channel' => 'native_function_call', 'name' => 'atlas_apply_patch'],
        ]));
        self::assertTrue(HermesNativeFcCapabilityAttestor::jobRequestsNativeFcLift([
            'hermes' => ['lift_native_function_calls' => true],
        ]));
        self::assertFalse(HermesNativeFcCapabilityAttestor::jobRequestsNativeFcLift([
            'response_contract' => ['channel' => 'free_form'],
        ]));
    }
}
