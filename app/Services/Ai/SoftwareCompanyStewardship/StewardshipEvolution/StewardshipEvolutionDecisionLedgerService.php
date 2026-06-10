<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Stewardship Evolution · append-only operator decision ledger (AP-731).
 *
 * Persists AP-731 operator decision receipts as local JSONL so Portfolio,
 * Executive and Self-Expanding proposals are reviewable and replayable. The
 * only side effect is append-only local state under storage/.
 */
class StewardshipEvolutionDecisionLedgerService
{
    public const LEDGER_SCHEMA = 'atlas.software_company_stewardship.evolution_decision_ledger.v1';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly StewardshipEvolutionOperatorDecisionService $decisions,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/evolution_decisions')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/evolution_decisions';
    }

    public function ledgerFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->areaSlug($areaId).'.jsonl';
    }

    /**
     * Build and append an operator decision receipt. Idempotent by decision_id.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $receipt = $this->decisions->decide($this->sanitizedInput($input));
        $areaId = (string) ($receipt['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID);

        $existing = $this->findInFile($this->ledgerFilePath($areaId), (string) $receipt['decision_id']);
        if ($existing !== null) {
            return $existing;
        }

        $record = $receipt + [
            'ledger_schema_version' => self::LEDGER_SCHEMA,
            'recorded_at' => $receipt['decided_at'] ?? null,
            'claim_policy' => $this->claimPolicy(),
        ];

        AppendOnlyJsonlStore::append($this->ledgerFilePath($areaId), $record);

        return $record;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function replay(string $decisionId): ?array
    {
        foreach ($this->areaFiles() as $file) {
            $found = $this->findInFile($file, $decisionId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function listDecisions(?string $areaId = null): array
    {
        $files = $areaId === null || trim($areaId) === ''
            ? $this->areaFiles()
            : [$this->ledgerFilePath($areaId)];

        $records = [];
        $corrupted = 0;
        foreach ($files as $file) {
            [$valid, $bad] = $this->readRecords($file);
            $records = array_merge($records, $valid);
            $corrupted += $bad;
        }

        $summaries = [];
        foreach ($records as $record) {
            $summaries[] = [
                'decision_id' => (string) ($record['decision_id'] ?? ''),
                'area_id' => (string) ($record['area_id'] ?? ''),
                'portfolio_id' => (string) ($record['portfolio_id'] ?? ''),
                'target_type' => (string) ($record['target_type'] ?? ''),
                'target_id' => (string) ($record['target_id'] ?? ''),
                'target_hash' => (string) ($record['target_hash'] ?? ''),
                'operator_actor' => (string) ($record['operator_actor'] ?? ''),
                'decision' => (string) ($record['decision'] ?? ''),
                'risk_level' => (string) ($record['risk_level'] ?? ''),
                'recorded_at' => (string) ($record['recorded_at'] ?? ''),
                'decision_hash' => (string) ($record['decision_hash'] ?? ''),
            ];
        }

        usort($summaries, static fn (array $a, array $b): int => strcmp((string) $a['recorded_at'], (string) $b['recorded_at']));

        return [
            'schema_version' => self::LEDGER_SCHEMA,
            'ap_contract' => 'AP-731',
            'area_id' => $areaId,
            'decision_count' => count($summaries),
            'corrupted_line_count' => $corrupted,
            'decisions' => $summaries,
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function sanitizedInput(array $input): array
    {
        $allowed = [
            'area_id',
            'portfolio_id',
            'target_type',
            'target_id',
            'target_hash',
            'target_payload',
            'operator_actor',
            'decision',
            'rationale',
            'risk',
            'risk_level',
        ];

        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'writes_local_state' => true,
            'persistence' => 'jsonl_append_only',
            'read_only_over_repo' => true,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'secrets_in_payload' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findInFile(string $path, string $decisionId): ?array
    {
        [$records] = $this->readRecords($path);
        foreach ($records as $record) {
            if ((string) ($record['decision_id'] ?? '') === $decisionId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function readRecords(string $path): array
    {
        return AppendOnlyJsonlStore::readWhereWithRejectedCount(
            $path,
            static fn (array $row): bool => isset($row['decision_id']) && is_string($row['decision_id']),
        );
    }

    /**
     * @return list<string>
     */
    private function areaFiles(): array
    {
        return AppendOnlyJsonlStore::jsonlFilesInDirectory($this->storageDir());
    }

    private function areaSlug(string $areaId): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($areaId))) ?? '';

        return $slug !== '' ? $slug : StewardshipEvolutionReadModelService::DEFAULT_AREA_ID;
    }
}
