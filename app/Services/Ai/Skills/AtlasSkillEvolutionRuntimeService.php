<?php

declare(strict_types=1);

namespace App\Services\Ai\Skills;

use App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasSkillEvolutionRuntimeService
{
    public const PROPOSAL_SCHEMA = 'atlas.skill_evolution.proposal.v1';

    public const REFACTOR_SCHEMA = 'atlas.skill_evolution.refactor_plan.v1';

    public const CERTIFICATION_SCHEMA = 'atlas.skill_evolution.certification.v1';

    public function __construct(
        private readonly SkillDiscoveryService $discovery,
        private readonly SkillManifestParser $parser,
        // GOD-DEBULK 3b: IntelligenceFactory quarantined (archive/app/Services/Ai/IntelligenceFactory,
        // blueprint 91c334a27 §2.2) — nullable so the container degrades to null instead of crashing.
        private readonly ?AtlasIntelligenceFactoryRuntimeService $factory = null,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function propose(array $input): array
    {
        $workspace = $this->workspace($input['workspace'] ?? null);
        $objective = $this->stringValue($input['objective'] ?? $input['summary'] ?? null) ?? 'Improve Atlas skill behavior from verified outcome.';
        $domain = $this->stringValue($input['domain'] ?? null) ?? 'programming';
        $flowId = $this->stringValue($input['flow_id'] ?? null) ?? 'atlas_dev';
        $evidenceRefs = AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['evidence_refs'] ?? []);
        $existing = $this->matchingSkill($workspace, $objective, $domain, $flowId);
        $skillName = $existing?->name ?? $this->skillName($objective, $domain, $flowId);
        $draft = $this->draftSkillMarkdown($skillName, $objective, $domain, $flowId, $evidenceRefs, $existing);
        $certification = $this->certifyDraft($draft, $evidenceRefs, $workspace, $existing);
        $capability = null;

        if (($certification['status'] ?? null) === 'passed') {
            $capability = $this->factory?->registerCapability([
                'capability_key' => 'skill-'.$skillName,
                'name' => Str::headline($skillName),
                'capability_type' => 'skill_candidate',
                'domain' => $domain,
                'flow_id' => $flowId,
                'description' => 'Skill candidate proposed from verified Atlas outcome.',
                'input_schema' => ['type' => 'object', 'required' => ['task', 'workspace']],
                'output_schema' => ['type' => 'object', 'required' => ['plan', 'evidence_refs']],
                'use_when' => ['task matches '.$domain.'/'.$flowId.' and evidence supports this skill'],
                'do_not_use_when' => ['missing evidence refs', 'skill is stale', 'operator rejected candidate'],
                'safety_policy' => $this->safetyPolicy(),
                'evidence_refs' => $evidenceRefs,
            ]);
        }

        $payload = [
            'schema_version' => self::PROPOSAL_SCHEMA,
            'status' => ($certification['status'] ?? null) === 'passed' ? 'candidate' : 'blocked',
            'action' => $existing instanceof SkillManifest ? 'refactor_existing_skill' : 'create_new_skill',
            'workspace_hash' => MissionCanonicalHash::sha256(['workspace' => $workspace]),
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'skill' => [
                'name' => $skillName,
                'existing_path' => $existing?->path,
                'source_tier' => $existing?->sourceTier,
                'draft_sha256' => hash('sha256', $draft),
            ],
            'draft_markdown' => $draft,
            'certification' => $certification,
            'intelligence_factory_capability' => $capability,
            'evidence_refs' => $evidenceRefs,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['proposal_hash'] = MissionCanonicalHash::sha256(array_diff_key($payload, ['draft_markdown' => true]));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function refactorPlan(array $input): array
    {
        $workspace = $this->workspace($input['workspace'] ?? null);
        $name = $this->stringValue($input['skill'] ?? $input['name'] ?? null);
        $skills = collect($this->discovery->discoverAll($workspace));
        $skill = $name !== null ? $skills->firstWhere('name', $name) : null;

        if (! $skill instanceof SkillManifest) {
            return $this->blocked(self::REFACTOR_SCHEMA, 'missing_skill', 'Skill refactor requires an existing discovered skill.');
        }

        $checks = [
            ['id' => 'has_description', 'status' => $skill->description !== '' ? 'pass' : 'fail'],
            ['id' => 'has_body', 'status' => trim($skill->body) !== '' ? 'pass' : 'fail'],
            ['id' => 'not_quarantined', 'status' => ! $skill->quarantined ? 'pass' : 'fail'],
            ['id' => 'has_resource_or_procedure', 'status' => $skill->resourceFiles() !== [] || str_contains(Str::lower($skill->body), 'procedure') ? 'pass' : 'warn'],
            ['id' => 'has_evidence_language', 'status' => str_contains(Str::lower($skill->body), 'evidence') || str_contains(Str::lower($skill->body), 'test') ? 'pass' : 'warn'],
        ];
        $recommendations = [];
        foreach ($checks as $check) {
            if ($check['status'] === 'pass') {
                continue;
            }
            $recommendations[] = match ($check['id']) {
                'has_resource_or_procedure' => 'Adicionar procedimento objetivo e recursos/scripts quando a skill executar verificacao local.',
                'has_evidence_language' => 'Adicionar secao de evidencia: comandos, testes, quando usar e quando nao usar.',
                default => 'Corrigir '.$check['id'].' antes de promover esta skill.',
            };
        }

        $payload = [
            'schema_version' => self::REFACTOR_SCHEMA,
            'status' => collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail') ? 'blocked' : ($recommendations === [] ? 'ready' : 'watch'),
            'skill' => $skill->activationMetadata(),
            'checks' => $checks,
            'recommendations' => $recommendations,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['refactor_plan_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function certifyDraft(string $draft, array $evidenceRefs, string $workspace, ?SkillManifest $existing): array
    {
        $tempRoot = sys_get_temp_dir().'/atlas-skill-evolution-'.Str::random(12);
        $skillName = $this->extractDraftName($draft) ?? 'atlas-skill-candidate';
        $path = $tempRoot.'/'.$skillName.'/SKILL.md';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $draft);

        $checks = [];
        try {
            $manifest = $this->parser->parse($path, 'candidate');
            $checks[] = ['id' => 'manifest_parseable', 'status' => 'pass'];
            $checks[] = ['id' => 'valid_name', 'status' => $manifest->warnings === [] ? 'pass' : 'warn', 'warnings' => $manifest->warnings];
            $checks[] = ['id' => 'not_quarantined', 'status' => ! $manifest->quarantined ? 'pass' : 'fail', 'issues' => $manifest->securityIssues];
            $checks[] = ['id' => 'has_evidence_refs', 'status' => $evidenceRefs !== [] ? 'pass' : 'fail'];
            $checks[] = ['id' => 'does_not_override_protected_skill', 'status' => $existing instanceof SkillManifest && $existing->sourceTier === 'builtin' ? 'warn' : 'pass'];
            $checks[] = ['id' => 'workspace_not_written', 'status' => ! File::exists($workspace.'/.atlas/skills/'.$manifest->name.'/SKILL.md') ? 'pass' : 'warn'];
        } catch (\Throwable $exception) {
            $checks[] = ['id' => 'manifest_parseable', 'status' => 'fail', 'reason' => $exception->getMessage()];
        } finally {
            File::deleteDirectory($tempRoot);
        }

        $failed = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail');
        $payload = [
            'schema_version' => self::CERTIFICATION_SCHEMA,
            'status' => $failed ? 'blocked' : 'passed',
            'checks' => $checks,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'provider_calls_made' => false,
            'auto_installs_skill' => false,
            'auto_promotes_default_skill' => false,
            'requires_evidence_refs' => true,
            'requires_operator_review_before_activation' => true,
            'workspace_skill_trust_required' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $schema, string $id, string $reason): array
    {
        return [
            'schema_version' => $schema,
            'status' => 'blocked',
            'blockers' => [['id' => $id, 'reason' => $reason]],
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    private function matchingSkill(string $workspace, string $objective, string $domain, string $flowId): ?SkillManifest
    {
        $needle = Str::lower($objective.' '.$domain.' '.$flowId);

        return collect($this->discovery->discoverAll($workspace))
            ->first(function (SkillManifest $skill) use ($needle, $domain, $flowId): bool {
                if (data_get($skill->metadata, 'atlas.domain') === $domain && data_get($skill->metadata, 'atlas.flow_id') === $flowId) {
                    return true;
                }

                $haystack = Str::lower($skill->name.' '.$skill->description.' '.$skill->body);

                $stopwords = ['about', 'after', 'atlas', 'before', 'bundle', 'existing', 'from', 'improve', 'outcome', 'skill', 'that', 'this', 'without'];
                $matches = collect(preg_split('/[^a-z0-9]+/', $needle) ?: [])
                    ->filter(fn (string $word): bool => mb_strlen($word) >= 5)
                    ->reject(fn (string $word): bool => in_array($word, $stopwords, true))
                    ->take(12)
                    ->filter(fn (string $word): bool => str_contains($haystack, $word))
                    ->count();

                return $matches >= 2;
            });
    }

    private function draftSkillMarkdown(string $skillName, string $objective, string $domain, string $flowId, array $evidenceRefs, ?SkillManifest $existing): string
    {
        $description = $existing instanceof SkillManifest
            ? 'Refactor candidate for '.$existing->name.' based on verified Atlas outcome.'
            : 'Candidate Atlas skill generated from verified execution outcome.';
        $evidence = $evidenceRefs === [] ? 'pending-evidence' : implode(', ', $evidenceRefs);
        $objectiveHash = MissionCanonicalHash::sha256(['objective' => $objective]);

        return <<<MD
---
name: {$skillName}
description: {$description}
metadata:
  atlas.trust_level: community
  atlas.requires_evidence: true
  atlas.domain: {$domain}
  atlas.flow_id: {$flowId}
---

# {$skillName}

Use this skill only when the task matches the declared domain and flow and the operator has reviewed the evidence.

## Procedure

1. Load the owner docs and current code before proposing changes.
2. Keep the context pack small and cite the evidence refs.
3. Run the required verification commands before claiming success.
4. Record outcome evidence back into AEMOR.

## Evidence

- objective_hash: {$objectiveHash}
- refs: {$evidence}

## Do Not Use When

- Evidence refs are missing.
- The task is outside {$domain}/{$flowId}.
- A newer certified skill supersedes this candidate.
MD;
    }

    private function skillName(string $objective, string $domain, string $flowId): string
    {
        $base = Str::slug($domain.'-'.$flowId.'-'.$this->keywordLabel($objective));
        $base = preg_replace('/-+/', '-', $base) ?: 'atlas-skill-candidate';

        return substr(trim($base, '-'), 0, 58) ?: 'atlas-skill-candidate';
    }

    private function keywordLabel(string $objective): string
    {
        $words = collect(preg_split('/[^a-zA-Z0-9]+/', Str::lower($objective)) ?: [])
            ->filter(fn (string $word): bool => mb_strlen($word) >= 4)
            ->reject(fn (string $word): bool => in_array($word, ['atlas', 'para', 'with', 'from', 'that', 'esta', 'essa', 'como', 'mais'], true))
            ->take(4)
            ->values()
            ->all();

        return $words === [] ? 'outcome-skill' : implode('-', $words);
    }

    /**
     * @return array<string,mixed>
     */
    private function safetyPolicy(): array
    {
        return [
            'auto_install_allowed' => false,
            'auto_default_allowed' => false,
            'operator_review_required' => true,
            'evidence_refs_required' => true,
            'skill_discovery_must_pass' => true,
        ];
    }

    private function extractDraftName(string $draft): ?string
    {
        if (preg_match('/^name:\s*([a-z0-9-]+)/m', $draft, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function workspace(mixed $workspace): string
    {
        $candidate = $this->stringValue($workspace) ?? base_path();
        $resolved = realpath($candidate);

        return $resolved !== false ? $resolved : $candidate;
    }
}
