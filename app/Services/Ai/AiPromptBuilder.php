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
            $this->attachmentInstructions($options, $input),
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

        $terms = AiPromptTextSupport::keywords($input);
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
