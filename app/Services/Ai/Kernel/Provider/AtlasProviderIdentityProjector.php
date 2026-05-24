<?php

namespace App\Services\Ai\Kernel\Provider;

use App\Services\Ai\AiSkillStore;
use Throwable;

class AtlasProviderIdentityProjector
{
    public function __construct(
        private readonly AiSkillStore $skills,
        private readonly AgentBehaviorContract $agentBehavior,
    ) {}

    public function forProvider(string $providerId): IdentityFragment
    {
        $providerId = trim($providerId) !== '' ? trim($providerId) : 'unknown_provider';

        try {
            if (! (bool) config('atlas.ai.provider_identity.use_vault_master_prompt', false)) {
                return $this->fallbackIdentity($providerId, 'vault_master_prompt_projection_disabled');
            }

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
                    'agent_behavior_contract' => $this->agentBehavior->toArray(),
                    'fallback' => false,
                ],
            );
        } catch (Throwable $exception) {
            return $this->fallbackIdentity($providerId, $exception::class);
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
            'Behavior contract:',
            $this->agentBehavior->text(),
        ]));
    }

    private function fallbackIdentity(string $providerId, string $reason): IdentityFragment
    {
        return IdentityFragment::fromText(
            identityId: $this->identityId($providerId),
            text: $this->providerProjectionText($providerId, $this->fallbackMasterIdentity()),
            metadata: [
                'source' => 'atlas_ai_master_prompt_fallback',
                'provider_id' => $providerId,
                'integration_stage' => 'canonical_identity_projection',
                'agent_behavior_contract' => $this->agentBehavior->toArray(),
                'fallback' => true,
                'fallback_reason' => $reason,
            ],
        );
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
