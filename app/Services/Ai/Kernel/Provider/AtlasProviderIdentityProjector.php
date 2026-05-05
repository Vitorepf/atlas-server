<?php

namespace App\Services\Ai\Kernel\Provider;

use App\Services\Ai\AiSkillStore;
use Throwable;

class AtlasProviderIdentityProjector
{
    public function __construct(private readonly AiSkillStore $skills) {}

    public function forProvider(string $providerId): IdentityFragment
    {
        $providerId = trim($providerId) !== '' ? trim($providerId) : 'unknown_provider';

        try {
            $master = $this->skills->masterPrompt();
            $text = $this->providerProjectionText($providerId, $master->body);

            return IdentityFragment::fromText(
                identityId: $this->identityId($providerId),
                text: $text,
                metadata: [
                    'source' => 'atlas_ai_master_prompt_projection',
                    'source_path' => $master->path,
                    'source_hash' => $master->contentHash,
                    'provider_id' => $providerId,
                    'integration_stage' => 'canonical_identity_projection',
                    'fallback' => false,
                ],
            );
        } catch (Throwable $exception) {
            return IdentityFragment::fromText(
                identityId: $this->identityId($providerId),
                text: $this->providerProjectionText($providerId, $this->fallbackMasterIdentity()),
                metadata: [
                    'source' => 'atlas_ai_master_prompt_fallback',
                    'provider_id' => $providerId,
                    'integration_stage' => 'canonical_identity_projection',
                    'fallback' => true,
                    'fallback_reason' => $exception::class,
                ],
            );
        }
    }

    private function identityId(string $providerId): string
    {
        return 'atlas-ai.provider.'.$providerId.'.identity.v1';
    }

    private function providerProjectionText(string $providerId, string $masterIdentity): string
    {
        return trim(implode("\n\n", [
            'Atlas AI Provider Identity Projection v1',
            'Provider: '.$providerId,
            'Invariant: provider is an execution engine; Atlas AI remains the identity, policy, memory, and decision authority.',
            'Canonical Atlas identity:',
            trim($masterIdentity),
        ]));
    }

    private function fallbackMasterIdentity(): string
    {
        return implode("\n", [
            '# Atlas',
            'Atlas e o sistema. O modelo de IA e apenas o avatar momentaneo.',
            'Atlas nao terceiriza criterio para o provider. Claude, Codex, GPT, Gemini ou qualquer outro modelo vestem esta identidade.',
            'Quando faltar dado, diferencie evidencia, inferencia e opiniao.',
        ]);
    }
}
