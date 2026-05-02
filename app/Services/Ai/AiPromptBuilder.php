<?php

namespace App\Services\Ai;

use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Attachments\AiAttachmentIndexService;
use App\Services\Ai\Skills\SkillDiscoveryService;
use App\Services\Ai\Skills\SkillManifest;
use App\Services\Ai\Search\SessionSearchService;
use App\Services\Ai\ValueObjects\AiExecutionPlan;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Illuminate\Support\Facades\Schema;
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
        $contextPack = $this->contexts->build($input, $taskRequest, $options);
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
        $contextRefs = $contextPack->contextRefs();
        $activatedBundles = $this->activatedBundles($activatedBundleNames);
        $activeAgentBundle = $this->skillBundles->find($agent);
        $catalog = $this->skillBundles->catalog();
        $sessionSearchSection = $this->sessionSearchSection($input, $options);
        $attachmentSearchSection = $this->attachmentSearchSection($input, $options);
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
            $contextPack->toPromptSection(),
            $executionPlan->toPromptSection(),
            $this->atlasModeInstructions($options),
            $this->permissionInstructions($options),
            $this->workflowInstructions($options),
            $this->attachmentInstructions($options, $input),
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
        );
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
        return collect((array) data_get($payload, 'activated_skills', []))
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (mixed $name): string => Str::of((string) $name)->lower()->trim()->value())
            ->unique()
            ->values()
            ->all();
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
     * @return \Illuminate\Support\Collection<int,SkillManifest>
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
     * @param  \Illuminate\Support\Collection<int,SkillManifest>  $manifests
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
     * @param  \Illuminate\Support\Collection<int,SkillManifest>  $manifests
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
                $lines[] = "<attached_file index=\"{$number}\" name=\"".htmlspecialchars($name, ENT_QUOTES, 'UTF-8')."\" mime=\"".htmlspecialchars($mime, ENT_QUOTES, 'UTF-8')."\" bytes=\"{$bytes}\">";
                if ($isPdf && $pdfPages !== []) {
                    $pageCount = is_scalar($file['pdf_page_count'] ?? null) ? (string) $file['pdf_page_count'] : 'desconhecido';
                    $processingStatus = is_scalar($file['pdf_processing_status'] ?? null) ? (string) $file['pdf_processing_status'] : 'desconhecido';
                    $renderStatus = is_scalar($file['pdf_render_status'] ?? null) ? (string) $file['pdf_render_status'] : 'desconhecido';
                    $ocrStatus = is_scalar($file['pdf_ocr_status'] ?? null) ? (string) $file['pdf_ocr_status'] : 'desconhecido';
                    $visualStatus = is_scalar($file['pdf_visual_understanding_status'] ?? null) ? (string) $file['pdf_visual_understanding_status'] : 'desconhecido';
                    $lines[] = "[pdf_metadata pages=\"{$pageCount}\" processing=\"{$processingStatus}\" render=\"{$renderStatus}\" ocr=\"{$ocrStatus}\" visual=\"{$visualStatus}\"]";
                    $lines[] = 'Use as paginas abaixo com citacoes tipo "p. 3". Quando houver imagem de pagina anexada ao provider, use a visao da pagina para layout, graficos, assinaturas, tabelas e prints; nao dependa apenas do texto.';

                    $selectedPdfPages = $this->selectPdfPagesForPrompt($pdfPages, $input, 24);
                    foreach ($selectedPdfPages as $page) {
                        if (! is_array($page)) {
                            continue;
                        }

                        $pageNumber = is_scalar($page['page'] ?? null) ? (string) $page['page'] : '?';
                        $pageExcerpt = is_string($page['text_excerpt'] ?? null) ? trim($page['text_excerpt']) : '';
                        $classification = is_scalar($page['classification'] ?? null) ? (string) $page['classification'] : 'unknown';
                        $caption = is_string($page['visual_caption'] ?? null) ? trim($page['visual_caption']) : '';
                        $tableExcerpt = is_string($page['table_excerpt'] ?? null) ? trim($page['table_excerpt']) : '';
                        $imageCount = is_scalar($page['image_count'] ?? null) ? (string) $page['image_count'] : '0';
                        $tableCount = is_scalar($page['table_count'] ?? null) ? (string) $page['table_count'] : '0';
                        $lines[] = "<pdf_page page=\"{$pageNumber}\" classification=\"".htmlspecialchars($classification, ENT_QUOTES, 'UTF-8')."\">";
                        if ($caption !== '') {
                            $lines[] = '<visual_caption>'.htmlspecialchars($caption, ENT_QUOTES, 'UTF-8').'</visual_caption>';
                        }
                        $lines[] = "<page_structure images=\"{$imageCount}\" table_like_rows=\"{$tableCount}\" />";
                        if ($tableExcerpt !== '') {
                            $lines[] = "<detected_table_excerpt>\n{$tableExcerpt}\n</detected_table_excerpt>";
                        }
                        $lines[] = $pageExcerpt !== '' ? $pageExcerpt : '[sem texto nativo extraido nesta pagina]';
                        $lines[] = '</pdf_page>';
                    }

                    foreach (array_slice($pdfOcrPages, 0, 12) as $page) {
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
                        $lines[] = '[prompt compacto com paginas selecionadas: '.$selectedNumbers.'. Se a pergunta depender de pagina omitida, declare a lacuna.]';
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

    private function sessionSearchSection(string $input, array $options): string
    {
        if (! Schema::hasTable('ai_messages') || ! Schema::hasTable('ai_threads')) {
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

        $topN = max(1, min(5, (int) data_get($config, 'top_n', 3)));

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

    private function shouldAttachOutputGovernor(array $options, string $agent): bool
    {
        $mode = data_get($options, 'payload.atlas_workflow_mode');

        if ($mode === 'semantic_clarification' || $agent === 'aclarador') {
            return false;
        }

        return true;
    }
}
