<?php

namespace App\Services\Ai;

use App\Services\Ai\Skills\SkillBundleStore;
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
            $contextPack->toPromptSection(),
            $executionPlan->toPromptSection(),
            $this->permissionInstructions($options),
            $this->workflowInstructions($options),
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
        $sandboxValue = data_get($permissions, 'codex_sandbox');
        $sandbox = is_scalar($sandboxValue) ? (string) $sandboxValue : 'nao definido';

        $capabilityText = is_array($capabilities) && $capabilities !== []
            ? implode(', ', array_map(fn (mixed $capability): string => (string) $capability, $capabilities))
            : 'read_files, inspect_git';

        return <<<TXT
# Runtime de ferramentas Atlas

Modo autorizado: {$mode}
Workspace autorizado: {$workspace}
Sandbox Codex previsto: {$sandbox}
Capacidades: {$capabilityText}

Regras:
- Execute leitura, escrita ou comandos somente dentro das capacidades acima.
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
