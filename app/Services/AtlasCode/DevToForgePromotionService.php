<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Models\AiMessage;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Models\AtlasProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Atlas Code · Dev-to-Forge Promotion Service.
 *
 * Canon:
 *   - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
 *   - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
 *   - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
 *
 * Bridges Atlas Dev (daily programming inside Atlas AI) and Forge/Obras
 * (governed heavy work). NEVER auto-creates an Obra — always returns a
 * preview payload first; the human accepts via the Attention queue.
 *
 * Storage: filesystem JSON at
 *   storage/app/atlas-code/promotion-candidates/{workspace_slug}/{candidate_id}.json
 *
 * Promotion targets:
 *   - quick_intervention  · scaffold record only (UI may open the Obra
 *                            workflow with the payload pre-filled).
 *   - obra_candidate      · structured discovery; lives in Attention until
 *                            the operator decides promote/dismiss.
 *   - forge_obra          · operator confirmed → service creates a real
 *                            Obra via AtlasProject::create with the payload
 *                            and binds workspace_slug into metadata.
 */
final class DevToForgePromotionService
{
    public const SCHEMA_VERSION = 'atlas.code.dev_to_forge.promotion_candidate.v1';

    public function __construct(
        private readonly PromotionSignalDetector $detector,
        private readonly AtlasCodeWorkspaceProfileService $profiles,
    ) {
    }

    /**
     * Build a preview payload for promoting a thread. Read-only.
     *
     * @return array<string,mixed>
     */
    public function previewForThread(string $threadId, ?string $workspaceSlug = null): array
    {
        $thread = AiThread::query()->find($threadId);
        if ($thread === null) {
            throw new RuntimeException('dev_to_forge_thread_not_found');
        }

        $messages = AiMessage::query()
            ->where('thread_id', $threadId)
            ->orderBy('position')
            ->limit(200)
            ->get()
            ->map(fn (AiMessage $m): array => [
                'id' => (string) $m->getKey(),
                'role' => (string) ($m->role ?? ''),
                'content' => $m->content,
                'occurred_at' => $m->occurred_at?->toJSON() ?? $m->created_at?->toJSON(),
            ])
            ->all();

        $traces = AiTrace::query()
            ->where('thread_id', $threadId)
            ->orderBy('created_at')
            ->limit(40)
            ->get()
            ->map(fn (AiTrace $t): array => [
                'id' => (string) $t->getKey(),
                'status' => (string) ($t->status ?? ''),
            ])
            ->all();

        $threadArr = [
            'id' => (string) $thread->getKey(),
            'title' => (string) ($thread->title ?? ''),
            'summary' => (string) ($thread->summary ?? ''),
            'workspace' => $workspaceSlug ?? (string) ($thread->workspace ?? ''),
            'message_count' => (int) ($thread->message_count ?? count($messages)),
        ];

        $signalReport = $this->detector->analyse($threadArr, $messages, $traces);
        $workspace = $this->resolveWorkspace($workspaceSlug ?? (string) ($thread->workspace ?? ''));

        return $this->buildPayload($thread, $messages, $signalReport, $workspace);
    }

    /**
     * Persist a candidate from the preview, optionally promote to a real Obra.
     *
     * @param  array<string,mixed>  $overrides  operator-edited fields
     * @return array<string,mixed>
     */
    public function promote(
        string $threadId,
        string $promotionTarget,
        array $overrides = [],
        ?string $workspaceSlug = null,
    ): array {
        if (! in_array($promotionTarget, [
            PromotionSignalDetector::TARGET_QUICK_INTERVENTION,
            PromotionSignalDetector::TARGET_OBRA_CANDIDATE,
            PromotionSignalDetector::TARGET_FORGE_OBRA,
        ], true)) {
            throw new RuntimeException('dev_to_forge_invalid_target:'.$promotionTarget);
        }

        $preview = $this->previewForThread($threadId, $workspaceSlug);
        // Operator can refine fields before persisting; merge cautiously
        // (never mutate workspace_slug — Project boundary is canonical).
        $allowed = [
            'title',
            'objective',
            'context_summary',
            'known_files',
            'risks',
            'open_questions',
            'suggested_success_criteria',
            'suggested_next_step',
        ];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $overrides)) {
                $preview[$field] = $overrides[$field];
            }
        }
        $preview['promotion_target'] = $promotionTarget;
        $preview['promoted_at'] = now()->toJSON();
        $preview['candidate_status'] = 'pending_decision';
        $preview['promoted_obra_id'] = null;

        $candidateId = 'pc_'.Str::ulid()->toBase32();
        $preview['id'] = $candidateId;

        // When target is forge_obra we ALSO create a real Obra (AtlasProject)
        // bound to the workspace. The candidate keeps the link.
        if ($promotionTarget === PromotionSignalDetector::TARGET_FORGE_OBRA) {
            $obra = AtlasProject::query()->create([
                'title' => (string) ($preview['title'] ?? 'Obra promovida do Atlas Dev'),
                'description' => (string) ($preview['context_summary'] ?? ''),
                'status' => 'active',
                'domain' => (string) ($preview['workspace_slug'] ?? 'atlas'),
                'goal' => (string) ($preview['objective'] ?? ''),
                'desired_outcome' => (string) ($preview['objective'] ?? ''),
                'priority' => 'medium',
                'last_touched_at' => now(),
                'metadata' => array_filter([
                    'origin' => 'atlas-dev-promotion',
                    'workspace_slug' => $preview['workspace_slug'] ?? null,
                    'workspace_name' => $preview['workspace_name'] ?? null,
                    'workspace_production_status' => $preview['workspace_production_status'] ?? null,
                    'promoted_from_thread_id' => $threadId,
                    'promotion_target' => $promotionTarget,
                    'promotion_candidate_id' => $candidateId,
                ], static fn ($v) => $v !== null && $v !== ''),
            ]);
            $preview['promoted_obra_id'] = (string) $obra->getKey();
            $preview['candidate_status'] = 'promoted';
        }

        $this->persist($preview);
        return $preview;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCandidates(?string $workspaceSlug = null): array
    {
        $base = $this->candidatesBaseDir();
        if (! is_dir($base)) {
            return [];
        }
        $entries = [];
        $dirs = $workspaceSlug !== null
            ? [$base.'/'.$this->safeSlug($workspaceSlug)]
            : (array) glob($base.'/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            if (! is_string($dir) || ! is_dir($dir)) {
                continue;
            }
            foreach ((array) glob($dir.'/*.json') as $file) {
                if (! is_string($file) || ! is_file($file)) {
                    continue;
                }
                $raw = @file_get_contents($file);
                if (! is_string($raw) || $raw === '') {
                    continue;
                }
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $entries[] = $decoded;
                }
            }
        }
        usort($entries, static fn (array $a, array $b): int => strcmp(
            (string) ($b['promoted_at'] ?? ''),
            (string) ($a['promoted_at'] ?? '')
        ));
        return $entries;
    }

    public function findCandidate(string $candidateId): ?array
    {
        foreach ($this->listCandidates(null) as $entry) {
            if ((string) ($entry['id'] ?? '') === $candidateId) {
                return $entry;
            }
        }
        return null;
    }

    public function dismiss(string $candidateId, ?string $reason = null): array
    {
        $candidate = $this->findCandidate($candidateId);
        if ($candidate === null) {
            throw new RuntimeException('dev_to_forge_candidate_not_found');
        }
        $candidate['candidate_status'] = 'dismissed';
        $candidate['decided_at'] = now()->toJSON();
        $candidate['decision_reason'] = $reason !== null ? trim($reason) : null;
        $this->persist($candidate);
        return $candidate;
    }

    /**
     * @param  array<int, array<string,mixed>>  $messages
     * @param  array<string,mixed>  $signalReport
     * @param  array<string,mixed>|null  $workspace
     * @return array<string,mixed>
     */
    private function buildPayload(
        AiThread $thread,
        array $messages,
        array $signalReport,
        ?array $workspace,
    ): array {
        $workspaceSlug = (string) ($signalReport['workspace_slug'] ?? '');
        if ($workspace !== null && $workspaceSlug === '') {
            $workspaceSlug = (string) ($workspace['slug'] ?? 'atlas');
        }
        if ($workspaceSlug === '') {
            $workspaceSlug = $this->profiles->defaultSlug();
        }

        $detectedFiles = (array) ($signalReport['detected_files'] ?? []);
        $signals = (array) ($signalReport['signals'] ?? []);
        $reasons = (array) ($signalReport['reasons'] ?? []);

        $titleSeed = (string) ($thread->title ?? '') !== ''
            ? (string) $thread->title
            : ($messages[0]['content'] ?? 'Promoção do Atlas Dev');
        $title = $this->buildTitle($titleSeed);
        $objective = $this->buildObjective($thread, $messages);
        $contextSummary = $this->buildContextSummary($thread, $messages, $signalReport);

        $risks = $this->buildRisks($signalReport);
        $openQuestions = $this->buildOpenQuestions($messages, $signalReport);
        $criteria = $this->buildSuccessCriteria($signalReport, $detectedFiles);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => null,
            'source_thread_id' => (string) $thread->getKey(),
            'workspace_slug' => $workspaceSlug,
            'workspace_name' => $workspace['name'] ?? null,
            'workspace_production_status' => $workspace['production_status'] ?? null,
            'workspace_default_risk' => $workspace['default_risk'] ?? null,
            'workspace_path' => $workspace['workspace_path'] ?? null,
            'title' => $title,
            'objective' => $objective,
            'context_summary' => $contextSummary,
            'known_files' => $detectedFiles,
            'risks' => $risks,
            'open_questions' => $openQuestions,
            'suggested_success_criteria' => $criteria,
            'suggested_next_step' => $this->buildNextStep($signalReport),
            'promotion_target' => (string) ($signalReport['recommended_target'] ?? 'none'),
            'signal_report' => $signalReport,
            'reasons' => $reasons,
            'signals' => $signals,
            'message_count' => (int) ($signalReport['message_count'] ?? count($messages)),
            'preview_only' => true,
            'candidate_status' => 'preview',
            'promoted_obra_id' => null,
            'promoted_at' => null,
            'generated_at' => now()->toJSON(),
        ];
    }

    private function buildTitle(string $seed): string
    {
        $clean = preg_replace('/\s+/u', ' ', $seed) ?? $seed;
        $clean = trim((string) $clean);
        if ($clean === '') {
            return 'Promoção do Atlas Dev';
        }
        if (mb_strlen($clean) > 120) {
            return mb_substr($clean, 0, 117).'…';
        }
        return $clean;
    }

    /**
     * @param  array<int, array<string,mixed>>  $messages
     */
    private function buildObjective(AiThread $thread, array $messages): string
    {
        $summary = (string) ($thread->summary ?? '');
        if (trim($summary) !== '') {
            return $summary;
        }
        // Fallback: take the first user message as the operator's intent.
        foreach ($messages as $msg) {
            if (in_array((string) ($msg['role'] ?? ''), ['user', 'human'], true)) {
                $content = $this->plainText((string) ($msg['content'] ?? ''));
                if ($content !== '') {
                    return mb_substr($content, 0, 480);
                }
            }
        }
        return '—';
    }

    /**
     * @param  array<int, array<string,mixed>>  $messages
     * @param  array<string,mixed>  $signalReport
     */
    private function buildContextSummary(AiThread $thread, array $messages, array $signalReport): string
    {
        $bits = [];
        $bits[] = sprintf(
            'Thread `%s` com %d mensagens.',
            mb_substr((string) $thread->getKey(), 0, 12),
            (int) ($signalReport['message_count'] ?? count($messages))
        );
        $userTurns = (int) ($signalReport['signals']['message_density']['user_turns'] ?? 0);
        $assistantTurns = (int) ($signalReport['signals']['message_density']['assistant_turns'] ?? 0);
        if ($userTurns > 0 || $assistantTurns > 0) {
            $bits[] = sprintf('Turnos humano/atlas: %d/%d.', $userTurns, $assistantTurns);
        }
        $arch = (int) ($signalReport['signals']['architecture']['hits'] ?? 0);
        $risk = (int) ($signalReport['signals']['risk']['hits'] ?? 0);
        if ($arch > 0 || $risk > 0) {
            $bits[] = sprintf('Sinais: arquitetura=%d, risco=%d.', $arch, $risk);
        }
        $files = (array) ($signalReport['detected_files'] ?? []);
        if ($files !== []) {
            $bits[] = sprintf('%d arquivo(s) mencionado(s).', count($files));
        }
        $bits[] = 'Atlas Dev iniciou o trabalho; promoção é um sinal de que ele deve virar Obra governada (ou Intervenção Rápida) com escopo, gates e evidência.';
        return implode(' ', $bits);
    }

    /**
     * @param  array<string,mixed>  $signalReport
     * @return array<int,string>
     */
    private function buildRisks(array $signalReport): array
    {
        $risks = [];
        $signals = (array) ($signalReport['signals'] ?? []);
        if (! empty($signals['risk']['detected'])) {
            $risks[] = 'Sinais de risco detectados na conversa (produção/migration/segurança).';
        }
        if (! empty($signals['recurring_failure']['detected'])) {
            $risks[] = sprintf(
                'Falha recorrente: %d na conversa + %d nos traces.',
                (int) ($signals['recurring_failure']['message_failure_matches'] ?? 0),
                (int) ($signals['recurring_failure']['trace_failure_count'] ?? 0),
            );
        }
        if (! empty($signals['architecture']['detected'])) {
            $risks[] = 'Decisão arquitetural; promova com spec/plan antes de executar.';
        }
        if (! empty($signals['file_breadth']['count']) && (int) $signals['file_breadth']['count'] >= 6) {
            $risks[] = 'Múltiplos arquivos/subsistemas envolvidos (file_breadth ≥6).';
        }
        $production = (string) ($signalReport['workspace_production_status'] ?? '');
        if ($production === 'production') {
            $risks[] = 'Workspace em produção — risco padrão maior; rollback obrigatório.';
        }
        return $risks;
    }

    /**
     * @param  array<int, array<string,mixed>>  $messages
     * @param  array<string,mixed>  $signalReport
     * @return array<int,string>
     */
    private function buildOpenQuestions(array $messages, array $signalReport): array
    {
        $questions = [];
        $userQuestions = 0;
        foreach (array_reverse($messages) as $msg) {
            if ($userQuestions >= 3) {
                break;
            }
            if (! in_array((string) ($msg['role'] ?? ''), ['user', 'human'], true)) {
                continue;
            }
            $content = $this->plainText((string) ($msg['content'] ?? ''));
            if (str_contains($content, '?')) {
                $clean = mb_substr($content, 0, 240);
                $questions[] = $clean;
                $userQuestions++;
            }
        }
        if (empty($signalReport['signals']['architecture']['detected']) === false) {
            $questions[] = 'Qual o impacto arquitetural mínimo viável vs ideal?';
        }
        if (empty($signalReport['signals']['risk']['detected']) === false) {
            $questions[] = 'Quais salvaguardas são necessárias antes de promover (rollback/feature flag)?';
        }
        return array_values(array_unique($questions));
    }

    /**
     * @param  array<string,mixed>  $signalReport
     * @param  array<int,string>  $detectedFiles
     * @return array<int,string>
     */
    private function buildSuccessCriteria(array $signalReport, array $detectedFiles): array
    {
        $criteria = [
            'Objetivo entregue com diff revisado e gates verdes.',
            'Sem regressão nos comandos de validação do Projeto.',
        ];
        if ($detectedFiles !== []) {
            $criteria[] = sprintf('Mudanças escopadas a %d arquivo(s) declarado(s).', count($detectedFiles));
        }
        if (! empty($signalReport['signals']['risk']['detected'])) {
            $criteria[] = 'Rollback documentado e testado antes do merge.';
        }
        return $criteria;
    }

    /**
     * @param  array<string,mixed>  $signalReport
     */
    private function buildNextStep(array $signalReport): string
    {
        $target = (string) ($signalReport['recommended_target'] ?? 'none');
        return match ($target) {
            PromotionSignalDetector::TARGET_FORGE_OBRA => 'Criar Obra Forge a partir desta thread; preparar Work Packet inicial em Operating Room.',
            PromotionSignalDetector::TARGET_OBRA_CANDIDATE => 'Persistir como Candidato de Obra; humano decide promover/dispensar em Atenção.',
            PromotionSignalDetector::TARGET_QUICK_INTERVENTION => 'Considerar Intervenção Rápida: escopo único, reversível, sem ampliação.',
            default => 'Sinais ainda fracos; continue em Atlas Dev e revise se um sinal forte aparecer.',
        };
    }

    private function plainText(mixed $content): string
    {
        if (is_string($content)) {
            return $this->normalise($content);
        }
        if (is_array($content)) {
            $text = (string) ($content['text'] ?? '');
            return $this->normalise($text);
        }
        return '';
    }

    private function normalise(string $raw): string
    {
        $clean = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
        return trim((string) $clean);
    }

    private function resolveWorkspace(?string $slug): ?array
    {
        if ($slug === null || trim($slug) === '') {
            return null;
        }
        return $this->profiles->findBySlug($slug);
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function persist(array $candidate): void
    {
        $candidateId = (string) ($candidate['id'] ?? '');
        $workspaceSlug = (string) ($candidate['workspace_slug'] ?? 'atlas');
        if ($candidateId === '') {
            throw new RuntimeException('dev_to_forge_persist_missing_id');
        }
        $dir = $this->candidatesBaseDir().'/'.$this->safeSlug($workspaceSlug);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir.'/'.$this->safeSegment($candidateId).'.json';
        $written = @file_put_contents(
            $path,
            json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        if ($written === false) {
            throw new RuntimeException("dev_to_forge_persist_failed:{$path}");
        }
    }

    private function candidatesBaseDir(): string
    {
        return storage_path('app/atlas-code/promotion-candidates');
    }

    private function safeSlug(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', trim(strtolower($value)));
        return is_string($clean) && $clean !== '' ? $clean : 'atlas';
    }

    private function safeSegment(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', $value);
        if (! is_string($clean) || $clean === '') {
            throw new RuntimeException('dev_to_forge_unsafe_id');
        }
        return $clean;
    }
}
