<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use RuntimeException;

/**
 * Executes a wave through injected campaign ports and forwards only normalized
 * evidence refs to Rivals. It owns no provider, workspace, claim or route state.
 */
final class AtlasExternalBrainDomainWaveCampaignRunner
{
    public const SCHEMA = 'atlas.external_brain.domain_wave_campaign_runner.v1';

    /** @var callable|null */
    private $campaignExecutor;
    /** @var callable|null */
    private $rivalsEvidenceSink;

    public function __construct(?callable $campaignExecutor = null, ?callable $rivalsEvidenceSink = null)
    {
        $this->campaignExecutor = $campaignExecutor;
        $this->rivalsEvidenceSink = $rivalsEvidenceSink;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function run(array $input): array
    {
        $mode = strtolower(trim((string) ($input['campaign_mode'] ?? 'hermetic')));
        if (! in_array($mode, ['hermetic', 'private'], true)) return $this->blocked('campaign_mode_invalid');
        if ($mode === 'private' && (($input['authorized'] ?? false) !== true || trim((string) ($input['authorization_receipt'] ?? '')) === '')) {
            return $this->blocked('private_campaign_authorization_required');
        }
        if (! is_callable($this->campaignExecutor)) return $this->blocked('campaign_executor_port_missing');
        if (! is_callable($this->rivalsEvidenceSink)) return $this->blocked('rivals_evidence_sink_missing');

        try {
            $campaign = ($this->campaignExecutor)($input);
            if (! is_array($campaign)) return $this->blocked('campaign_executor_invalid_result');
            $refs = array_values(array_unique(array_filter(array_map('strval', (array) ($campaign['evidence_refs'] ?? [])), static fn (string $ref): bool => trim($ref) !== '')));
            if ($refs === []) return $this->blocked('campaign_evidence_refs_missing');
            $rivals = ($this->rivalsEvidenceSink)([
                'domain_id' => (string) ($input['domain_id'] ?? ''),
                'wave_version' => (string) ($input['wave_version'] ?? ''),
                'campaign_mode' => $mode,
                'evidence_refs' => $refs,
                'authorization_receipt' => $mode === 'private' ? (string) $input['authorization_receipt'] : null,
            ]);
            if (! is_array($rivals) || ($rivals['accepted'] ?? false) !== true) return $this->blocked('rivals_evidence_rejected');

            return [
                'schema' => self::SCHEMA, 'status' => 'executed', 'campaign_mode' => $mode,
                'evidence_refs' => $refs, 'rivals' => $rivals,
                'claim_allowed' => false, 'mutates_claims_or_routes' => false,
            ];
        } catch (\Throwable $exception) {
            return $this->blocked('campaign_execution_failed:'.$exception->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason): array
    {
        return ['schema' => self::SCHEMA, 'status' => 'blocked', 'blockers' => [$reason], 'claim_allowed' => false, 'mutates_claims_or_routes' => false];
    }
}
