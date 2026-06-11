<?php

namespace App\Services\Ai;

use App\Services\Ai\Attachments\AiAttachmentIndexService;
use App\Services\Ai\Context\RetrievalRankInput;
use App\Services\Ai\Search\SessionSearchService;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use App\Services\Ai\Skills\SkillManifest;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\ValueObjects\AiPromptExecutionPlan as AiExecutionPlan;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiPromptBuilder
{
    public function __construct(
        private readonly AiSkillStore $skills,
        private readonly AiIntentRouter $router,
        private readonly AiContextPackBuilder $contexts,
        private readonly SkillDiscoveryService $skillDiscovery,
        private readonly SkillBundleStore $skillBundles,
        private readonly SessionSearchService $sessionSearch,
        private readonly ?AiAttachmentIndexService $attachmentIndex = null,
        private readonly ?AtlasOpenBrainContextInjectionService $openBrainInjection = null,
        private readonly ?RetrievalRankInput $retrievalRankInput = null,
    ) {}

    public function build(string $input, array $options = []): AiPrompt
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $this->bootSkillBundles($options);
        $requestedAgent = $options['agent_slug'] ?? null;
        if (! $requestedAgent && data_get($payload, 'atlas_workflow_mode') === 'dev') {
            $requestedAgent = 'desenvolvedor';
        }

        $route = $this->router->route($input, $requestedAgent);
        $requestedBundleSkills = $this->requestedBundleSkills($payload);
        $automaticBundleSkill = $requestedAgent ? null : $this->bestBundleSkillForInput($input);
        if ($automaticBundleSkill && $this->legacySkillExists($automaticBundleSkill)) {
            $route = [
                'agent' => $automaticBundleSkill,
                'intent' => 'bundle_description:'.$automaticBundleSkill,
            ];
        }

        $agent = $this->skills->resolveSlug((string) $route['agent']);
        $route['agent'] = $agent;
        $intent = (string) $route['intent'];
        $taskRequest = AiTaskRequest::fromInput($input, $options, $route);
        $options = $this->optionsWithPolicyRequiredOpenBrain($options);
        $contextPack = $this->contexts->build($input, $taskRequest, $options);
        $openBrain = $this->openBrainInjection ?? app(AtlasOpenBrainContextInjectionService::class);
        $openBrainInjection = $openBrain->inject($input, $taskRequest, $contextPack, $options);
        $openBrain->assertAllowed($openBrainInjection);
        $openBrainMetadata = $this->openBrainMetadata($openBrainInjection);
        $executionPlan = AiExecutionPlan::fromTask(
            task: $taskRequest,
            agent: $agent,
            provider: is_string($options['provider'] ?? null) ? $options['provider'] : null,
            options: $options,
        );

        $master = $this->skills->masterPrompt();
        $skill = $this->skills->load($agent);
        $activatedBundleNames = $this->activatedBundleNames($requestedBundleSkills, $automaticBundleSkill, $agent, $options);
        $outputGovernor = $this->shouldAttachOutputGovernor($options, $agent) && ! in_array('comunicador-claro', $activatedBundleNames, true)
            ? $this->skills->load('comunicador-claro')
            : null;
        $contextRefs = $this->contextRefsWithOpenBrain($contextPack->contextRefs(), $openBrainInjection);
        $activatedBundles = $this->activatedBundles($activatedBundleNames);
        $activeAgentBundle = $this->skillBundles->find($agent);
        $catalog = $this->skillBundles->catalog();
        $sessionSearchSection = $this->sessionSearchSection($input, $options);
        $attachmentSearchSection = $this->attachmentSearchSection($input, $options);
        $youtubeKnowledgeSection = $this->youtubeKnowledgeSection($options);
        $activeSkillSection = $activeAgentBundle
            ? "# Skill ativa: {$skill->title}\n\nA skill ativa esta carregada como bundle agentskills.io em <skill_content name=\"{$activeAgentBundle->name}\">. Use esse bloco como fonte procedural principal."
            : "# Skill ativa: {$skill->title}\n\n{$skill->body}";

        $prompt = implode("\n\n", array_filter([
            "# Identidade Atlas\n\n{$master->body}",
            $activeSkillSection,
            $outputGovernor && $outputGovernor->slug !== $skill->slug
                ? "# Skill auxiliar obrigatoria: {$outputGovernor->title}\n\n{$outputGovernor->body}"
                : null,
            $this->skillCatalogSection($catalog),
            $sessionSearchSection,
            $attachmentSearchSection,
            $youtubeKnowledgeSection,
            $this->persistentContextPromptSection($options),
            $this->awisRuntimeContextPromptSection($options),
            $this->contextPackPromptSection($contextPack, $openBrainInjection),
            $executionPlan->toPromptSection(),
            $this->atlasModeInstructions($options),
            $this->harnessInstructionSection(),
            $this->specialistFlowInstructions($options),
            $this->permissionInstructions($options),
            $this->workflowInstructions($options),
            $this->attachmentInstructions($options, $input),
            $this->voiceResponseInstructions($options),
            $this->outputContract($options),
            $this->activatedSkillContentSection($activatedBundles),
            "# Pedido do operador\n\n{$input}",
            <<<'TXT'
# Instrucoes de execucao

Responda em portugues brasileiro.
Use a identidade Atlas acima.
Se o contexto recuperado nao for suficiente, declare a lacuna.
Use o contexto conversacional recente para entender respostas curtas como A, B, C, "ambos", "isso" e "continua".
Quando houver referência a conversa antiga, decisão anterior, "como falamos", continuidade entre sessões ou contexto histórico provável, use a ferramenta Atlas `session.search` antes de responder.
Trate resultados de `session.search` como contexto recuperado de sessão, não como memória permanente validada.
Nao anuncie "contexto mapeado" nem despeje lista de contexto interno; use contexto silenciosamente e responda ao pedido.
Nao mencione detalhes internos de provider, CLI, prompts ou traces, a menos que o operador peca.
TXT,
        ]));

        return new AiPrompt(
            prompt: $prompt,
            agentSlug: $agent,
            intent: $intent,
            skillVersions: [
                'master' => [
                    'path' => $master->path,
                    'hash' => $master->contentHash,
                    'version' => $master->version(),
                ],
                $skill->slug => [
                    'path' => $skill->path,
                    'hash' => $skill->contentHash,
                    'version' => $skill->version(),
                ],
            ] + ($outputGovernor && $outputGovernor->slug !== $skill->slug ? [
                $outputGovernor->slug => [
                    'path' => $outputGovernor->path,
                    'hash' => $outputGovernor->contentHash,
                    'version' => $outputGovernor->version(),
                    'role' => 'output_governor',
                ],
            ] : []) + $this->bundleSkillVersions($activatedBundles),
            contextRefs: $contextRefs,
            model: $options['model'] ?? null,
            taskRequest: $taskRequest->toArray(),
            contextPack: $contextPack->toArray(),
            executionPlan: $executionPlan->toArray(),
            activatedSkills: $activatedBundles->map(fn (SkillManifest $manifest): array => $manifest->activationMetadata())->values()->all(),
            skillCatalog: $catalog,
            openBrainInjection: $openBrainMetadata,
        );
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function persistentContextPromptSection(array $options): string
    {
        $runtime = data_get($options, 'payload.persistent_context');
        if (! is_array($runtime) || ($runtime['schema_version'] ?? null) !== 'atlas.persistent_context.runtime.v1') {
            return '';
        }

        $handoff = is_array($runtime['provider_handoff'] ?? null) ? $runtime['provider_handoff'] : [];
        $ledgerItems = array_slice((array) data_get($runtime, 'must_know_ledger.items', []), 0, 12);
        $readFirst = array_slice((array) data_get($handoff, 'read_first', []), 0, 8);
        $required = array_slice((array) data_get($handoff, 'required_before_execution', []), 0, 8);
        $blockers = array_slice((array) data_get($runtime, 'sufficiency.blockers', []), 0, 8);

        $lines = [
            '# Atlas Persistent Context Runtime',
            '',
            'Use este bloco como contexto obrigatorio antes de responder. Ele existe para impedir que a sessao/provider nasca sem memoria operacional.',
            '- Status: '.(string) ($runtime['status'] ?? 'unknown'),
            '- Sufficiency: '.(string) data_get($runtime, 'sufficiency.status', 'unknown'),
            '- Context pack hash: '.(string) ($runtime['context_pack_hash'] ?? 'missing'),
            '- Must-know ledger hash: '.(string) ($runtime['must_know_ledger_hash'] ?? 'missing'),
            '- Provider handoff hash: '.(string) data_get($handoff, 'context_pack_hash', 'missing'),
            '- Execution allowed: '.((bool) data_get($handoff, 'execution_allowed', false) ? 'yes' : 'no'),
        ];

        if ($readFirst !== []) {
            $lines[] = '';
            $lines[] = 'Read-first refs:';
            foreach ($readFirst as $ref) {
                if (is_scalar($ref)) {
                    $lines[] = '- '.(string) $ref;
                }
            }
        }

        if ($ledgerItems !== []) {
            $lines[] = '';
            $lines[] = 'Must-know ledger:';
            foreach ($ledgerItems as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $digest = trim((string) ($item['digest'] ?? ''));
                if ($digest === '') {
                    continue;
                }

                $lines[] = '- '.(string) ($item['kind'] ?? 'fact').': '.$digest;
            }
        }

        if ($required !== []) {
            $lines[] = '';
            $lines[] = 'Antes de executar:';
            foreach ($required as $rule) {
                if (is_scalar($rule)) {
                    $lines[] = '- '.(string) $rule;
                }
            }
        }

        if ($blockers !== []) {
            $lines[] = '';
            $lines[] = 'Blockers de contexto:';
            foreach ($blockers as $blocker) {
                $lines[] = '- '.(is_scalar($blocker) ? (string) $blocker : json_encode($blocker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        $lines[] = '';
        $lines[] = 'Nao invente source refs. Se este bloco disser execution_allowed=no ou sufficiency=blocked, declare o bloqueio antes de executar.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function awisRuntimeContextPromptSection(array $options): string
    {
        $context = data_get($options, 'payload.awis_runtime_context');
        if (! is_array($context) || ($context['schema_version'] ?? null) !== 'atlas.awis.runtime_context_hint.v1') {
            return '';
        }

        $workspaceName = $this->awisPromptScalar(
            data_get($context, 'workspace.name', data_get($context, 'workspace.key')),
            'workspace desconhecido',
        );
        $neverStartCold = (bool) data_get($context, 'never_start_cold', false);
        $files = $this->awisPromptList(data_get($context, 'working_set.files', []), 5);
        $docs = $this->awisPromptList(data_get($context, 'working_set.docs', []), 5);
        $commands = $this->awisPromptList(data_get($context, 'working_set.commands', []), 5);

        $lines = [
            '# Atlas Workspace Intelligence System',
            '',
            'Use este bloco como contexto operacional compacto do workspace antes de responder. Ele existe para impedir que a sessão nasça fria.',
            '- Workspace: '.$workspaceName,
            '- Nunca iniciar frio: '.($neverStartCold ? 'sim' : 'não'),
        ];

        $startupLines = [];
        $launchMode = $this->awisPromptScalar(data_get($context, 'startup_contract.launch_mode'), '');
        if ($launchMode !== '') {
            $startupLines[] = 'modo de partida: '.$launchMode;
        }
        $contextMode = $this->awisPromptScalar(data_get($context, 'startup_contract.context_mode'), '');
        if ($contextMode !== '') {
            $startupLines[] = 'modo de contexto: '.$contextMode;
        }
        if ((bool) data_get($context, 'startup_contract.prefer_summary', false)) {
            $startupLines[] = 'preferir resumo antes de expandir';
        }
        $readiness = array_filter([
            'partida' => data_get($context, 'startup_contract.readiness.startup'),
            'kernel' => data_get($context, 'startup_contract.readiness.context_kernel'),
            'artifact' => data_get($context, 'startup_contract.readiness.artifact_replay'),
            'próxima sessão' => data_get($context, 'startup_contract.readiness.next_session_brain'),
        ], fn ($value): bool => is_int($value) || is_float($value));
        foreach ($readiness as $label => $value) {
            $startupLines[] = 'readiness '.$label.': '.max(0, min(100, (int) round($value))).'%';
        }
        $startupLines = [
            ...$startupLines,
            ...array_map(fn (string $item): string => 'sequência: '.$item, $this->awisPromptList(data_get($context, 'startup_contract.load_sequence', []), 6)),
            ...array_map(fn (string $item): string => 'revalidar antes de enviar: '.$item, $this->awisPromptList(data_get($context, 'startup_contract.revalidate_before_send', []), 6)),
            ...array_map(fn (string $item): string => 'fronteira humana: '.$item, $this->awisPromptList(data_get($context, 'startup_contract.human_boundary', []), 4)),
        ];
        if ($startupLines !== []) {
            $lines = [
                ...$lines,
                ...$this->awisPromptSectionLines('Contrato de partida', $startupLines),
            ];
        }

        $lines = [
            ...$lines,
            ...$this->awisPromptSectionLines('Carregar primeiro', $this->awisPromptList(data_get($context, 'load_first', []), 8)),
            ...$this->awisPromptSectionLines('Resumo ouro', $this->awisPromptList(data_get($context, 'use_as_summary', []), 6)),
            ...$this->awisPromptSectionLines('Validar com', $this->awisPromptList(data_get($context, 'validate_with', []), 6)),
            ...$this->awisPromptSectionLines('Evitar carregar', $this->awisPromptList(data_get($context, 'avoid_loading', []), 6)),
        ];

        if ($files !== [] || $docs !== [] || $commands !== []) {
            $lines[] = '';
            $lines[] = 'Working set provável:';
            foreach ($files as $file) {
                $lines[] = '- arquivo: '.$file;
            }
            foreach ($docs as $doc) {
                $lines[] = '- doc: '.$doc;
            }
            foreach ($commands as $command) {
                $lines[] = '- comando: '.$command;
            }
        }

        $verifyBeforeTrust = $this->awisPromptList(data_get($context, 'evidence_gate.verify_before_trust', []), 4);
        $humanBoundary = $this->awisPromptList(data_get($context, 'evidence_gate.human_boundary', []), 4);
        if ($verifyBeforeTrust !== [] || $humanBoundary !== []) {
            $lines[] = '';
            $lines[] = 'Evidence gate:';
            foreach ($verifyBeforeTrust as $rule) {
                $lines[] = '- verificar antes de confiar: '.$rule;
            }
            foreach ($humanBoundary as $boundary) {
                $lines[] = '- fronteira humana: '.$boundary;
            }
        }

        $spaceLines = [
            ...array_map(fn (string $item): string => 'Space ativo: '.$item, $this->awisPromptList(data_get($context, 'space_context.active_spaces', []), 4)),
            ...array_map(fn (string $item): string => 'Space forte: '.$item, $this->awisPromptList(data_get($context, 'space_context.strongest_spaces', []), 5)),
            ...array_map(fn (string $item): string => 'carregar: '.$item, $this->awisPromptList(data_get($context, 'space_context.load_first', []), 6)),
            ...array_map(fn (string $item): string => 'manter: '.$item, $this->awisPromptList(data_get($context, 'space_context.carry_forward', []), 6)),
            ...array_map(fn (string $item): string => 'validar: '.$item, $this->awisPromptList(data_get($context, 'space_context.validate_before_use', []), 5)),
            ...array_map(fn (string $item): string => 'limite humano: '.$item, $this->awisPromptList(data_get($context, 'space_context.human_boundary', []), 4)),
            ...array_map(fn (string $item): string => 'artifact: '.$item, $this->awisPromptList(data_get($context, 'space_context.artifact_refs', []), 4)),
        ];
        if ($spaceLines !== []) {
            $lines = [
                ...$lines,
                ...$this->awisPromptSectionLines('Spaces vivos', $spaceLines),
            ];
        }

        $artifactLines = [];
        if ((bool) data_get($context, 'artifact_context.replay_ready', false)) {
            $artifactLines[] = 'replay pronto';
        }
        $latestArtifactHash = $this->awisPromptScalar(data_get($context, 'artifact_context.latest_artifact_hash'), '');
        if ($latestArtifactHash !== '') {
            $artifactLines[] = 'artifact recente: '.$latestArtifactHash;
        }
        $artifactLines = [
            ...$artifactLines,
            ...array_map(fn (string $item): string => 'carregar: '.$item, $this->awisPromptList(data_get($context, 'artifact_context.load_order', []), 5)),
            ...array_map(fn (string $item): string => 'validar: '.$item, $this->awisPromptList(data_get($context, 'artifact_context.validate_with', []), 4)),
            ...array_map(fn (string $item): string => 'padrão reutilizável: '.$item, $this->awisPromptList(data_get($context, 'artifact_context.reusable_patterns', []), 5)),
            ...array_map(fn (string $item): string => 'Space preservado: '.$item, $this->awisPromptList(data_get($context, 'artifact_context.strongest_spaces', []), 4)),
            ...array_map(fn (string $item): string => 'atenção: '.$item, $this->awisPromptList(data_get($context, 'artifact_context.warnings', []), 4)),
        ];
        if ($artifactLines !== []) {
            $lines = [
                ...$lines,
                ...$this->awisPromptSectionLines('Artifacts reutilizáveis', $artifactLines),
            ];
        }

        $recentMaintenance = $this->awisPromptList(data_get($context, 'continue_learning.maintenance_recent', []), 5);
        if ($recentMaintenance !== []) {
            $lines = [
                ...$lines,
                ...$this->awisPromptSectionLines('Manutenção recente AWIS', $recentMaintenance),
            ];
        }

        $lines = [
            ...$lines,
            ...$this->awisPromptSectionLines('Próxima sessão · carregar', $this->awisPromptList(data_get($context, 'next_session.first_load', []), 6)),
            ...$this->awisPromptSectionLines('Próxima sessão · validar', $this->awisPromptList(data_get($context, 'next_session.validate_with', []), 5)),
            ...$this->awisPromptSectionLines('Promover para memória quando', $this->awisPromptList(data_get($context, 'next_session.promote_when', []), 5)),
            ...$this->awisPromptSectionLines('Rebaixar quando', $this->awisPromptList(data_get($context, 'next_session.demote_when', []), 5)),
        ];

        $learning = [
            'record_outcome' => 'registrar resultado real',
            'update_memory' => 'atualizar memória AWIS',
            'update_space_pack' => 'atualizar Space pack',
            'preserve_artifact_after_success' => 'preservar artifact após sucesso',
        ];
        $enabledLearning = [];
        foreach ($learning as $key => $label) {
            if ((bool) data_get($context, 'continue_learning.'.$key, false)) {
                $enabledLearning[] = $label;
            }
        }
        if ($enabledLearning !== []) {
            $lines = [
                ...$lines,
                ...$this->awisPromptSectionLines('Aprendizado contínuo', $enabledLearning),
            ];
        }

        $lines[] = '';
        $lines[] = 'Não trate este bloco como conversa bruta. Se algo estiver ausente ou inseguro, declare a lacuna e use contexto verificável.';

        return implode("\n", $lines);
    }

    /**
     * @return array<int,string>
     */
    private function awisPromptList(mixed $values, int $limit = 6): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '' && ! $this->awisPromptValueIsUnsafe($value))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $items
     * @return array<int,string>
     */
    private function awisPromptSectionLines(string $title, array $items): array
    {
        if ($items === []) {
            return [];
        }

        return [
            '',
            $title.':',
            ...array_map(fn (string $item): string => '- '.$item, $items),
        ];
    }

    private function awisPromptScalar(mixed $value, string $fallback): string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);

        return $value !== '' && ! $this->awisPromptValueIsUnsafe($value) ? $value : $fallback;
    }

    private function awisPromptValueIsUnsafe(string $value): bool
    {
        return preg_match('/\/Users\/|thread_id|source_thread_ids|raw_conversation|response_text|operator_input|full_message/i', $value) === 1;
    }

    /**
     * @param  array<int,array<string,mixed>>  $contextRefs
     * @param  array<string,mixed>  $openBrainInjection
     * @return array<int,array<string,mixed>>
     */
    private function contextRefsWithOpenBrain(array $contextRefs, array $openBrainInjection): array
    {
        $openBrainRefs = is_array($openBrainInjection['context_refs'] ?? null) ? $openBrainInjection['context_refs'] : [];

        return collect([...$contextRefs, ...$openBrainRefs])
            ->filter(fn (mixed $ref): bool => is_array($ref))
            ->unique(fn (array $ref): string => (string) ($ref['type'] ?? 'unknown').':'.(string) ($ref['id'] ?? $ref['slug'] ?? $ref['canonical_path'] ?? $ref['root_path'] ?? md5(json_encode($ref) ?: '')))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $openBrainInjection
     */
    private function contextPackPromptSection($contextPack, array $openBrainInjection): string
    {
        $section = $openBrainInjection['prompt_section'] ?? null;

        return is_string($section) && trim($section) !== ''
            ? $section
            : $contextPack->toPromptSection();
    }

    /**
     * @param  array<string,mixed>  $openBrainInjection
     * @return array<string,mixed>
     */
    private function openBrainMetadata(array $openBrainInjection): array
    {
        unset($openBrainInjection['prompt_section'], $openBrainInjection['context_refs']);

        return $openBrainInjection;
    }

    private function bootSkillBundles(array $options): void
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $workspace = data_get($payload, 'workspace', data_get($options, 'workspace'));
        if (! is_string($workspace) || trim($workspace) === '') {
            $workspace = (string) config('atlas.ai.workdir', dirname(base_path()));
        }

        // Discovery direto: scaneia o workspace a cada chamada.
        //
        // Tinha um Cache::remember aqui (5min TTL) que serializava objetos
        // SkillManifest. O custo do scan é ~100ms num caminho que termina
        // gastando 10-60s no provider de IA — ganho imperceptível. O cache
        // de objetos PHP é frágil: qualquer drift de classe vira
        // __PHP_Incomplete_Class no unserialize, quebrando o type hint.
        // Removido em favor da simplicidade.
        $this->skillBundles->clear();
        $this->skillBundles->registerAll($this->skillDiscovery->discoverAll($workspace));
    }

    /**
     * @return array<int,string>
     */
    private function requestedBundleSkills(array $payload): array
    {
        return collect([
            ...((array) data_get($payload, 'activated_skills', [])),
            ...((array) data_get($payload, 'programming_policy_contracts.skills.required_bundles', [])),
            ...((array) data_get($payload, 'programming_message_plan.policy_contracts.skills.required_bundles', [])),
            ...((array) data_get($payload, 'programming_message_plan.policy_profile.policy_contracts.skills.required_bundles', [])),
            ...((array) data_get($payload, 'programming_message_plan.policy_profile.effective_policy.operational_contracts.skills.required_bundles', [])),
            ...((array) data_get($payload, 'programming_dispatch.policy_contracts.skills.required_bundles', [])),
        ])
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (mixed $name): string => Str::of((string) $name)->lower()->trim()->value())
            ->unique()
            ->values()
            ->all();
    }

    private function optionsWithPolicyRequiredOpenBrain(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $mode = data_get($options, 'open_brain.mode', data_get($payload, 'open_brain.mode'));
        if (is_string($mode) && trim($mode) !== '') {
            return $options;
        }

        $contracts = $this->programmingPolicyContracts($payload);
        $includeMemory = (bool) data_get($contracts, 'context.include_memory', data_get($contracts, 'memory.scope') !== null);
        if (! $includeMemory) {
            return $options;
        }

        $options['open_brain'] = array_merge((array) ($options['open_brain'] ?? []), [
            'mode' => 'auto',
        ]);

        return $options;
    }

    private function programmingPolicyContracts(array $payload): array
    {
        $messagePlan = is_array($payload['programming_message_plan'] ?? null)
            ? $payload['programming_message_plan']
            : [];
        $dispatch = is_array($payload['programming_dispatch'] ?? null)
            ? $payload['programming_dispatch']
            : [];
        $contracts = data_get($payload, 'programming_policy_contracts')
            ?: data_get($messagePlan, 'policy_contracts')
            ?: data_get($messagePlan, 'policy_profile.policy_contracts')
            ?: data_get($messagePlan, 'policy_profile.effective_policy.operational_contracts')
            ?: data_get($dispatch, 'policy_contracts');

        return is_array($contracts) ? $contracts : [];
    }

    /**
     * @param  array<int,string>  $requested
     * @return array<int,string>
     */
    private function activatedBundleNames(array $requested, ?string $automatic, string $agent, array $options): array
    {
        $names = $requested;
        if ($automatic) {
            $names[] = $automatic;
        }

        if ($this->skillBundles->find($agent)) {
            $names[] = $agent;
        }

        if ($this->shouldAttachOutputGovernor($options, $agent) && $this->skillBundles->find('comunicador-claro')) {
            $names[] = 'comunicador-claro';
        }

        return collect($names)
            ->filter(fn (string $name): bool => $this->skillBundles->find($name) !== null)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $names
     * @return Collection<int,SkillManifest>
     */
    private function activatedBundles(array $names)
    {
        return collect($names)
            ->map(fn (string $name): ?SkillManifest => $this->skillBundles->find($name))
            ->filter()
            ->values();
    }

    private function bestBundleSkillForInput(string $input): ?string
    {
        $inputWords = $this->keywords($input);
        if ($inputWords === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($this->skillBundles->all() as $manifest) {
            if ($manifest->name === 'comunicador-claro') {
                continue;
            }

            $haystackWords = $this->keywords($manifest->name.' '.$manifest->description);
            if ($haystackWords === []) {
                continue;
            }

            $hits = count(array_intersect($inputWords, $haystackWords));
            $score = $hits / max(4, count($inputWords));
            if ($score > $bestScore) {
                $best = $manifest->name;
                $bestScore = $score;
            }
        }

        return $bestScore >= 0.22 ? $best : null;
    }

    /**
     * @return array<int,string>
     */
    private function keywords(string $text): array
    {
        $normalized = Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->value();
        $stop = ['para', 'com', 'uma', 'que', 'quando', 'onde', 'como', 'de', 'do', 'da', 'dos', 'das', 'the', 'and', 'use', 'when'];

        return collect(preg_split('/\s+/', $normalized) ?: [])
            ->filter(fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, $stop, true))
            ->unique()
            ->values()
            ->all();
    }

    private function legacySkillExists(string $slug): bool
    {
        try {
            $this->skills->load($slug);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $catalog
     */
    private function skillCatalogSection(array $catalog): string
    {
        if ($catalog === []) {
            return '';
        }

        $lines = ['# Available Skills'];
        foreach ($catalog as $entry) {
            $compatibility = is_string($entry['compatibility'] ?? null) && $entry['compatibility'] !== ''
                ? ' ['.$entry['compatibility'].']'
                : '';
            $lines[] = '- '.$entry['name'].': '.$entry['description'].$compatibility;
        }

        $lines[] = '';
        $lines[] = 'Use uma skill quando o pedido combinar com a descricao. Nao carregue referencias, scripts ou assets automaticamente; solicite leitura sob demanda quando necessario.';

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int,SkillManifest>  $manifests
     */
    private function activatedSkillContentSection($manifests): string
    {
        if ($manifests->isEmpty()) {
            return '';
        }

        return $manifests
            ->map(function (SkillManifest $manifest): string {
                $resources = collect($manifest->resourceFiles())
                    ->map(fn (string $path): string => "    <file>{$path}</file>")
                    ->implode("\n");
                $resources = $resources !== '' ? "\n  <skill_resources>\n{$resources}\n  </skill_resources>" : '';

                return <<<TXT
<skill_content name="{$manifest->name}">
{$manifest->body}

Skill directory: {$manifest->directory}
Relative paths in this skill resolve to skill directory.{$resources}
</skill_content>
TXT;
            })
            ->implode("\n\n");
    }

    /**
     * @param  Collection<int,SkillManifest>  $manifests
     * @return array<string,array<string,mixed>>
     */
    private function bundleSkillVersions($manifests): array
    {
        return $manifests
            ->mapWithKeys(fn (SkillManifest $manifest): array => [
                'bundle:'.$manifest->name => [
                    'path' => $manifest->path,
                    'hash' => $manifest->contentHash,
                    'version' => data_get($manifest->metadata, 'version'),
                    'source_tier' => $manifest->sourceTier,
                    'trust_level' => $manifest->trustLevel(),
                    'role' => 'agentskills_bundle',
                ],
            ])
            ->all();
    }

    private function workflowInstructions(array $options): string
    {
        $mode = data_get($options, 'payload.atlas_workflow_mode');

        return match ($mode) {
            'semantic_clarification' => <<<'TXT'
# Modo de trabalho: Aclaramento semantico

Voce e o Aclarador do Atlas. Retorne somente JSON valido, sem markdown e sem comentario externo. Nao descarte captura curta por tamanho. Diferencie captura bruta de conhecimento validado. O JSON deve conter exatamente estas chaves de topo: main_thesis, atomic_ideas, suggested_type, tension_or_question, density, possible_destination, authorship_question, future_triggers.
TXT,
            'plan' => <<<'TXT'
# Modo de trabalho: Planejar

Nao execute acao externa. Estruture o problema, explicite premissas, riscos, ordem de implementacao, criterios de verificacao e o proximo passo concreto. Se houver tradeoff, mostre a decisao recomendada e por que ela e melhor para o Atlas agora.
TXT,
            'review' => <<<'TXT'
# Modo de trabalho: Revisar

Assuma postura de revisao rigorosa. Procure bugs, inconsistencias, riscos de arquitetura, pontos de quebra, lacunas de teste e divergencias com a identidade do Atlas. Priorize achados acionaveis antes de resumo. Nao reescreva tudo se o problema for local.
TXT,
            'dev' => <<<'TXT'
# Modo de trabalho: Desenvolvimento pesado no Mac

Atue como executor técnico do Atlas. Entenda o workspace, preserve mudanças existentes, edite com escopo claro, valide com comandos relevantes e entregue resumo operacional. Não despeje código na resposta final; cite arquivos, decisões, testes e riscos residuais. Se não puder executar ou validar algo, diga isso de forma objetiva.
TXT,
            'debug' => <<<'TXT'
# Modo de trabalho: Debug

Investigue o erro de forma operacional: reproduza quando possível, isole a causa provável, diferencie sintoma de raiz e proponha uma correção mínima. Não edite código sem permissão explícita do operador ou sem modo de escrita autorizado. Cite evidências concretas: arquivo, comando, erro, log ou teste.
TXT,
            'research' => <<<'TXT'
# Modo de trabalho: Pesquisa

Pesquise dentro do contexto realmente disponível para o Atlas: repositório, Vault, documentos enviados, contexto recuperado e traces. Cite somente fontes efetivamente fornecidas ou recuperadas pelo Atlas. Se faltar ferramenta de web/search ou se uma fonte externa não estiver disponível, declare a limitação em vez de inventar referência. Separe evidência, inferência e recomendação.
TXT,
            'quality_repair' => <<<'TXT'
# Modo de trabalho: Reparo de qualidade

Sua função é produzir a versão final que o Atlas deveria entregar ao operador. Corrija perda de continuidade, vazamento de contexto interno, excesso de código, excesso de verbosidade ou falta de clareza. Não explique o processo interno de reparo. Não mencione traces, jobs, context_pack, prompts, provider ou avaliação de qualidade.
TXT,
            default => '',
        };
    }

    /**
     * AP-819 Surface v2 — as seções de instrução AUTO-EVOLUÍDAS do harness
     * (espaço de busca finito + auditado; o autopilot só troca por variantes
     * declaradas, julgado pela suite congelada + recorrência crua). Fail-open:
     * qualquer erro aqui nunca derruba a montagem do prompt.
     */
    private function harnessInstructionSection(): ?string
    {
        try {
            return app(\App\Services\Ai\Cognitive\Harness\AtlasHarnessInstructionSurface::class)->promptSection();
        } catch (\Throwable) {
            return null;
        }
    }

    private function atlasModeInstructions(array $options): string
    {
        $contract = data_get($options, 'payload.atlas_mode_contract');
        if (! is_array($contract) || $contract === []) {
            return '';
        }

        $mode = (string) data_get($contract, 'mode', data_get($options, 'payload.atlas_mode', 'general'));
        $objective = (string) data_get($contract, 'objective', 'responder com clareza e continuidade');
        $expected = $this->stringList(data_get($contract, 'expected_output', []));
        $quality = data_get($options, 'payload.quality_policy');
        $qualityRules = is_array($quality) && $quality !== []
            ? $this->keyValueLines($quality)
            : '- sem regras adicionais';

        $modeRules = match ($mode) {
            'programming' => <<<'TXT'
- Trate esta conversa como trabalho de engenharia: plano, escopo, execução, validação e riscos.
- Se houver ferramenta/harness disponivel, use-o para leitura, comandos, scripts e testes conforme permissões.
- Se nao executar algo, explique o bloqueio operacional e deixe o proximo passo concreto.
TXT,
            'operational' => <<<'TXT'
- Trate esta conversa como operacao: explique o que aconteceu, por que importa, evidencias, risco e proxima decisao.
- Use metricas, traces, context bundles e historico antes de recomendar mudanca.
- Se o item exigir codigo, promova para programacao com briefing claro em vez de misturar diagnostico e patch sem controle.
TXT,
            default => <<<'TXT'
- Trate esta conversa como geral: nao herde contexto operacional ou de codigo por acidente.
- Responda com simplicidade, peça lacunas essenciais e sugira proximo passo apenas quando ajudar.
TXT,
        };

        $expectedText = $expected === '' ? '- resposta clara' : $expected;

        return <<<TXT
# Modo Atlas AI

Modo: {$mode}
Objetivo: {$objective}

Contrato esperado:
{$expectedText}

Politica de qualidade:
{$qualityRules}

Regras do modo:
{$modeRules}
TXT;
    }

    private function stringList(mixed $values): string
    {
        if (! is_array($values)) {
            return '';
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => '- '.trim((string) $value))
            ->implode("\n");
    }

    private function keyValueLines(array $values): string
    {
        return collect($values)
            ->map(function (mixed $value, string|int $key): string {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_array($value)) {
                    $value = implode(', ', array_map(fn (mixed $item): string => (string) $item, $value));
                } elseif (! is_scalar($value)) {
                    $value = 'n/a';
                }

                return '- '.$key.': '.trim((string) $value);
            })
            ->implode("\n");
    }

    private function specialistFlowInstructions(array $options): string
    {
        $execution = data_get($options, 'payload.specialist_flow_execution');
        if (! is_array($execution) || $execution === []) {
            return '';
        }

        $flowId = (string) data_get($execution, 'flow_id', 'unknown');
        $handlerId = (string) data_get($execution, 'handler_id', 'unknown');
        $status = (string) data_get($execution, 'status', 'ready_for_provider');
        $runtimeReceipt = (string) data_get($execution, 'runtime_receipt_id', 'missing');
        $runtimeHash = (string) data_get($execution, 'runtime_contract_hash', 'missing');
        $promptContract = $this->stringList(data_get($execution, 'provider_prompt_contract', []));
        $responseShape = $this->stringList(data_get($execution, 'response_shape', []));
        $auditChecks = $this->stringList(data_get($execution, 'audit_checks', []));
        $qualityRubric = $this->stringList(data_get($execution, 'quality_rubric', []));
        $completionChecks = $this->stringList(data_get($execution, 'completion_checks', []));
        $failureModes = $this->stringList(data_get($execution, 'failure_modes', []));
        $delegation = data_get($execution, 'delegation');
        $delegationLines = is_array($delegation) && $delegation !== []
            ? $this->keyValueLines($delegation)
            : '- status: not_delegated';

        return <<<TXT
# Atlas AI Specialist Flow Handler

Flow: {$flowId}
Handler: {$handlerId}
Status: {$status}
Runtime receipt: {$runtimeReceipt}
Runtime contract hash: {$runtimeHash}

Contrato do handler:
{$promptContract}

Formato esperado (chaves semanticas internas — NUNCA escreva esses identificadores literais no corpo da resposta):
{$responseShape}

Auditoria obrigatoria:
{$auditChecks}

Rubrica de qualidade:
{$qualityRubric}

Checks de conclusao:
{$completionChecks}

Modos de falha proibidos:
{$failureModes}

Delegation:
{$delegationLines}

Regras:
- Siga este handler como contrato operacional do fluxo escolhido pelo Atlas AI Router.
- Se o status for delegated, nao execute o trabalho neste fluxo; explique o handoff e o proximo passo.
- Nao oculte ausencia de evidencia exigida pelo handler.
- As chaves do formato esperado sao alvos semanticos para o conteudo, nao titulos literais. NUNCA escreva "source_refs", "uncertainty", "SOURCE_REFS", "UNCERTAINTY", "claims_table", "open_questions", "findings", "assumptions" ou qualquer identificador em snake_case/UPPER_CASE como cabecalho da resposta. Use titulos editoriais curtos em portugues quando precisar separar secoes (ex: "Resposta", "Fontes", "Incerteza", "Achados"). Para respostas curtas, prefira prosa continua sem cabecalhos.
- Quando o conteudo de uma secao for puramente uma lista de identificadores tecnicos/auditoria (refs, hashes, receipts, traces), entregue como nota de rodape em portugues ou omita do corpo principal — o Atlas expoe esses dados separadamente no painel de contexto.
- Separe secoes principais (≥2) com uma linha "---" em branco entre elas para ativar o divisor editorial Atlas.
TXT;
    }

    private function attachmentInstructions(array $options, string $input): string
    {
        $images = data_get($options, 'payload.attachments.images', []);
        $files = data_get($options, 'payload.attachments.files', []);
        $images = is_array($images) ? $images : [];
        $files = is_array($files) ? $files : [];

        if ($images === [] && $files === []) {
            return '';
        }

        $lines = ['# Anexos enviados'];

        if ($images !== []) {
            $lines[] = '';
            $lines[] = '## Imagens';
            $lines[] = 'Ha imagem(ns) reais anexadas a esta mensagem pelo Atlas. Analise visualmente o conteudo anexado; nao trate como apenas caminho de arquivo.';

            foreach (array_slice($images, 0, 8) as $index => $image) {
                if (! is_array($image)) {
                    continue;
                }

                $number = $index + 1;
                $mime = is_scalar($image['mime_type'] ?? null) ? (string) $image['mime_type'] : 'image';
                $bytes = is_scalar($image['bytes'] ?? null) ? (string) $image['bytes'] : 'desconhecido';
                $source = is_scalar($image['source'] ?? null) ? (string) $image['source'] : 'upload';
                $lines[] = "- imagem {$number}: {$mime}, {$bytes} bytes, origem {$source}.";
            }
        }

        if ($files !== []) {
            $lines[] = '';
            $lines[] = '## Arquivos';
            $lines[] = 'Use o conteudo textual extraido abaixo como contexto do operador. Se um arquivo nao tiver texto extraido, declare essa lacuna em vez de inventar conteudo.';

            foreach (array_slice($files, 0, 4) as $index => $file) {
                if (! is_array($file)) {
                    continue;
                }

                $number = $index + 1;
                $name = is_scalar($file['original_name'] ?? null) ? (string) $file['original_name'] : "arquivo-{$number}";
                $mime = is_scalar($file['mime_type'] ?? null) ? (string) $file['mime_type'] : 'application/octet-stream';
                $bytes = is_scalar($file['bytes'] ?? null) ? (string) $file['bytes'] : 'desconhecido';
                $excerpt = is_string($file['text_excerpt'] ?? null) ? trim((string) $file['text_excerpt']) : '';
                $truncated = (bool) ($file['text_truncated'] ?? false);
                $pdfPages = is_array($file['pdf_pages'] ?? null) ? $file['pdf_pages'] : [];
                $pdfOcrPages = is_array($file['pdf_ocr_pages'] ?? null) ? $file['pdf_ocr_pages'] : [];
                $lowerName = strtolower($name);
                $isPdf = str_contains(strtolower($mime), 'pdf') || str_ends_with($lowerName, '.pdf');
                $isOffice = str_ends_with($lowerName, '.docx') || str_ends_with($lowerName, '.xlsx') || str_ends_with($lowerName, '.pptx');

                $lines[] = '';
                $lines[] = "<attached_file index=\"{$number}\" name=\"".htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'" mime="'.htmlspecialchars($mime, ENT_QUOTES, 'UTF-8')."\" bytes=\"{$bytes}\">";
                if ($isPdf && $pdfPages !== []) {
                    $pageCount = is_scalar($file['pdf_page_count'] ?? null) ? (string) $file['pdf_page_count'] : 'desconhecido';
                    $processingStatus = is_scalar($file['pdf_processing_status'] ?? null) ? (string) $file['pdf_processing_status'] : 'desconhecido';
                    $renderStatus = is_scalar($file['pdf_render_status'] ?? null) ? (string) $file['pdf_render_status'] : 'desconhecido';
                    $ocrStatus = is_scalar($file['pdf_ocr_status'] ?? null) ? (string) $file['pdf_ocr_status'] : 'desconhecido';
                    $visualStatus = is_scalar($file['pdf_visual_understanding_status'] ?? null) ? (string) $file['pdf_visual_understanding_status'] : 'desconhecido';
                    $visualStrategy = is_scalar($file['pdf_visual_page_strategy'] ?? null) ? (string) $file['pdf_visual_page_strategy'] : 'desconhecido';
                    $visualSelectedPages = is_array($file['pdf_visual_selected_pages'] ?? null) ? $file['pdf_visual_selected_pages'] : [];
                    $lines[] = "[pdf_metadata pages=\"{$pageCount}\" processing=\"{$processingStatus}\" render=\"{$renderStatus}\" ocr=\"{$ocrStatus}\" visual=\"{$visualStatus}\"]";
                    $lines[] = 'Use as paginas abaixo com citacoes tipo "p. 3". Quando houver imagem de pagina anexada ao provider, use a visao da pagina para layout, graficos, assinaturas, tabelas e prints; nao dependa apenas do texto.';
                    $lines[] = 'Ao responder com base neste PDF, cite paginas relevantes. Se a resposta depender de pagina omitida, declare a lacuna antes de concluir.';
                    $map = $this->pdfDocumentMap($pdfPages, $visualSelectedPages, $visualStrategy);
                    if ($map !== '') {
                        $lines[] = $map;
                    }

                    $selectedPdfPages = $this->selectPdfPagesForPrompt($pdfPages, $input, 36);
                    foreach ($selectedPdfPages as $page) {
                        if (! is_array($page)) {
                            continue;
                        }

                        $pageNumber = is_scalar($page['page'] ?? null) ? (string) $page['page'] : '?';
                        $pageExcerpt = is_string($page['text_excerpt'] ?? null) ? trim($page['text_excerpt']) : '';
                        $classification = is_scalar($page['classification'] ?? null) ? (string) $page['classification'] : 'unknown';
                        $caption = is_string($page['visual_caption'] ?? null) ? trim($page['visual_caption']) : '';
                        $tableExcerpt = is_string($page['table_excerpt'] ?? null) ? trim($page['table_excerpt']) : '';
                        $tableMarkdown = is_string($page['table_markdown'] ?? null) ? trim($page['table_markdown']) : '';
                        $tableConfidence = is_scalar($page['table_confidence'] ?? null) ? (string) $page['table_confidence'] : 'unknown';
                        $imageCount = is_scalar($page['image_count'] ?? null) ? (string) $page['image_count'] : '0';
                        $tableCount = is_scalar($page['table_count'] ?? null) ? (string) $page['table_count'] : '0';
                        $lines[] = "<pdf_page page=\"{$pageNumber}\" classification=\"".htmlspecialchars($classification, ENT_QUOTES, 'UTF-8').'">';
                        if ($caption !== '') {
                            $lines[] = '<visual_caption>'.htmlspecialchars($caption, ENT_QUOTES, 'UTF-8').'</visual_caption>';
                        }
                        $lines[] = "<page_structure images=\"{$imageCount}\" table_like_rows=\"{$tableCount}\" />";
                        if ($tableExcerpt !== '') {
                            $lines[] = "<detected_table_excerpt>\n{$tableExcerpt}\n</detected_table_excerpt>";
                        }
                        if ($tableMarkdown !== '') {
                            $lines[] = '<detected_table_markdown confidence="'.htmlspecialchars($tableConfidence, ENT_QUOTES, 'UTF-8')."\">\n{$tableMarkdown}\n</detected_table_markdown>";
                        }
                        $lines[] = $pageExcerpt !== '' ? $pageExcerpt : '[sem texto nativo extraido nesta pagina]';
                        $lines[] = '</pdf_page>';
                    }

                    foreach (array_slice($pdfOcrPages, 0, 24) as $page) {
                        if (! is_array($page)) {
                            continue;
                        }

                        $pageNumber = is_scalar($page['page'] ?? null) ? (string) $page['page'] : '?';
                        $pageExcerpt = is_string($page['text_excerpt'] ?? null) ? trim($page['text_excerpt']) : '';
                        if ($pageExcerpt === '') {
                            continue;
                        }

                        $lines[] = "<pdf_ocr_page page=\"{$pageNumber}\">";
                        $lines[] = $pageExcerpt;
                        $lines[] = '</pdf_ocr_page>';
                    }

                    if ((bool) ($file['pdf_pages_truncated'] ?? false) || count($pdfPages) > count($selectedPdfPages)) {
                        $selectedNumbers = collect($selectedPdfPages)
                            ->map(fn (mixed $page): mixed => is_array($page) ? ($page['page'] ?? null) : null)
                            ->filter()
                            ->implode(', ');
                        $lines[] = '[prompt compacto com paginas selecionadas: '.$selectedNumbers.'. Use o mapa do PDF para decidir se ha lacuna de pagina.]';
                    }
                } elseif ($isOffice) {
                    $renderStatus = is_scalar($file['office_render_status'] ?? null) ? (string) $file['office_render_status'] : 'desconhecido';
                    $pageCount = is_scalar($file['office_rendered_page_count'] ?? null) ? (string) $file['office_rendered_page_count'] : '0';
                    $processingStatus = is_scalar($file['office_processing_status'] ?? null) ? (string) $file['office_processing_status'] : 'desconhecido';
                    $lines[] = "[office_metadata processing=\"{$processingStatus}\" render=\"{$renderStatus}\" visual_pages=\"{$pageCount}\"]";
                    $lines[] = 'Se houver paginas/slides renderizados como imagem anexada ao provider, use tambem a visao do documento para layout, slides, abas de planilha, graficos e tabelas.';
                    if ($excerpt !== '') {
                        $lines[] = $excerpt;
                        if ($truncated) {
                            $lines[] = '[conteudo truncado pelo Atlas]';
                        }
                    } else {
                        $lines[] = '[sem texto extraido automaticamente deste Office]';
                    }
                } elseif ($excerpt !== '') {
                    $lines[] = $excerpt;
                    if ($truncated) {
                        $lines[] = '[conteudo truncado pelo Atlas]';
                    }
                } else {
                    $lines[] = '[sem texto extraido automaticamente deste arquivo]';
                }
                $lines[] = '</attached_file>';
            }
        }

        return implode("\n", $lines);
    }

    private function youtubeKnowledgeSection(array $options): string
    {
        $videos = data_get($options, 'payload.youtube_ingestion.videos', []);
        if (! is_array($videos) || $videos === []) {
            return '';
        }

        // Canonical 3-status capability · honest signaling of translation gap.
        // `translation_required=true && translation_status != translated_ready`
        // means: we have the original-language transcript but NO translation
        // pipeline ran. The model reads foreign text and responds in pt-BR by
        // inference — that is not the same as translation. Tell the operator.
        $anyTranslationGap = collect($videos)->contains(function (mixed $video): bool {
            if (! is_array($video)) {
                return false;
            }
            $required = (bool) ($video['translation_required'] ?? false);
            $status = (string) ($video['translation_status'] ?? '');

            return $required && $status !== 'translated_ready';
        });

        $lines = [
            '# YouTube ingerido',
            'O operador colou link(s) do YouTube. Use a transcricao com timestamps como fonte primaria do video. Cite timestamps no formato [mm:ss] ou [h:mm:ss] quando usar pontos especificos.',
            'Se a transcricao/caption nao estiver disponivel, declare a lacuna com precisao e nao finja ter visto/ouvido o video inteiro.',
            'REGRA CRITICA: quando o pedido for sobre o conteudo do video e a transcricao estiver indisponivel, e proibido substituir o video por blog post, GitHub README, site oficial, artigos, conhecimento geral ou pesquisa externa, a menos que o operador peca explicitamente fontes externas. Entregue apenas metadados seguros e a lacuna.',
            'Se o status for processing, diga de forma curta que o Atlas esta transcrevendo o audio em background e que o operador pode reenviar o mesmo link em instantes para receber a analise completa.',
            'Se um <youtube_video> tiver status diferente de ready, nao diga "tenho o suficiente para analise" e nao produza analise do conteudo falado.',
            'Idioma de saida: responda em portugues brasileiro quando o operador escrever em portugues. Se o titulo oficial do video estiver em outro idioma, nao use esse titulo cru como heading principal; crie um titulo curto em portugues para a resposta e cite o original separadamente como "Titulo original: ...". Preserve nomes proprios, marcas, produtos e termos tecnicos quando a traducao prejudicar precisao.',
            'Quando o pedido for amplo ("me diga tudo", "analise", "disseca", "me fala sobre", "resuma completo"), entregue uma analise completa e estruturada, nao apenas um resumo curto. Inclua, no minimo: qualidade da fonte/transcricao, resumo executivo, mapa por timestamps, pontos importantes, exemplos demonstrados, implicacoes para o operador/Atlas, candidatos para memoria e proximas acoes concretas. So seja ultra-curto se o operador pedir explicitamente resposta curta.',
        ];

        if ($anyTranslationGap) {
            $lines[] = 'TRADUCAO HONESTA: o transcript esta em idioma estrangeiro e o Atlas NAO possui pipeline de traducao explicita ainda — voce esta lendo o transcript original e respondendo em pt-BR por inferencia. Deixe claro na resposta que a base e o transcript ORIGINAL no idioma de origem (cite o idioma quando souber), nao uma traducao certificada. Nao escreva "traduzi o video para voce" nem "aqui esta a traducao": isso seria mentira sobre o que o Atlas fez.';
        }

        foreach (array_slice($videos, 0, 2) as $index => $video) {
            if (! is_array($video)) {
                continue;
            }

            $number = $index + 1;
            $status = htmlspecialchars((string) ($video['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8');
            $metadata = is_array($video['metadata'] ?? null) ? $video['metadata'] : [];
            $caption = is_array($video['caption'] ?? null) ? $video['caption'] : [];
            $title = htmlspecialchars((string) ($metadata['title'] ?? 'sem titulo'), ENT_QUOTES, 'UTF-8');
            $channel = htmlspecialchars((string) ($metadata['channel'] ?? 'canal desconhecido'), ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars((string) ($metadata['webpage_url'] ?? $video['url'] ?? ''), ENT_QUOTES, 'UTF-8');
            $duration = is_scalar($metadata['duration_seconds'] ?? null) ? (string) $metadata['duration_seconds'] : 'desconhecida';
            $language = htmlspecialchars((string) ($caption['language'] ?? $metadata['language'] ?? 'desconhecida'), ENT_QUOTES, 'UTF-8');
            $captionKind = htmlspecialchars((string) ($caption['kind'] ?? 'desconhecida'), ENT_QUOTES, 'UTF-8');
            $timestampSource = htmlspecialchars((string) ($caption['timestamp_source'] ?? 'native'), ENT_QUOTES, 'UTF-8');

            $lines[] = '';
            $lines[] = "<youtube_video index=\"{$number}\" status=\"{$status}\" title=\"{$title}\" channel=\"{$channel}\" duration_seconds=\"{$duration}\" language=\"{$language}\" caption_kind=\"{$captionKind}\" timestamp_source=\"{$timestampSource}\" url=\"{$url}\">";
            if ($timestampSource === 'estimated') {
                $lines[] = '<timestamp_note>Transcricao veio de audio/Whisper; timestamps sao estimativas proporcionais, nao marcas nativas do YouTube.</timestamp_note>';
            }
            $chapters = is_array($metadata['chapters'] ?? null) ? $metadata['chapters'] : [];
            if ($chapters !== []) {
                $lines[] = '<official_chapters>';
                foreach (array_slice($chapters, 0, 30) as $chapter) {
                    if (! is_array($chapter)) {
                        continue;
                    }
                    $chapterStart = htmlspecialchars((string) ($chapter['start_label'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $chapterTitle = htmlspecialchars((string) ($chapter['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                    if ($chapterTitle !== '') {
                        $lines[] = "- [{$chapterStart}] {$chapterTitle}";
                    }
                }
                $lines[] = '</official_chapters>';
            }
            $description = is_string($metadata['description_excerpt'] ?? null) ? trim((string) $metadata['description_excerpt']) : '';
            if ($description !== '') {
                $lines[] = '<official_description_excerpt>'.htmlspecialchars($description, ENT_QUOTES, 'UTF-8').'</official_description_excerpt>';
            }

            if (($video['status'] ?? null) !== 'ready') {
                $reason = htmlspecialchars((string) ($video['reason'] ?? 'transcricao indisponivel'), ENT_QUOTES, 'UTF-8');
                $lines[] = "<ingestion_gap>{$reason}</ingestion_gap>";
                $processing = is_array($video['processing'] ?? null) ? $video['processing'] : [];
                if ($processing !== []) {
                    $stage = htmlspecialchars((string) ($processing['stage'] ?? 'processing'), ENT_QUOTES, 'UTF-8');
                    $eta = is_scalar($processing['estimated_remaining_seconds'] ?? null) ? (string) $processing['estimated_remaining_seconds'] : '';
                    $progress = is_scalar($processing['progress'] ?? null) ? (string) $processing['progress'] : '';
                    $lines[] = "<processing_status stage=\"{$stage}\" progress=\"{$progress}\" eta_seconds=\"{$eta}\">transcricao em background</processing_status>";
                }
                $lines[] = '</youtube_video>';

                continue;
            }

            $chunks = is_array($video['chunks'] ?? null) ? $video['chunks'] : [];
            foreach (array_slice($chunks, 0, 80) as $chunk) {
                if (! is_array($chunk)) {
                    continue;
                }

                $chunkIndex = is_scalar($chunk['index'] ?? null) ? (string) $chunk['index'] : '?';
                $start = htmlspecialchars((string) ($chunk['start_label'] ?? ''), ENT_QUOTES, 'UTF-8');
                $end = htmlspecialchars((string) ($chunk['end_label'] ?? ''), ENT_QUOTES, 'UTF-8');
                $text = trim((string) ($chunk['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $lines[] = "<transcript_chunk index=\"{$chunkIndex}\" start=\"{$start}\" end=\"{$end}\">";
                $lines[] = $text;
                $lines[] = '</transcript_chunk>';
            }

            $lines[] = '</youtube_video>';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int,mixed>  $pages
     * @return array<int,array<string,mixed>>
     */
    private function selectPdfPagesForPrompt(array $pages, string $input, int $limit): array
    {
        $pages = collect($pages)
            ->filter(fn (mixed $page): bool => is_array($page))
            ->values();
        if ($pages->count() <= $limit) {
            return $pages->all();
        }

        $terms = $this->keywords($input);
        if ($terms === []) {
            return $pages->take($limit)->all();
        }

        $head = $pages->take(2)->all();
        $headNumbers = collect($head)
            ->map(fn (array $page): int => (int) ($page['page'] ?? 0))
            ->filter()
            ->all();

        $ranked = $pages
            ->reject(fn (array $page): bool => in_array((int) ($page['page'] ?? 0), $headNumbers, true))
            ->map(function (array $page) use ($terms): array {
                $text = Str::of((string) ($page['text_excerpt'] ?? ''))->lower()->ascii()->value();
                $score = 0;
                foreach ($terms as $term) {
                    $score += substr_count($text, $term);
                }

                return [
                    'page' => $page,
                    'score' => $score,
                    'number' => (int) ($page['page'] ?? 0),
                ];
            })
            ->filter(fn (array $item): bool => $item['score'] > 0)
            ->sortByDesc('score')
            ->take(max(0, $limit - count($head)))
            ->pluck('page')
            ->all();

        $selected = [...$head, ...$ranked];
        if (count($selected) < $limit) {
            $selectedNumbers = collect($selected)
                ->map(fn (array $page): int => (int) ($page['page'] ?? 0))
                ->filter()
                ->all();
            $fill = $pages
                ->reject(fn (array $page): bool => in_array((int) ($page['page'] ?? 0), $selectedNumbers, true))
                ->take($limit - count($selected))
                ->all();
            $selected = [...$selected, ...$fill];
        }

        return collect($selected)
            ->sortBy(fn (array $page): int => (int) ($page['page'] ?? 0))
            ->values()
            ->all();
    }

    private function pdfDocumentMap(array $pages, array $visualSelectedPages, string $visualStrategy): string
    {
        $pages = collect($pages)
            ->filter(fn (mixed $page): bool => is_array($page))
            ->values();
        if ($pages->isEmpty()) {
            return '';
        }

        $visualPages = collect($visualSelectedPages)
            ->map(fn (mixed $page): int => (int) $page)
            ->filter(fn (int $page): bool => $page > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $scanned = $pages
            ->filter(fn (array $page): bool => in_array((string) ($page['classification'] ?? ''), ['visual_or_scanned', 'sparse'], true))
            ->pluck('page')
            ->filter()
            ->take(24)
            ->implode(', ');
        $tables = $pages
            ->filter(fn (array $page): bool => (int) ($page['table_count'] ?? 0) > 0)
            ->map(fn (array $page): string => 'p. '.(string) ($page['page'] ?? '?').' ('.(string) ($page['table_count'] ?? 0).')')
            ->take(24)
            ->implode(', ');
        $images = $pages
            ->filter(fn (array $page): bool => (int) ($page['image_count'] ?? 0) > 0)
            ->map(fn (array $page): string => 'p. '.(string) ($page['page'] ?? '?').' ('.(string) ($page['image_count'] ?? 0).')')
            ->take(24)
            ->implode(', ');
        $headings = $pages
            ->flatMap(function (array $page): array {
                $structure = is_array($page['structure'] ?? null) ? $page['structure'] : [];
                $candidates = is_array($structure['heading_candidates'] ?? null) ? $structure['heading_candidates'] : [];
                $pageNumber = (string) ($page['page'] ?? '?');

                return collect($candidates)
                    ->filter(fn (mixed $heading): bool => is_scalar($heading) && trim((string) $heading) !== '')
                    ->take(2)
                    ->map(fn (mixed $heading): string => 'p. '.$pageNumber.': '.trim((string) $heading))
                    ->all();
            })
            ->take(16)
            ->implode(' | ');

        $lines = ['<pdf_document_map visual_strategy="'.htmlspecialchars($visualStrategy, ENT_QUOTES, 'UTF-8').'">'];
        if ($visualPages !== []) {
            $lines[] = '<visual_pages>'.implode(', ', $visualPages).'</visual_pages>';
        }
        if ($scanned !== '') {
            $lines[] = '<scanned_or_sparse_pages>'.$scanned.'</scanned_or_sparse_pages>';
        }
        if ($tables !== '') {
            $lines[] = '<table_like_pages>'.$tables.'</table_like_pages>';
        }
        if ($images !== '') {
            $lines[] = '<image_or_chart_pages>'.$images.'</image_or_chart_pages>';
        }
        if ($headings !== '') {
            $lines[] = '<heading_candidates>'.htmlspecialchars($headings, ENT_QUOTES, 'UTF-8').'</heading_candidates>';
        }
        $lines[] = '</pdf_document_map>';

        return implode("\n", $lines);
    }

    private function sessionSearchSection(string $input, array $options): string
    {
        if (! DatabaseTableAvailability::all(['ai_messages', 'ai_threads'])) {
            return '';
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $config = data_get($payload, 'session_search');
        if ($config === false) {
            return '';
        }

        $force = (bool) data_get($config, 'force', false);
        if (! $force && ! $this->shouldAutoSessionSearch($input)) {
            return '';
        }

        $workspaceValue = data_get(
            $config,
            'workspace',
            data_get($payload, 'workspace', data_get($options, 'workspace', config('atlas.ai.workdir', dirname(base_path()))))
        );
        $workspace = is_scalar($workspaceValue) ? (string) $workspaceValue : (string) config('atlas.ai.workdir', dirname(base_path()));
        $queryValue = data_get($config, 'query');
        $query = is_string($queryValue) && trim($queryValue) !== ''
            ? trim($queryValue)
            : $this->sessionSearchQuery($input);

        if ($query === '') {
            return '';
        }

        $topN = ($this->retrievalRankInput ?? app(RetrievalRankInput::class))
            ->promptSessionTopN(data_get($config, 'top_n'));

        try {
            $results = $this->sessionSearch->search($workspace, $query, $topN, summarize: true);
        } catch (\Throwable) {
            return '';
        }

        $lines = [
            '# Contexto recuperado de sessoes anteriores (session.search)',
            '',
            'Consulta: '.$query,
            'Use este bloco apenas como contexto recuperado de sessao. Nao trate como memoria permanente validada.',
        ];

        if ($results === []) {
            $lines[] = 'Nenhuma sessao anterior relevante foi encontrada para esta consulta.';

            return implode("\n", $lines);
        }

        foreach ($results as $result) {
            $lastMessageAt = $result->lastMessageAt?->format(\DateTimeInterface::ATOM) ?: 'sem-data';
            $threadId = htmlspecialchars($result->threadId, ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars($result->threadTitle, ENT_QUOTES, 'UTF-8');
            $source = htmlspecialchars($result->source, ENT_QUOTES, 'UTF-8');
            $excerpt = trim($result->excerpt);

            $lines[] = '';
            $lines[] = "<session_search_result thread_id=\"{$threadId}\" title=\"{$title}\" last_message_at=\"{$lastMessageAt}\" rank=\"{$result->rank}\" source=\"{$source}\">";
            $lines[] = $excerpt;
            $lines[] = '</session_search_result>';
        }

        return implode("\n", $lines);
    }

    private function attachmentSearchSection(string $input, array $options): string
    {
        $normalized = Str::of($input)->lower()->ascii()->squish()->value();
        $shouldSearch = Str::contains($normalized, [
            'arquivo',
            'anexo',
            'pdf',
            'planilha',
            'imagem',
            'foto',
            'documento',
            'slide',
            'ppt',
            'excel',
            'xlsx',
            'docx',
        ]);
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $force = (bool) data_get($payload, 'attachment_search.force', false);
        if (! $force && ! $shouldSearch) {
            return '';
        }

        $threadId = data_get($payload, 'thread_id', data_get($options, 'thread_id'));
        $threadId = is_string($threadId) && trim($threadId) !== '' ? $threadId : null;

        try {
            if (! $this->attachmentIndex) {
                return '';
            }

            $results = $this->attachmentIndex->search($input, $threadId, 6);
        } catch (\Throwable) {
            return '';
        }

        if ($results->isEmpty()) {
            return '';
        }

        $lines = [
            '# Anexos historicos recuperados',
            '',
            'Use estes trechos para localizar arquivos enviados anteriormente. Eles sao indice de anexos, nao substituem o arquivo original.',
        ];

        foreach ($results as $entry) {
            $name = htmlspecialchars((string) ($entry->source_name ?? 'anexo'), ENT_QUOTES, 'UTF-8');
            $unit = htmlspecialchars((string) $entry->unit_type, ENT_QUOTES, 'UTF-8');
            $number = $entry->unit_number ? ' number="'.$entry->unit_number.'"' : '';
            $traceId = htmlspecialchars((string) $entry->trace_id, ENT_QUOTES, 'UTF-8');
            $excerpt = trim((string) $entry->excerpt);
            $lines[] = '';
            $lines[] = "<attachment_search_result trace_id=\"{$traceId}\" name=\"{$name}\" unit=\"{$unit}\"{$number}>";
            $lines[] = $excerpt;
            $lines[] = '</attachment_search_result>';
        }

        return implode("\n", $lines);
    }

    private function shouldAutoSessionSearch(string $input): bool
    {
        $normalized = Str::of($input)->lower()->ascii()->squish()->value();
        if ($normalized === '') {
            return false;
        }

        foreach ([
            'como falamos',
            'como falei antes',
            'falamos antes',
            'falamos anteriormente',
            'conversa anterior',
            'sessao anterior',
            'sessao passada',
            'na outra conversa',
            'voltando ao que',
            'retomando o que',
            'continuando de onde',
            'lembra do que',
            'lembra quando',
            'voce disse antes',
            'o que decidimos',
            'decisao anterior',
            'historico da conversa',
            'continuidade entre',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sessionSearchQuery(string $input): string
    {
        return Str::of($input)
            ->replaceMatches('/[?!.]+/', ' ')
            ->squish()
            ->limit(240, '')
            ->value();
    }

    private function permissionInstructions(array $options): string
    {
        $permissions = data_get($options, 'payload.tool_permissions');
        if (! is_array($permissions) || $permissions === []) {
            return '';
        }

        $mode = (string) data_get($permissions, 'mode', 'read');
        $workspaceValue = data_get($permissions, 'workspace', data_get($options, 'payload.workspace'));
        $workspace = is_scalar($workspaceValue) ? (string) $workspaceValue : 'nao definido';
        $capabilities = data_get($permissions, 'capabilities', []);
        $allowedRoots = data_get($permissions, 'allowed_roots', data_get($permissions, 'permission_decision.metadata.allowed_roots', []));
        $sandboxValue = data_get($permissions, 'codex_sandbox');
        $sandbox = is_scalar($sandboxValue) ? (string) $sandboxValue : 'nao definido';

        $capabilityText = is_array($capabilities) && $capabilities !== []
            ? implode(', ', array_map(fn (mixed $capability): string => (string) $capability, $capabilities))
            : 'read_files, inspect_git';
        $rootsText = is_array($allowedRoots) && $allowedRoots !== []
            ? implode(', ', array_map(fn (mixed $root): string => (string) $root, $allowedRoots))
            : $workspace;
        $scopeRule = $mode === 'danger'
            ? '- Em modo danger, voce pode ler, escrever e executar comandos dentro das raizes autorizadas, nao apenas no workspace atual. Nao use sudo nem acesse fora dessas raizes sem pedido explicito.'
            : '- Em modo read/write, mantenha leitura, escrita e comandos dentro do workspace autorizado.';

        return <<<TXT
# Runtime de ferramentas Atlas

Modo autorizado: {$mode}
Workspace autorizado: {$workspace}
Raizes autorizadas: {$rootsText}
Sandbox Codex previsto: {$sandbox}
Capacidades: {$capabilityText}

Regras:
- Execute leitura, escrita ou comandos somente dentro das capacidades e raizes acima.
{$scopeRule}
- Não tente contornar sandbox, permissões, workspace ou políticas do Atlas.
- Se precisar de uma capacidade maior, pare e explique a solicitação de permissão em termos operacionais.
TXT;
    }

    private function outputContract(array $options): string
    {
        $mode = data_get($options, 'payload.atlas_workflow_mode');

        $base = <<<'TXT'
# Contrato de saida Atlas

- O produto final é o Atlas; Claude, Codex e outros modelos são motores internos.
- Use memória e contexto de forma silenciosa. Não anuncie que "contexto foi mapeado".
- Responda com clareza executiva: decisão, ação, risco e validação quando relevante.
- Evite código na resposta final, salvo pedido explícito do operador.
- Em tarefas técnicas, cite arquivos alterados e comandos de verificação.
- Para perguntas sobre arquivos, pastas ou contagens no filesystem, use comando deterministico quando houver acesso a ferramentas e diga se ocultos foram incluídos.
- Se a resposta anterior do operador for curta ("C", "ambos", "continua"), use a conversa recente antes de pedir referência.
TXT;

        if ($mode === 'dev') {
            return $base."\n- Para desenvolvimento, prefira resumo de mudanças e validação a explicações longas.";
        }

        return $base;
    }

    private function voiceResponseInstructions(array $options): string
    {
        $contract = data_get($options, 'payload.voice_response_contract');
        if (! is_array($contract) || ! in_array((string) data_get($contract, 'mode'), ['spoken_concise', 'spoken_result'], true)) {
            return '';
        }

        $targetChars = max(160, min(800, (int) data_get($contract, 'target_chars', 360)));
        $hardMaxChars = max($targetChars, min(1200, (int) data_get($contract, 'hard_max_chars', 520)));
        $maxSentences = max(1, min(5, (int) data_get($contract, 'max_sentences', 3)));

        return <<<TXT
# Contrato de resposta falada Atlas Voice

Esta resposta sera falada em voz alta. Priorize tempo ate a primeira fala e clareza oral.

Regras:
- Responda em portugues brasileiro natural, direto e sem markdown.
- Nao reduza o escopo do pedido por ser voz: execute a intencao completa antes de formular a resposta falada.
- Use no maximo {$maxSentences} frases curtas quando a pergunta permitir.
- Mira de tamanho: ate {$targetChars} caracteres; limite duro: {$hardMaxChars} caracteres.
- Nao use listas longas, cabecalhos, tabelas, JSON, codigo ou referencias internas.
- Se o trabalho exigir analise longa, faca o trabalho completo, responda em voz com conclusao eficiente e deixe detalhes essenciais no texto da conversa.
- Se faltar contexto, faca uma pergunta objetiva em uma frase.
TXT;
    }

    private function shouldAttachOutputGovernor(array $options, string $agent): bool
    {
        $mode = data_get($options, 'payload.atlas_workflow_mode');

        if ($mode === 'semantic_clarification' || $agent === 'aclarador') {
            return false;
        }

        return true;
    }
}
