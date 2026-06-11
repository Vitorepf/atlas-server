<?php

namespace App\Services\Ai\Hermes;

use App\Models\HermesSkillCandidate;
use App\Services\Ai\Hermes\Support\HermesStringListNormalizer;
use App\Services\Ai\Skills\Governance\HermesSkillProvisionGate;
use App\Services\Ai\Skills\Governance\SkillPackPromotionGate;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Governed skill provisioning — closes the loop from a PROMOTED Atlas skill to
 * an on-disk Hermes `SKILL.md` that the engine can consume via
 * `hermes chat --skills <csv>`, WITHOUT ever letting Hermes author, discover or
 * self-install a skill.
 *
 * Atlas is the canonical skill authority. A skill reaches disk only when its
 * upstream SkillPackPromotionGate verdict is `promotion_approved`, the persisted
 * record carries `promotion_allowed === true`, AND {@see HermesSkillProvisionGate}
 * approves the Atlas-owned target dir. Everything else is skipped with a reason.
 *
 * The hub catalog path (`hermes skills list`) is INGEST-ONLY: every entry lands
 * as a quarantined {@see HermesSkillCandidate} with `install_allowed = false` and
 * is NEVER installed. A newly-seen hub skill is a candidate for operator review,
 * not an auto-enabled capability.
 *
 * Secrets never reach disk: `required_environment_variables` are rendered by
 * NAME only, and provenance markers / receipts carry sha256 digests only.
 */
class HermesSkillProvisioner
{
    use HermesAdapterReceipt;

    public function __construct(
        private readonly Filesystem $files,
        private readonly HermesSkillProvisionGate $gate,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $promotedSkills
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<string,mixed>
     */
    public function provision(array $promotedSkills, array $mission, array $invocation, string $skillProvisionPolicy, string $externalDir): array
    {
        $records = array_values(array_filter($promotedSkills, static fn (mixed $record): bool => is_array($record)));
        $atlasOwned = $this->gate->isAtlasOwned($externalDir);

        $receipt = $this->baseReceipt($skillProvisionPolicy, $externalDir, $atlasOwned, count($records));

        if ($records === []) {
            $receipt['status'] = 'no_promoted_skills';

            return $this->withReceiptHash($receipt);
        }

        if ($skillProvisionPolicy !== 'atlas_adapter') {
            $receipt['status'] = 'skipped_by_policy';
            $receipt['skipped_count'] = count($records);
            $receipt['skipped'] = $this->skippedAll($records, 'skill_provision_policy_not_atlas_adapter');

            return $this->withReceiptHash($receipt);
        }

        if (! $atlasOwned) {
            $receipt['status'] = 'skipped_by_gate';
            $receipt['skipped_count'] = count($records);
            $receipt['skipped'] = $this->skippedAll($records, 'external_dir_not_atlas_owned');

            return $this->withReceiptHash($receipt);
        }

        $provisionedSkillNames = [];

        foreach ($records as $record) {
            $skillId = $this->skillId($record);
            $promotionVerdict = $this->promotionVerdict($record);
            $verdictDecision = is_string($promotionVerdict['promotion_decision'] ?? null)
                ? $promotionVerdict['promotion_decision']
                : '';

            if ($verdictDecision !== 'promotion_approved') {
                $receipt['skipped_count']++;
                $receipt['skipped'][] = ['skill_id' => $skillId, 'reason' => 'promotion_not_approved'];

                continue;
            }

            if (($record['promotion_allowed'] ?? null) !== true) {
                $receipt['skipped_count']++;
                $receipt['skipped'][] = ['skill_id' => $skillId, 'reason' => 'promotion_not_allowed'];

                continue;
            }

            $receipt['eligible_count']++;

            $gateVerdict = $this->gate->evaluate($record, $promotionVerdict, $externalDir);
            if (($gateVerdict['provision_decision'] ?? null) !== 'provision_approved') {
                $reason = array_key_first($gateVerdict['failed_checks'] ?? []);
                $receipt['skipped_count']++;
                $receipt['skipped'][] = [
                    'skill_id' => $skillId,
                    'reason' => 'provision_gate_blocked'.($reason !== null ? ':'.$reason : ''),
                ];

                continue;
            }

            $written = $this->writeSkillMarkdown($record, $externalDir);
            $receipt['provisioned_count']++;
            $receipt['provisioned_skill_ids'][] = $skillId;
            $receipt['provisioned_skills'][] = [
                'name' => $written['name'],
                'sha256' => $written['sha256'],
                'path' => $written['path'],
            ];
            $provisionedSkillNames[] = $written['name'];
        }

        if ($receipt['provisioned_count'] > 0) {
            $receipt['provision_allowed_now'] = true;
            $receipt['status'] = $receipt['skipped_count'] > 0 ? 'partially_provisioned' : 'provisioned';
        } else {
            $receipt['status'] = 'skipped_by_gate';
        }

        $requested = $this->requestedSelection($mission);
        $selection = $this->selectMissionSkills($requested, $provisionedSkillNames);
        $receipt['skills_selection'] = [
            'requested' => $requested,
            'provisioned' => $provisionedSkillNames,
            'selected' => $selection['selection'],
            'dropped' => $selection['dropped'],
        ];

        // Stamp provenance markers with the sealed receipt hash now that the
        // receipt body is final (so the on-disk marker proves THIS provision).
        $sealed = $this->withReceiptHash($receipt);
        $this->stampReceiptHash($sealed['provisioned_skills'], $externalDir, $sealed['receipt_hash']);

        return $sealed;
    }

    /**
     * Mission can never request a non-provisioned skill: the selection is the
     * intersection of requested and provisioned names; everything else drops.
     *
     * @param  array<int,mixed>  $requestedSkills
     * @param  array<int,string>  $provisionedSkillNames
     * @return array{selection:array<int,string>,dropped:array<int,string>,reason:string}
     */
    public function selectMissionSkills(array $requestedSkills, array $provisionedSkillNames): array
    {
        $provisioned = array_values(array_unique(array_filter(
            $provisionedSkillNames,
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        )));

        $requested = array_values(array_unique(array_filter(
            array_map(static fn (mixed $name): ?string => is_string($name) ? trim($name) : null, $requestedSkills),
            static fn (?string $name): bool => $name !== null && $name !== '',
        )));

        $selection = [];
        $dropped = [];
        foreach ($requested as $name) {
            if (in_array($name, $provisioned, true)) {
                $selection[] = $name;
            } else {
                $dropped[] = $name;
            }
        }

        return [
            'selection' => $selection,
            'dropped' => $dropped,
            'reason' => $dropped === []
                ? 'all_requested_skills_provisioned'
                : 'dropped_skills_not_provisioned_by_atlas',
        ];
    }

    /**
     * `hermes skills list` entries -> quarantined candidate rows. NEVER installs.
     *
     * @param  array<int,array<string,mixed>>  $hubEntries
     * @return array<string,mixed>
     */
    public function ingestHubCatalog(array $hubEntries, string $hubIngestPolicy): array
    {
        $entries = array_values(array_filter($hubEntries, static fn (mixed $entry): bool => is_array($entry)));

        $receipt = [
            'schema_version' => 'atlas.hermes.skill_hub_ingest_receipt.v1',
            'adapter' => 'hermes_skill_provisioner',
            'hub_ingest_policy' => $hubIngestPolicy,
            'canonical_skill_authority' => 'atlas',
            'capability_gate' => 'HermesSkillProvisionGate',
            'auto_install_allowed' => false,
            'install_allowed_now' => false,
            'entry_count' => count($entries),
            'ingested_count' => 0,
            'duplicate_count' => 0,
            'skipped_count' => 0,
            'ingested_candidate_ids' => [],
            'duplicate_candidate_ids' => [],
            'skipped' => [],
            'status' => 'no_hub_entries',
        ];

        if ($entries === []) {
            return $this->withReceiptHash($receipt);
        }

        if ($hubIngestPolicy !== 'atlas_adapter') {
            $receipt['status'] = 'skipped_by_policy';
            $receipt['skipped_count'] = count($entries);
            $receipt['skipped'] = $this->skippedHub($entries, 'hub_ingest_policy_not_atlas_adapter');

            return $this->withReceiptHash($receipt);
        }

        if (! DatabaseTableAvailability::has('hermes_skill_candidates')) {
            $receipt['status'] = 'capability_gate_unavailable';
            $receipt['skipped_count'] = count($entries);
            $receipt['skipped'] = $this->skippedHub($entries, 'hermes_skill_candidates_table_missing');

            return $this->withReceiptHash($receipt);
        }

        foreach ($entries as $entry) {
            $hubSkillId = $this->string($entry['id'] ?? $entry['skill_id'] ?? null, 191);
            $hubSkillName = $this->string($entry['name'] ?? $entry['skill_name'] ?? null, 191);
            if ($hubSkillId === null && $hubSkillName === null) {
                $receipt['skipped_count']++;
                $receipt['skipped'][] = ['hub_skill_id' => null, 'reason' => 'missing_hub_skill_identity'];

                continue;
            }

            $candidateHash = $this->hubCandidateHash($entry, $hubSkillId, $hubSkillName);
            $existing = HermesSkillCandidate::query()->where('candidate_hash', $candidateHash)->first();
            if ($existing instanceof HermesSkillCandidate) {
                $receipt['duplicate_count']++;
                $receipt['duplicate_candidate_ids'][] = $existing->id;

                continue;
            }

            $row = HermesSkillCandidate::query()->create([
                'candidate_hash' => $candidateHash,
                'status' => 'quarantined_for_review',
                'source' => 'hermes_skills_hub',
                'hub_skill_id' => $hubSkillId,
                'hub_skill_name' => $hubSkillName,
                'install_allowed' => false,
                'review_required' => true,
                'payload_json' => $this->hubPayload($entry, $hubSkillId, $hubSkillName),
                'evidence_refs_json' => $this->hubEvidence($entry, $hubIngestPolicy),
                'capability_gate_json' => $this->hubCapabilityGate(),
                'reviewed_at' => null,
                'expires_at' => now()->addDays(90),
            ]);

            $receipt['ingested_count']++;
            $receipt['ingested_candidate_ids'][] = $row->id;
        }

        $receipt['status'] = $receipt['ingested_count'] > 0
            ? 'ingested_as_quarantined'
            : ($receipt['duplicate_count'] > 0 ? 'deduplicated' : 'skipped_by_gate');

        return $this->withReceiptHash($receipt);
    }

    /**
     * Removes ONLY Atlas-provisioned dirs (provenance marker present). Refuses
     * operator/hub skills that Atlas never wrote.
     *
     * @return array<string,mixed>
     */
    public function uninstall(string $skillName, string $externalDir): array
    {
        $atlasOwned = $this->gate->isAtlasOwned($externalDir);
        $receipt = [
            'schema_version' => 'atlas.hermes.skill_provision_receipt.v1',
            'adapter' => 'hermes_skill_provisioner',
            'skill_provision_policy' => 'uninstall',
            'canonical_skill_authority' => 'atlas',
            'promotion_gate' => 'SkillPackPromotionGate',
            'provision_gate' => 'HermesSkillProvisionGate',
            'provision_allowed_now' => false,
            'external_dir' => $externalDir,
            'external_dir_is_atlas_owned' => $atlasOwned,
            'candidate_count' => 1,
            'eligible_count' => 0,
            'provisioned_count' => 0,
            'skipped_count' => 0,
            'uninstalled_count' => 0,
            'provisioned_skill_ids' => [],
            'provisioned_skills' => [],
            'skipped' => [],
            'skills_selection' => ['requested' => [], 'provisioned' => [], 'selected' => [], 'dropped' => []],
            'status' => 'skipped_by_gate',
        ];

        $name = $this->skillSlug($skillName);
        if ($name === null) {
            $receipt['skipped'][] = ['skill_id' => $skillName, 'reason' => 'invalid_skill_name'];
            $receipt['skipped_count'] = 1;

            return $this->withReceiptHash($receipt);
        }

        if (! $atlasOwned) {
            $receipt['skipped'][] = ['skill_id' => $name, 'reason' => 'external_dir_not_atlas_owned'];
            $receipt['skipped_count'] = 1;

            return $this->withReceiptHash($receipt);
        }

        $dir = $this->skillDir($externalDir, $name);
        $marker = $dir.DIRECTORY_SEPARATOR.'.atlas-provisioned.json';

        if (! $this->files->isDirectory($dir) || ! $this->files->exists($marker)) {
            $receipt['skipped'][] = ['skill_id' => $name, 'reason' => 'not_atlas_provisioned'];
            $receipt['skipped_count'] = 1;

            return $this->withReceiptHash($receipt);
        }

        $this->files->deleteDirectory($dir);

        $receipt['uninstalled_count'] = 1;
        $receipt['status'] = 'uninstalled';

        return $this->withReceiptHash($receipt);
    }

    /**
     * Renders SKILL.md frontmatter from the Atlas skill record and drops the
     * `.atlas-provisioned.json` provenance marker. `required_environment_variables`
     * are emitted by NAME only — never values.
     *
     * @param  array<string,mixed>  $record
     * @return array{name:string,sha256:string,path:string}
     */
    private function writeSkillMarkdown(array $record, string $externalDir): array
    {
        $name = $this->skillSlug($this->skillName($record)) ?? 'atlas-skill';
        $category = $this->skillSlug($this->string($this->hermesMeta($record)['category'] ?? null, 80) ?? 'atlas') ?? 'atlas';
        $dir = $this->skillDir($externalDir, $name, $category);
        $this->files->ensureDirectoryExists($dir);

        $markdown = $this->renderSkillMarkdown($record, $name);
        $path = $dir.DIRECTORY_SEPARATOR.'SKILL.md';
        $this->files->put($path, $markdown);

        $sha = hash('sha256', $markdown);

        $this->files->put(
            $dir.DIRECTORY_SEPARATOR.'.atlas-provisioned.json',
            json_encode([
                'skill_id' => $this->skillId($record),
                'promotion_gate_ref' => SkillPackPromotionGate::SCHEMA_VERSION,
                'provisioned_at' => now()->toJSON(),
                'receipt_hash' => null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        return ['name' => $name, 'sha256' => $sha, 'path' => $path];
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function renderSkillMarkdown(array $record, string $name): string
    {
        $meta = $this->hermesMeta($record);
        $description = $this->string($record['description'] ?? null, 700) ?? 'Atlas-governed Hermes skill.';
        $version = $this->semver($record['version'] ?? null);
        $platforms = HermesStringListNormalizer::bounded($record['platforms'] ?? ['local'], 8, 40) ?: ['local'];
        $tags = HermesStringListNormalizer::bounded($meta['tags'] ?? null, 16, 60);
        $category = $this->string($meta['category'] ?? null, 80) ?? 'atlas';
        $fallbackFor = HermesStringListNormalizer::bounded($meta['fallback_for_toolsets'] ?? null, 16, 80);
        $requiresToolsets = HermesStringListNormalizer::bounded($meta['requires_toolsets'] ?? null, 16, 80);
        $config = HermesStringListNormalizer::bounded($meta['config'] ?? null, 24, 120);
        // NAME only — never values. Strip any "=value" an upstream payload smuggled in.
        $envVars = array_values(array_map(
            static fn (string $entry): string => trim(explode('=', $entry, 2)[0]),
            HermesStringListNormalizer::bounded($record['required_environment_variables'] ?? null, 24, 191),
        ));
        $envVars = array_values(array_filter($envVars, static fn (string $v): bool => $v !== ''));

        $lines = ['---'];
        $lines[] = 'name: '.$this->yamlScalar($name);
        $lines[] = 'description: '.$this->yamlScalar($description);
        $lines[] = 'version: '.$this->yamlScalar($version);
        $lines[] = 'platforms:';
        foreach ($platforms as $platform) {
            $lines[] = '  - '.$this->yamlScalar($platform);
        }
        $lines[] = 'metadata:';
        $lines[] = '  hermes:';
        $lines[] = '    tags:'.($tags === [] ? ' []' : '');
        foreach ($tags as $tag) {
            $lines[] = '      - '.$this->yamlScalar($tag);
        }
        $lines[] = '    category: '.$this->yamlScalar($category);
        $lines[] = '    fallback_for_toolsets:'.($fallbackFor === [] ? ' []' : '');
        foreach ($fallbackFor as $toolset) {
            $lines[] = '      - '.$this->yamlScalar($toolset);
        }
        $lines[] = '    requires_toolsets:'.($requiresToolsets === [] ? ' []' : '');
        foreach ($requiresToolsets as $toolset) {
            $lines[] = '      - '.$this->yamlScalar($toolset);
        }
        $lines[] = '    config:'.($config === [] ? ' []' : '');
        foreach ($config as $configKey) {
            $lines[] = '      - '.$this->yamlScalar($configKey);
        }
        $lines[] = '    canonical_skill_authority: '.$this->yamlScalar('atlas');
        $lines[] = '    provisioned_by: '.$this->yamlScalar('hermes_skill_provisioner');
        $lines[] = 'required_environment_variables:'.($envVars === [] ? ' []' : '');
        foreach ($envVars as $envVar) {
            $lines[] = '  - '.$this->yamlScalar($envVar);
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '# '.$name;
        $lines[] = '';
        $lines[] = $description;
        $lines[] = '';
        $lines[] = '> Canonical skill authority: Atlas. Provisioned from a promoted Atlas skill';
        $lines[] = '> record; Hermes is the consuming engine only. Do not edit by hand — changes';
        $lines[] = '> are overwritten on the next governed provision.';
        $lines[] = '';
        $instructions = $this->string($record['instructions'] ?? $record['purpose'] ?? null, 4000);
        if ($instructions !== null) {
            $lines[] = $instructions;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int,array<string,mixed>>  $provisionedSkills
     */
    private function stampReceiptHash(array $provisionedSkills, string $externalDir, string $receiptHash): void
    {
        foreach ($provisionedSkills as $skill) {
            $name = is_string($skill['name'] ?? null) ? $skill['name'] : null;
            if ($name === null) {
                continue;
            }

            $dir = $this->skillDir($externalDir, $name);
            $marker = $dir.DIRECTORY_SEPARATOR.'.atlas-provisioned.json';
            if (! $this->files->exists($marker)) {
                continue;
            }

            $decoded = json_decode((string) $this->files->get($marker), true);
            if (! is_array($decoded)) {
                continue;
            }

            $decoded['receipt_hash'] = $receiptHash;
            $this->files->put(
                $marker,
                json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * @param  array<string,mixed>  $mission
     * @return array<int,string>
     */
    private function requestedSelection(array $mission): array
    {
        $requested = data_get($mission, 'requested_skills', data_get($mission, 'skills', []));

        return is_array($requested) ? array_values($requested) : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function baseReceipt(string $policy, string $externalDir, bool $atlasOwned, int $candidateCount): array
    {
        return [
            'schema_version' => 'atlas.hermes.skill_provision_receipt.v1',
            'adapter' => 'hermes_skill_provisioner',
            'skill_provision_policy' => $policy,
            'canonical_skill_authority' => 'atlas',
            'promotion_gate' => 'SkillPackPromotionGate',
            'provision_gate' => 'HermesSkillProvisionGate',
            'provision_allowed_now' => false,
            'external_dir' => $externalDir,
            'external_dir_is_atlas_owned' => $atlasOwned,
            'candidate_count' => $candidateCount,
            'eligible_count' => 0,
            'provisioned_count' => 0,
            'skipped_count' => 0,
            'uninstalled_count' => 0,
            'provisioned_skill_ids' => [],
            'provisioned_skills' => [],
            'skipped' => [],
            'skills_selection' => ['requested' => [], 'provisioned' => [], 'selected' => [], 'dropped' => []],
            'status' => 'no_promoted_skills',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $records
     * @return array<int,array<string,string>>
     */
    private function skippedAll(array $records, string $reason): array
    {
        return collect($records)
            ->map(fn (array $record): array => ['skill_id' => $this->skillId($record), 'reason' => $reason])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<int,array<string,?string>>
     */
    private function skippedHub(array $entries, string $reason): array
    {
        return collect($entries)
            ->map(fn (array $entry): array => [
                'hub_skill_id' => $this->string($entry['id'] ?? $entry['skill_id'] ?? null, 191),
                'reason' => $reason,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function skillId(array $record): string
    {
        return $this->string($record['skill_id'] ?? $record['id'] ?? null, 191)
            ?? ($this->skillSlug($this->skillName($record)) ?? 'hermes_skill_candidate_unknown');
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function skillName(array $record): ?string
    {
        return $this->string($record['name'] ?? null, 160);
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function promotionVerdict(array $record): array
    {
        $verdict = $record['promotion_gate_verdict'] ?? $record['promotion_gate'] ?? null;

        return is_array($verdict) ? $verdict : [];
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function hermesMeta(array $record): array
    {
        $meta = data_get($record, 'metadata.hermes', $record['hermes'] ?? null);

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function hubCandidateHash(array $entry, ?string $hubSkillId, ?string $hubSkillName): string
    {
        $source = $this->string($entry['source'] ?? $entry['registry'] ?? null, 80) ?: 'agentskills.io';

        return hash('sha256', 'hub|'.($hubSkillId ?? '').'|'.($hubSkillName ?? '').'|'.$source);
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private function hubPayload(array $entry, ?string $hubSkillId, ?string $hubSkillName): array
    {
        return [
            'schema_version' => 'atlas.hermes.skill_hub_candidate.v1',
            'hub_skill_id' => $hubSkillId,
            'hub_skill_name' => $hubSkillName,
            'description' => $this->string($entry['description'] ?? null, 700),
            'category' => $this->string($entry['category'] ?? null, 80),
            'tags' => HermesStringListNormalizer::bounded($entry['tags'] ?? null, 16, 60),
            'source' => $this->string($entry['source'] ?? $entry['registry'] ?? null, 80) ?: 'agentskills.io',
            'gate_status' => 'quarantined_for_atlas_capability_review',
            'review_status' => 'pending',
            'install_allowed_now' => false,
            'auto_install_allowed' => false,
            'install_requires_atlas_capability_gate' => true,
            'capability_gate' => 'HermesSkillProvisionGate',
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<int,array<string,mixed>>
     */
    private function hubEvidence(array $entry, string $hubIngestPolicy): array
    {
        return [[
            'kind' => 'hermes_skills_hub_entry',
            'hub_skill_id' => $this->string($entry['id'] ?? $entry['skill_id'] ?? null, 191),
            'hub_ingest_policy' => $hubIngestPolicy,
            'install_allowed_now' => false,
            'auto_install_allowed' => false,
        ]];
    }

    /**
     * @return array<string,mixed>
     */
    private function hubCapabilityGate(): array
    {
        return [
            'gate' => 'HermesSkillProvisionGate',
            'schema_version' => HermesSkillProvisionGate::SCHEMA_VERSION,
            'install_allowed_now' => false,
            'auto_install_allowed' => false,
            'requires' => [
                'promotion_approved',
                'record.promotion_allowed===true',
                'external_dir_atlas_owned',
                'danger=>operator_authority_required',
            ],
        ];
    }

    private function skillDir(string $externalDir, string $name, ?string $category = null): string
    {
        $base = rtrim($externalDir, DIRECTORY_SEPARATOR);

        if ($category !== null && $category !== '') {
            return $base.DIRECTORY_SEPARATOR.$category.DIRECTORY_SEPARATOR.$name;
        }

        // Uninstall path: discover the skill dir under any single-level category.
        $direct = $base.DIRECTORY_SEPARATOR.$name;
        if ($this->files->isDirectory($direct) && $this->files->exists($direct.DIRECTORY_SEPARATOR.'.atlas-provisioned.json')) {
            return $direct;
        }

        if ($this->files->isDirectory($base)) {
            foreach ($this->files->directories($base) as $categoryDir) {
                $candidate = rtrim($categoryDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
                if ($this->files->isDirectory($candidate)) {
                    return $candidate;
                }
            }
        }

        return $direct;
    }

    private function skillSlug(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $slug = Str::slug($value);

        return $slug === '' ? null : Str::limit($slug, 80, '');
    }

    private function semver(mixed $value): string
    {
        $version = $this->string($value, 24);
        if ($version !== null && preg_match('/^\d+\.\d+\.\d+$/', $version) === 1) {
            return $version;
        }

        return '1.0.0';
    }

    private function yamlScalar(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
