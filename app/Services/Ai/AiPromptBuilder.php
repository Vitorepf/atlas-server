<?php

namespace App\Services\Ai;

use App\Services\Ai\Attachments\AiAttachmentIndexService;
use App\Services\Ai\Context\RetrievalRankInput;
use App\Services\Ai\Context\AiContextPackBuilder;
use App\Services\Ai\Router\AiIntentRouter;
use App\Services\Ai\Search\SessionSearchService;
use App\Services\Ai\Skills\AiSkillStore;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use App\Services\Ai\Skills\SkillManifest;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\ValueObjects\AiPromptExecutionPlan as AiExecutionPlan;
use App\Services\Ai\ValueObjects\AiPrompt;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use App\Services\Ai\Support\AiPromptAttachmentSupport;
use App\Services\Ai\Support\AiPromptInstructionSupport;
use App\Services\Ai\Support\AiPromptTextSupport;

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
        $youtubeKnowledgeSection = AiPromptAttachmentSupport::youtubeKnowledgeSection($options);
        $activeSkillSection = $activeAgentBundle
            ? "# Skill ativa: {$skill->title}\n\nA skill ativa esta carregada como bundle agentskills.io em <skill_content name=\"{$activeAgentBundle->name}\">. Use esse bloco como fonte procedural principal."
            : "# Skill ativa: {$skill->title}\n\n{$skill->body}";

        $prompt = implode("\n\n", array_filter([
            "# Identidade Atlas\n\n{$master->body}",
            $activeSkillSection,
            $outputGovernor && $outputGovernor->slug !== $skill->slug
                ? "# Skill auxiliar obrigatoria: {$outputGovernor->title}\n\n{$outputGovernor->body}"
                : null,
            AiPromptInstructionSupport::skillCatalogSection($catalog),
            $sessionSearchSection,
            $attachmentSearchSection,
            $youtubeKnowledgeSection,
            AiPromptInstructionSupport::persistentContextPromptSection($options),
            AiPromptInstructionSupport::awisRuntimeContextPromptSection($options),
            $this->contextPackPromptSection($contextPack, $openBrainInjection),
            $executionPlan->toPromptSection(),
            AiPromptInstructionSupport::atlasModeInstructions($options),
            $this->harnessInstructionSection(),
            AiPromptInstructionSupport::specialistFlowInstructions($options),
            $this->programmingAreaFailureMemory($options),
            AiPromptInstructionSupport::permissionInstructions($options),
            AiPromptInstructionSupport::workflowInstructions($options),
            AiPromptAttachmentSupport::attachmentInstructions($options, $input),
            AiPromptInstructionSupport::voiceResponseInstructions($options),
            AiPromptInstructionSupport::outputContract($options),
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
        $inputWords = AiPromptTextSupport::keywords($input);
        if ($inputWords === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($this->skillBundles->all() as $manifest) {
            if ($manifest->name === 'comunicador-claro') {
                continue;
            }

            $haystackWords = AiPromptTextSupport::keywords($manifest->name.' '.$manifest->description);
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

    /**
     * AP-819 Surface v2 — as seções de instrução AUTO-EVOLUÍDAS do harness
     * (espaço de busca finito + auditado; o autopilot só troca por variantes
     * declaradas, julgado pela suite congelada + recorrência crua). Fail-open:
     * qualquer erro aqui nunca derruba a montagem do prompt.
     */
    private function harnessInstructionSection(): ?string
    {
        try {
            return app(\App\Services\Ai\Learning\Harness\AtlasHarnessInstructionSurface::class)->promptSection();
        } catch (\Throwable) {
            return null;
        }
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

    /**
     * Memória de falha da ÁREA no prompt do chat de programação — o mesmo canal
     * known_failure_modes que o pipeline Dev e a esteira já recebem (M5/S2),
     * agora também no caminho interativo: o modelo agêntico entra sabendo o que
     * já falhou nos arquivos-alvo antes de tocar neles. Só em mode=programming
     * com arquivos-alvo identificados; fail-open (memória nunca quebra prompt).
     */
    private function programmingAreaFailureMemory(array $options): string
    {
        try {
            $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
            $mode = data_get($payload, 'atlas_mode') ?? data_get($payload, 'current_mode');
            if ($mode !== 'programming') {
                return '';
            }

            $files = array_values(array_filter(array_map('strval', array_merge(
                (array) data_get($payload, 'tool_permissions.allowed_files', []),
                (array) ($payload['expected_files'] ?? []),
            ))));
            $workspace = data_get($payload, 'tool_permissions.workspace') ?? data_get($payload, 'workspace');
            if ($files === [] || ! is_string($workspace) || trim($workspace) === '') {
                return '';
            }

            $modes = app(\App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsulePromptInjector::class)->injectFor(
                array_slice($files, 0, 12),
                \App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity::slug($workspace),
            );
            if ($modes === []) {
                return '';
            }

            return "# Memória da área (falhas conhecidas nestes arquivos)\n\n"
                .implode("\n", array_map(static fn (string $m): string => '- '.$m, array_slice($modes, 0, 6)))
                ."\nEvite repetir esses modos de falha; quando relevante, diga como o seu approach os evita.";
        } catch (\Throwable) {
            return '';
        }
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
