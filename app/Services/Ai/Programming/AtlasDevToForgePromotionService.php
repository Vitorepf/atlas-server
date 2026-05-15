<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AiMessage;
use App\Models\AiThread;
use App\Models\AtlasProject;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Atlas Dev-to-Forge Promotion Service.
 *
 * Reads an Atlas AI / Atlas Dev `AiThread` and decides if (and how) the work
 * should be promoted to a Forge artefact:
 *
 *   thread → quick_intervention | obra_candidate | forge_obra | none
 *
 * Canon:
 *   - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
 *   - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
 *   - docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
 *
 * Rules honored:
 *   - Never auto-promote. The service emits a *recommendation*; promotion only
 *     happens when the operator explicitly POSTs the candidate.
 *   - Never force Obra on a small bug. `none` is a valid (and common) outcome.
 *   - Preview is read-only. Calling `preview()` MUST NOT touch any model.
 *   - When promoting, preserve thread, workspace, decisions, risks, files,
 *     open questions, success criteria and a stable link back to the source
 *     thread via `metadata.source_thread_id`.
 *   - Forbidden: creating Forge runtime, invoking provider, bypassing Atenção
 *     decision flow. The created Obra Candidate naturally surfaces in Atenção
 *     as `intake_needed` because it lacks intake fields.
 *
 * Schema: atlas.dev.dev_to_forge_promotion.v1
 */
final class AtlasDevToForgePromotionService
{
    public const SCHEMA_VERSION = 'atlas.dev.dev_to_forge_promotion.v1';

    public const TARGET_NONE = 'none';
    public const TARGET_QUICK_INTERVENTION = 'quick_intervention';
    public const TARGET_OBRA_CANDIDATE = 'obra_candidate';
    public const TARGET_FORGE_OBRA = 'forge_obra';

    /** Mensagens analisadas para extrair sinais. Mantém a heurística barata. */
    private const MAX_MESSAGES_SCANNED = 80;

    /** Limite acima do qual a conversa é considerada "long_context". */
    private const LONG_CONTEXT_MESSAGE_THRESHOLD = 12;

    /** Soma estimada de tokens acima do qual disparamos long_context. */
    private const LONG_CONTEXT_TOKEN_THRESHOLD = 8_000;

    /** Quando o operador menciona >= N arquivos distintos viramos multi-subsistema. */
    private const MULTI_SUBSYSTEM_FILE_THRESHOLD = 3;

    public function __construct(
        private readonly AtlasCodeWorkspaceProfileService $workspaces,
    ) {}

    /**
     * Build a read-only preview for the given thread.
     *
     * @return array<string, mixed>
     */
    public function preview(AiThread $thread): array
    {
        $messages = $thread->messages()->orderBy('position')->limit(self::MAX_MESSAGES_SCANNED)->get();
        $signals = $this->detectSignals($thread, $messages);
        $target = $this->decideTarget($signals);
        $reasons = $this->reasonsForTarget($target, $signals);
        $confidence = $this->confidenceFor($target, $signals);

        $workspaceSlug = $this->normalizeWorkspaceSlug($thread, $signals);
        $profile = $workspaceSlug !== null ? $this->workspaces->findBySlug($workspaceSlug) : null;

        $summary = $this->buildSummary($thread, $messages, $signals);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'source_thread_id' => (string) $thread->getKey(),
            'thread_title' => $this->stringOrNull($thread->title),
            'thread_status' => (string) $thread->status,
            'thread_message_count' => (int) $thread->message_count,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'workspace' => [
                'slug' => $workspaceSlug,
                'name' => $profile['name'] ?? null,
                'workspace_path' => $profile['workspace_path'] ?? null,
                'workspace_path_exists' => $profile['workspace_path_exists'] ?? null,
                'production_status' => $profile['production_status'] ?? null,
                'default_risk' => $profile['default_risk'] ?? null,
            ],
            'promotion_target' => $target,
            'promotion_target_reasons' => $reasons,
            'confidence' => $confidence,
            'signals' => $signals,
            'title' => $summary['title'],
            'objective' => $summary['objective'],
            'context_summary' => $summary['context_summary'],
            'known_files' => $summary['known_files'],
            'risks' => $summary['risks'],
            'open_questions' => $summary['open_questions'],
            'suggested_success_criteria' => $summary['suggested_success_criteria'],
            'suggested_next_step' => $summary['suggested_next_step'],
            'allowed_targets' => $this->allowedTargetsFor($target),
            'requires_workspace' => $target !== self::TARGET_NONE && $workspaceSlug === null,
            'already_promoted' => $this->existingPromotionRecord($thread) !== null,
            'existing_promotion' => $this->existingPromotionRecord($thread),
            'separated_from' => 'forge_runtime',
            'note' => 'Atlas Dev-to-Forge Promotion: recomenda; nunca executa. Nenhuma promoção acontece sem POST explícito.',
        ];
    }

    /**
     * Materialize the promotion decision.
     *
     * Quick intervention → records the candidate inside the thread metadata
     * only. No Obra is created (canon: small bugs do not become Obras).
     *
     * Obra Candidate / Forge Obra → records the candidate AND creates an
     * AtlasProject with `metadata.origin='atlas-dev-promotion'`, `metadata.
     * promotion_target=…`, and a back-link to the source thread.
     *
     * @param  array<string, mixed>  $overrides  optional title/objective/etc
     * @return array<string, mixed>
     */
    public function promote(
        AiThread $thread,
        string $requestedTarget,
        array $overrides = [],
    ): array {
        $preview = $this->preview($thread);

        if (! in_array($requestedTarget, (array) $preview['allowed_targets'], true)) {
            throw new \InvalidArgumentException('promotion_target_not_allowed');
        }

        if ($requestedTarget === self::TARGET_NONE) {
            throw new \InvalidArgumentException('promotion_target_is_none');
        }

        if (((bool) ($preview['requires_workspace'] ?? false)) === true) {
            throw new \InvalidArgumentException('workspace_required_for_promotion');
        }

        $title = $this->stringOrNull($overrides['title'] ?? null) ?? (string) $preview['title'];
        $objective = $this->stringOrNull($overrides['objective'] ?? null) ?? (string) $preview['objective'];
        $contextSummary = $this->stringOrNull($overrides['context_summary'] ?? null)
            ?? (string) $preview['context_summary'];
        $workspaceSlug = $this->stringOrNull($overrides['workspace_slug'] ?? null)
            ?? (is_array($preview['workspace']) ? ($preview['workspace']['slug'] ?? null) : null);

        $promotionId = (string) Str::uuid();
        $recordedAt = now()->toIso8601String();

        $record = [
            'promotion_id' => 'devforge_'.substr($promotionId, 0, 12),
            'schema_version' => self::SCHEMA_VERSION,
            'source_thread_id' => (string) $thread->getKey(),
            'promotion_target' => $requestedTarget,
            'workspace_slug' => $workspaceSlug,
            'title' => $title,
            'objective' => $objective,
            'context_summary' => $contextSummary,
            'known_files' => (array) ($preview['known_files'] ?? []),
            'risks' => (array) ($preview['risks'] ?? []),
            'open_questions' => (array) ($preview['open_questions'] ?? []),
            'suggested_success_criteria' => (array) ($preview['suggested_success_criteria'] ?? []),
            'suggested_next_step' => (string) ($preview['suggested_next_step'] ?? ''),
            'signals' => (array) ($preview['signals'] ?? []),
            'recorded_at' => $recordedAt,
            'decided_by' => $this->stringOrNull($overrides['decided_by'] ?? null) ?? 'human',
            'reason' => $this->stringOrNull($overrides['reason'] ?? null),
        ];

        $createdObraId = null;
        if ($requestedTarget === self::TARGET_OBRA_CANDIDATE || $requestedTarget === self::TARGET_FORGE_OBRA) {
            $profile = $workspaceSlug !== null ? $this->workspaces->findBySlug($workspaceSlug) : null;
            $obra = AtlasProject::query()->create([
                'id' => (string) Str::uuid(),
                'title' => $title,
                'description' => $contextSummary !== '' ? $contextSummary : $objective,
                'status' => 'active',
                'domain' => $workspaceSlug ?? 'atlas',
                'goal' => $objective,
                'desired_outcome' => $objective,
                'priority' => $requestedTarget === self::TARGET_FORGE_OBRA ? 'high' : 'medium',
                'last_touched_at' => now(),
                'metadata' => array_filter([
                    'origin' => 'atlas-dev-promotion',
                    'promotion_target' => $requestedTarget,
                    'promotion_id' => $record['promotion_id'],
                    'source_thread_id' => $record['source_thread_id'],
                    'workspace_slug' => $workspaceSlug,
                    'workspace_path' => $profile['workspace_path'] ?? null,
                    'workspace_name' => $profile['name'] ?? null,
                    'workspace_production_status' => $profile['production_status'] ?? null,
                    'dev_to_forge_promotion' => $record,
                ], static fn ($v): bool => $v !== null && $v !== ''),
            ]);
            $createdObraId = (string) $obra->getKey();
        }

        $record['created_obra_id'] = $createdObraId;

        $metadata = is_array($thread->metadata) ? $thread->metadata : [];
        $history = (array) data_get($metadata, 'dev_to_forge_promotions', []);
        $history[] = $record;
        $metadata['dev_to_forge_promotions'] = array_values(array_slice($history, -10));
        $metadata['latest_dev_to_forge_promotion'] = $record;
        $thread->metadata = $metadata;
        $thread->save();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'promoted',
            'promotion_target' => $requestedTarget,
            'promotion_record' => $record,
            'created_obra_id' => $createdObraId,
            'preview' => $preview,
        ];
    }

    /**
     * Detect signals from the thread + messages. Pure data extraction.
     *
     * @param  Collection<int, AiMessage>  $messages
     * @return array<string, mixed>
     */
    private function detectSignals(AiThread $thread, Collection $messages): array
    {
        $messageTexts = $messages
            ->map(fn (AiMessage $m): string => (string) ($m->content ?? ''))
            ->filter(static fn (string $c): bool => $c !== '')
            ->values();

        $tokenEstimate = (int) $messages->sum(fn (AiMessage $m): int => (int) ($m->token_estimate ?? 0));
        $messageCount = (int) max($thread->message_count ?? 0, $messages->count());

        $allText = $messageTexts->implode("\n");
        $allTextLower = mb_strtolower($allText);

        $knownFiles = $this->extractKnownFiles($messageTexts);
        $openQuestions = $this->extractOpenQuestions($messageTexts);
        $risks = $this->extractRisks($messageTexts);

        $explicitHumanRequest = (bool) preg_match(
            '/(virar?|cria(r|e)|promova?|criar)\s+(uma\s+)?obra|forge|candidato|promov(?:er|a)|isso\s+(virou|virar)\s+obra/iu',
            $allText,
        );

        $architectureSignal = (bool) preg_match(
            '/arquitet|arquiteturas?\b|refator|refactor|design\s+system|spec\b|spec\s+os|planejamento|plano\s+t[eé]cnico/iu',
            $allText,
        );

        $gatesSignal = (bool) preg_match(
            '/\bgates?\b|aceite|crit[eé]rios?\s+de\s+aceite|reviewers?|review\s+gate|evid[eê]ncia|evidence/iu',
            $allText,
        );

        $riskKeywords = (bool) preg_match(
            '/\bprodu[cç][aã]o\b|prod\b|pagament|cobranc|auth\b|autenticac|migra[cç][aã]o|schema|breaking|seguran[cç]a|gdpr|lgpd|cr[ií]tic|hotfix|incidente?|leak/iu',
            $allText,
        );

        $recurringFailure = ($messageTexts->filter(static fn (string $c): bool => (bool) preg_match(
            '/(falhou|n[aã]o\s+funcionou|continua\s+quebr|ainda\s+(n[aã]o|sem)|errou\s+de\s+novo|tentei\s+(mais|de\s+novo)|loop\s+infinito|deu\s+ruim)/iu',
            $c,
        ))->count() >= 2);

        $multiSubsystemSignal = count($knownFiles) >= self::MULTI_SUBSYSTEM_FILE_THRESHOLD
            || $this->mentionsMultipleSubsystems($allTextLower);

        $longContext = $messageCount >= self::LONG_CONTEXT_MESSAGE_THRESHOLD
            || $tokenEstimate >= self::LONG_CONTEXT_TOKEN_THRESHOLD;

        $thinSmallBug = ! $multiSubsystemSignal
            && ! $recurringFailure
            && count($knownFiles) <= 1
            && $messageCount <= 6
            && ! $architectureSignal
            && ! $gatesSignal
            && ! $riskKeywords;

        return [
            'message_count' => $messageCount,
            'token_estimate' => $tokenEstimate,
            'long_context' => $longContext,
            'recurring_failure' => $recurringFailure,
            'multi_subsystem_signal' => $multiSubsystemSignal,
            'risk_keywords' => $riskKeywords,
            'architecture_signal' => $architectureSignal,
            'gates_signal' => $gatesSignal,
            'explicit_human_request' => $explicitHumanRequest,
            'known_files_count' => count($knownFiles),
            'open_questions_count' => count($openQuestions),
            'risk_marker_count' => count($risks),
            'thin_small_bug' => $thinSmallBug,
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function decideTarget(array $signals): string
    {
        // 1. Operador pediu explicitamente: respeitamos.
        if (! empty($signals['explicit_human_request'])) {
            return self::TARGET_FORGE_OBRA;
        }

        // 2. Thread claramente pequena: nada de Obra.
        if (! empty($signals['thin_small_bug'])) {
            return self::TARGET_NONE;
        }

        // 3. Combinações de risco/arquitetura/gates puxam para Obra Candidate.
        if (! empty($signals['risk_keywords'])
            && (! empty($signals['architecture_signal']) || ! empty($signals['gates_signal']))) {
            return self::TARGET_OBRA_CANDIDATE;
        }

        if (! empty($signals['multi_subsystem_signal']) && ! empty($signals['risk_keywords'])) {
            return self::TARGET_OBRA_CANDIDATE;
        }

        if (! empty($signals['long_context'])
            && (! empty($signals['multi_subsystem_signal']) || ! empty($signals['architecture_signal']))) {
            return self::TARGET_OBRA_CANDIDATE;
        }

        // 4. Sinais menores → Intervenção Rápida.
        if (! empty($signals['multi_subsystem_signal'])
            || ! empty($signals['recurring_failure'])
            || ! empty($signals['gates_signal'])) {
            return self::TARGET_QUICK_INTERVENTION;
        }

        return self::TARGET_NONE;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return list<string>
     */
    private function reasonsForTarget(string $target, array $signals): array
    {
        $reasons = [];
        if (! empty($signals['explicit_human_request'])) {
            $reasons[] = 'humano pediu explicitamente promoção';
        }
        if (! empty($signals['risk_keywords'])) {
            $reasons[] = 'palavras de risco (produção/auth/pagamento/migração/segurança)';
        }
        if (! empty($signals['architecture_signal'])) {
            $reasons[] = 'conversa toca arquitetura/spec/plano';
        }
        if (! empty($signals['gates_signal'])) {
            $reasons[] = 'menciona gates, aceite ou evidência';
        }
        if (! empty($signals['multi_subsystem_signal'])) {
            $reasons[] = sprintf(
                'múltiplos arquivos/subsistemas mencionados (%d arquivos detectados)',
                (int) ($signals['known_files_count'] ?? 0),
            );
        }
        if (! empty($signals['long_context'])) {
            $reasons[] = sprintf(
                'contexto longo (%d mensagens / ~%d tokens)',
                (int) ($signals['message_count'] ?? 0),
                (int) ($signals['token_estimate'] ?? 0),
            );
        }
        if (! empty($signals['recurring_failure'])) {
            $reasons[] = 'falha recorrente detectada nas mensagens';
        }
        if ($target === self::TARGET_NONE && empty($reasons)) {
            $reasons[] = 'sinais insuficientes — bug pequeno, conversa curta ou ajuste simples';
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function confidenceFor(string $target, array $signals): string
    {
        $weight = 0;
        foreach (['explicit_human_request' => 5,
            'risk_keywords' => 2,
            'architecture_signal' => 1,
            'gates_signal' => 1,
            'multi_subsystem_signal' => 2,
            'recurring_failure' => 2,
            'long_context' => 1,
        ] as $key => $w) {
            if (! empty($signals[$key])) {
                $weight += $w;
            }
        }

        if ($target === self::TARGET_NONE) {
            return $weight === 0 ? 'high' : 'low';
        }
        if ($weight >= 5) {
            return 'high';
        }
        if ($weight >= 3) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return list<string>
     */
    private function allowedTargetsFor(string $suggested): array
    {
        if ($suggested === self::TARGET_NONE) {
            // Operador pode forçar uma quick_intervention/candidato mesmo sem sinais
            // — mas precisa sair do default.
            return [self::TARGET_NONE, self::TARGET_QUICK_INTERVENTION];
        }
        if ($suggested === self::TARGET_QUICK_INTERVENTION) {
            return [self::TARGET_QUICK_INTERVENTION, self::TARGET_OBRA_CANDIDATE];
        }
        if ($suggested === self::TARGET_OBRA_CANDIDATE) {
            return [self::TARGET_QUICK_INTERVENTION, self::TARGET_OBRA_CANDIDATE, self::TARGET_FORGE_OBRA];
        }

        return [self::TARGET_OBRA_CANDIDATE, self::TARGET_FORGE_OBRA];
    }

    /**
     * @param  Collection<int, AiMessage>  $messages
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function buildSummary(AiThread $thread, Collection $messages, array $signals): array
    {
        $title = $this->stringOrNull($thread->title) ?? 'Promoção sem título';

        $firstOperator = $messages->first(static fn (AiMessage $m): bool => in_array((string) $m->role, ['user', 'operator', 'human'], true));
        $objective = $this->stringOrNull($thread->summary)
            ?? $this->firstSentence((string) ($firstOperator?->content ?? ''))
            ?? $title;

        $messageTexts = $messages
            ->map(fn (AiMessage $m): string => (string) ($m->content ?? ''))
            ->filter(static fn (string $c): bool => $c !== '')
            ->values();

        $contextSummary = $this->buildContextSummary($messageTexts);
        $knownFiles = $this->extractKnownFiles($messageTexts);
        $openQuestions = $this->extractOpenQuestions($messageTexts);
        $risks = $this->extractRisks($messageTexts);
        $successCriteria = $this->extractSuccessCriteria($messageTexts);
        $nextStep = $this->extractNextStep($messageTexts, $signals);

        return [
            'title' => $title,
            'objective' => $objective,
            'context_summary' => $contextSummary,
            'known_files' => $knownFiles,
            'risks' => $risks,
            'open_questions' => $openQuestions,
            'suggested_success_criteria' => $successCriteria,
            'suggested_next_step' => $nextStep,
        ];
    }

    /**
     * @param  Collection<int, string>  $texts
     * @return list<string>
     */
    private function extractKnownFiles(Collection $texts): array
    {
        $files = [];
        foreach ($texts as $text) {
            if (preg_match_all(
                '#(?<![\w./-])([A-Za-z0-9_./-]+\.(?:php|tsx?|jsx?|css|scss|md|json|yml|yaml|sql|sh|rb|py|go|rs|html|env|toml|lock|ini|conf|swift|kt|java|c|cpp|hpp))\b#u',
                $text,
                $matches,
            )) {
                foreach ($matches[1] as $match) {
                    $clean = trim($match, " \t\n\r\0\x0B.,;:()[]{}\"'");
                    if ($clean !== '' && ! in_array($clean, $files, true)) {
                        $files[] = $clean;
                    }
                }
            }
        }

        return array_slice($files, 0, 20);
    }

    /**
     * @param  Collection<int, string>  $texts
     * @return list<string>
     */
    private function extractOpenQuestions(Collection $texts): array
    {
        $questions = [];
        foreach ($texts as $text) {
            foreach (preg_split('/[\n\r]+/u', $text) as $line) {
                $line = trim((string) $line);
                if ($line === '' || mb_strlen($line) < 8) {
                    continue;
                }
                if (str_ends_with($line, '?')) {
                    if (! in_array($line, $questions, true)) {
                        $questions[] = $line;
                    }
                }
            }
        }

        return array_slice($questions, 0, 12);
    }

    /**
     * @param  Collection<int, string>  $texts
     * @return list<string>
     */
    private function extractRisks(Collection $texts): array
    {
        $risks = [];
        foreach ($texts as $text) {
            foreach (preg_split('/[\n\r]+/u', $text) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^\s*[-*]?\s*risc/iu', $line)
                    || preg_match('/\b(risco|perigo|cuidado|pode\s+quebrar|breaking)\b/iu', $line)) {
                    $clean = ltrim($line, "-* \t");
                    if (! in_array($clean, $risks, true)) {
                        $risks[] = $clean;
                    }
                }
            }
        }

        return array_slice($risks, 0, 10);
    }

    /**
     * @param  Collection<int, string>  $texts
     * @return list<string>
     */
    private function extractSuccessCriteria(Collection $texts): array
    {
        $criteria = [];
        foreach ($texts as $text) {
            foreach (preg_split('/[\n\r]+/u', $text) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/\b(aceite|crit[eé]rios?|definition\s+of\s+done|sucesso|feito\s+quando|done\s+when)\b/iu', $line)) {
                    $clean = ltrim($line, "-* \t");
                    if (! in_array($clean, $criteria, true)) {
                        $criteria[] = $clean;
                    }
                }
            }
        }

        return array_slice($criteria, 0, 10);
    }

    /**
     * @param  Collection<int, string>  $texts
     * @param  array<string, mixed>  $signals
     */
    private function extractNextStep(Collection $texts, array $signals): string
    {
        $last = $texts->last();
        if (is_string($last) && $last !== '') {
            foreach (preg_split('/[\n\r]+/u', $last) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^(pr[oó]xim|next|to\s*do|fazer\s+a\s+seguir|seguindo|continua)/iu', $line)) {
                    return ltrim($line, "-* \t");
                }
            }
        }

        if (! empty($signals['explicit_human_request'])) {
            return 'Criar Obra Forge a partir desta thread, preservando contexto e workspace.';
        }
        if (! empty($signals['risk_keywords']) && ! empty($signals['gates_signal'])) {
            return 'Promover para Candidato de Obra; planejar gates antes da execução.';
        }
        if (! empty($signals['multi_subsystem_signal'])) {
            return 'Promover para Intervenção Rápida ou Candidato de Obra; mapear arquivos antes de mexer.';
        }

        return 'Continuar como Atlas Dev; nenhuma promoção necessária no momento.';
    }

    /**
     * @param  Collection<int, string>  $texts
     */
    private function buildContextSummary(Collection $texts): string
    {
        if ($texts->isEmpty()) {
            return '';
        }
        $head = trim((string) $texts->first());
        $tail = trim((string) $texts->last());
        $headSlice = $this->firstSentence($head) ?? mb_substr($head, 0, 200);
        $tailSlice = $this->firstSentence($tail) ?? mb_substr($tail, 0, 200);
        if ($headSlice === $tailSlice) {
            return $headSlice ?? '';
        }

        return trim(($headSlice ?? '').' … '.($tailSlice ?? ''));
    }

    private function firstSentence(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }
        $matches = [];
        if (preg_match('/^(.{20,240}?[\.\?!])/u', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        return mb_substr($trimmed, 0, 200);
    }

    private function mentionsMultipleSubsystems(string $textLower): bool
    {
        $hits = 0;
        foreach ([
            'backend', 'frontend', 'mobile', 'desktop', 'cli', 'database',
            'banco de dados', 'api', 'auth', 'pagament', 'queue', 'worker',
            'job ', 'migration', 'schema', 'css', 'react',
        ] as $needle) {
            if (str_contains($textLower, $needle)) {
                $hits++;
                if ($hits >= 3) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function normalizeWorkspaceSlug(AiThread $thread, array $signals): ?string
    {
        $workspace = $this->stringOrNull($thread->workspace);
        if ($workspace !== null) {
            $profile = $this->workspaces->findBySlug($workspace);
            if ($profile !== null) {
                return $profile['slug'];
            }
            // workspace may be free-form path; we can't resolve a profile.
            return $workspace;
        }

        $metadata = is_array($thread->metadata) ? $thread->metadata : [];
        foreach (['workspace_slug', 'routing_domain'] as $key) {
            $candidate = $this->stringOrNull(data_get($metadata, $key));
            if ($candidate !== null) {
                $profile = $this->workspaces->findBySlug($candidate);
                if ($profile !== null) {
                    return $profile['slug'];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingPromotionRecord(AiThread $thread): ?array
    {
        $metadata = is_array($thread->metadata) ? $thread->metadata : [];
        $latest = data_get($metadata, 'latest_dev_to_forge_promotion');
        if (is_array($latest)) {
            return $latest;
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
