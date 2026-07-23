<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Ports;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ClaudeCliProvider;
use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Throwable;

/**
 * REAL Frontier generator backed by the Claude Code CLI on Opus 4.8.
 *
 * AP-C INVIOLABLE RULES (see FrontierGeneratorPort):
 *  - PROPOSAL-ONLY: emits candidate proposals; never writes canon/code/docs, never merges,
 *    never executes, never auto-approves. Survivors are admitted to the curation inbox by
 *    the orchestrator as pending_operator_review.
 *  - REAL-OR-BLOCKED: invokes the real `claude` CLI (Opus 4.8) via ClaudeCliProvider. If the
 *    CLI is unavailable / errors / returns no parseable strict-JSON proposals, it BLOCKS
 *    honestly with reasons and generates NOTHING. It never fabricates.
 *  - DOSSIER-ONLY INPUT: the prompt is built from the AP-A harvester dossier only.
 *
 * Each proposal must carry the 13 evolution_proposal keys and cite ONLY dossier anchor_ids.
 * Identity/provenance uses FrontierProposalIdentity::of (the single canonical formula).
 */
final class AtlasClaudeCliFrontierGeneratorService implements FrontierGeneratorPort
{
    /** Operator-mandated: newest Claude Opus for high-leap proposal generation. */
    public const MODEL = 'claude-opus-4-8';

    public const PROVIDER = 'claude_cli';

    /** Operator-mandated limit fallback target: Codex 5.5 on the codex CLI. */
    public const CODEX_PROVIDER = 'codex_cli';

    public const CODEX_MODEL = 'gpt-5.5-codex';

    public const BLOCKER_PROVIDER_FAILED = 'claude_cli_provider_failed';

    public const BLOCKER_NO_PARSEABLE_PROPOSALS = 'claude_cli_no_parseable_proposals';

    /**
     * Distinct blocker for a provider LIMIT/exhaustion/unavailable signal (vs a
     * generic failure). A limit is the one signal the limit-fallback router treats
     * as "route to the next provider" — a generic failure never auto-falls-back.
     */
    public const BLOCKER_PROVIDER_LIMITED = 'provider_limited';

    /**
     * AiProviderResult.errorCode values that mean the provider hit a usage ceiling
     * / auth ceiling / availability ceiling — i.e. a LIMIT, not a content failure.
     *
     * @var list<string>
     */
    private const LIMIT_ERROR_CODES = [
        'rate_limited',
        'auth_expired',
        'provider_unavailable',
        'unavailable',
    ];

    public function __construct(
        private readonly AiProvider $provider,
        private readonly int $timeoutSeconds = 600,
        private readonly string $providerKey = self::PROVIDER,
        private readonly string $model = self::MODEL,
    ) {}

    /**
     * Opus 4.8 generator (the operator-mandated primary), driven by the real
     * Claude Code CLI. This is the default production primary.
     */
    public static function opus(ClaudeCliProvider $claude, int $timeoutSeconds = 600): self
    {
        return new self($claude, $timeoutSeconds, self::PROVIDER, self::MODEL);
    }

    /**
     * Codex 5.5 generator (the operator-mandated limit fallback), driven by the
     * real Codex CLI. Same proposal-only / real-or-blocked contract as Opus.
     */
    public static function codex(AiProvider $codex, int $timeoutSeconds = 600): self
    {
        return new self($codex, $timeoutSeconds, self::CODEX_PROVIDER, self::CODEX_MODEL);
    }

    /**
     * @param  array<string,mixed>  $dossier
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function generate(array $dossier, int $count, array $context = []): array
    {
        $count = max(1, $count);
        $label = 'real:'.$this->providerKey.':'.$this->model;
        $generatorInputHash = MissionCanonicalHash::sha256($dossier);

        $prompt = $this->buildPrompt($dossier, $count);

        try {
            $job = new AiJob([
                'model' => $this->model,
                'timeout_seconds' => $this->timeoutSeconds,
                'metadata' => ['purpose' => 'foundry_frontier_generation', 'proposal_only' => true],
            ]);
            $result = $this->provider->run($job, $prompt);
        } catch (Throwable $e) {
            return $this->blocked($label, [self::BLOCKER_PROVIDER_FAILED]);
        }

        if (! $result->ok) {
            // A LIMIT/exhaustion/unavailable signal is distinct from a generic
            // failure: surface provider_limited so the router can fall back. A
            // generic failure stays a hard block (real-or-blocked, no fabrication).
            $errorCode = (string) ($result->errorCode ?? '');
            if (in_array($errorCode, self::LIMIT_ERROR_CODES, true)) {
                return $this->blocked($label, [self::BLOCKER_PROVIDER_LIMITED], providerLimited: true, errorCode: $errorCode);
            }

            return $this->blocked($label, [self::BLOCKER_PROVIDER_FAILED]);
        }

        $proposals = $this->parseProposals($result->output !== '' ? $result->output : $result->stdout, $count);
        if ($proposals === []) {
            return $this->blocked($label, [self::BLOCKER_NO_PARSEABLE_PROPOSALS]);
        }

        $provenance = [];
        foreach ($proposals as $proposal) {
            $proposalId = (string) ($proposal['proposal_id'] ?? '');
            $provenance[$proposalId] = [
                'generator_label' => $label,
                'generator_input_hash' => $generatorInputHash,
                'proposal_hash' => FrontierProposalIdentity::of($proposal),
            ];
        }

        return [
            'status' => 'generated',
            'proposals' => array_values($proposals),
            'provenance' => $provenance,
            'generator_label' => $label,
            'generator_provider_resolved' => $this->providerKey,
            'generator_model_resolved' => $this->model,
            'generator_blocked_reasons' => [],
            'provider_limited' => false,
            'claim_policy' => [
                'provider_invoked' => true,
                'proposal_only' => true,
                'writes_canon' => false,
                'executes' => false,
            ],
        ];
    }

    /**
     * Strictly parse a JSON array of 13-key proposals from the model output. Anything that is
     * not a schema-valid evolution_proposal is dropped (never coerced/fabricated).
     *
     * @return list<array<string,mixed>>
     */
    private function parseProposals(string $output, int $count): array
    {
        $start = strpos($output, '[');
        $end = strrpos($output, ']');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            return [];
        }

        $valid = [];
        foreach ($decoded as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $shape = FoundrySchemas::validateShape(FoundrySchemas::EVOLUTION_PROPOSAL, $proposal);
            if (($shape['valid'] ?? false) === true) {
                $valid[] = $proposal;
            }
            if (count($valid) >= $count) {
                break;
            }
        }

        return $valid;
    }

    /**
     * @param  array<string,mixed>  $dossier
     */
    private function buildPrompt(array $dossier, int $count): string
    {
        $dossierJson = json_encode($dossier, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $anchorIds = [];
        foreach ((array) ($dossier['anchors'] ?? []) as $anchor) {
            $id = is_array($anchor) ? ($anchor['anchor_id'] ?? null) : null;
            if (is_string($id) && $id !== '') {
                $anchorIds[] = $id;
            }
        }
        $anchorList = $anchorIds === [] ? '(none — if empty, return [])' : implode(', ', $anchorIds);

        return <<<PROMPT
You are the Atlas Frontier evolution proposer. From the AP-A evidence dossier below ONLY,
propose up to {$count} HIGH-LEAP evolution proposals that bring the biggest possible multiplier
to the AAEOS (Atlas Agentic Engineering OS) and the autonomous loop factory. Each must be a
genuine compounding leap, not incremental polish.

OUTPUT CONTRACT (strict):
- Respond with a SINGLE JSON array and NOTHING else. No prose, no markdown fences.
- Each element MUST have EXACTLY these 13 keys:
  proposal_id (kebab string), horizon ("extreme"|"quantum"|"tier_s"|"tier_a"|"tier_b"),
  title, thesis, evidence_refs (array of {"anchor_id": "<one of the dossier anchor_ids>"}),
  why_it_multiplies, success_metric (a falsifiable metric), rollback (how to revert),
  risk_level ("low"|"medium"|"high"|"critical"), dependencies (array of strings),
  proposed_packets (array of {"kind","owner_candidate","label"}),
  provider_tier_required ("cheap"|"builder"|"premium"), anti_pattern_self_check.
- evidence_refs MUST cite ONLY these dossier anchor_ids: {$anchorList}
- If the dossier has no anchors to ground a real proposal, return [] (empty array). Never invent evidence.

DOSSIER:
{$dossierJson}
PROMPT;
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string,mixed>
     */
    private function blocked(string $label, array $reasons, bool $providerLimited = false, string $errorCode = ''): array
    {
        return [
            'status' => 'blocked',
            'proposals' => [],
            'provenance' => [],
            'generator_label' => $label,
            'generator_provider_resolved' => $this->providerKey,
            'generator_model_resolved' => $this->model,
            'generator_blocked_reasons' => array_values($reasons),
            'provider_limited' => $providerLimited,
            'provider_limit_error_code' => $errorCode,
            'claim_policy' => [
                'provider_invoked' => true,
                'proposal_only' => true,
                'writes_canon' => false,
                'executes' => false,
            ],
        ];
    }
}
