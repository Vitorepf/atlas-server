<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasArchitectureOperationsCatalog
{
    /**
     * @param  array<int,array<string,mixed>>|null  $commandsOverride
     */
    public function __construct(
        private readonly ?array $commandsOverride = null,
    ) {}

    public function sectionKey(): string
    {
        return 'arquitetura_mae';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function commands(): array
    {
        if ($this->commandsOverride !== null) {
            return $this->commandsOverride;
        }

        return [
            [
                'id' => 'architecture_operations',
                'command' => 'atlas ai architecture-operations --json',
                'description' => 'Lista o catalogo canonico de comandos da arquitetura mae consumido por CLI help, Observability e Open Brain MCP.',
                'surface' => 'cli',
                'kind' => 'catalog',
                'output' => 'json',
            ],
            [
                'id' => 'architecture_validate',
                'command' => 'atlas ai architecture-validate',
                'description' => 'Valida contratos executaveis da arquitetura mae: capabilities, domains, adapters, providers, SLOs e APs estaticos.',
                'surface' => 'cli',
                'kind' => 'validation',
                'output' => 'human_json',
            ],
            [
                'id' => 'architecture_readiness',
                'command' => 'php artisan atlas:ai:architecture-readiness --json',
                'description' => 'Agrega status de architecture-validate, docs-health, docs-split-plan, provider projection e catalogo de operacoes para orientar sessoes novas.',
                'surface' => 'cli',
                'kind' => 'readiness',
                'output' => 'json',
                'api_endpoint' => '/ai/architecture/readiness',
                'mcp_tool' => 'atlas_architecture_readiness',
            ],
            [
                'id' => 'documentation_health',
                'command' => 'atlas engineering knowledge docs-health --json',
                'description' => 'Audita bootstrap, frontmatter, limites de linhas e docs split-required da Knowledge Base canonica.',
                'surface' => 'cli',
                'kind' => 'validation',
                'output' => 'json',
            ],
            [
                'id' => 'session_bootstrap',
                'command' => 'php artisan atlas:ai:session-bootstrap --task="<task>" --json',
                'strict_command' => 'php artisan atlas:ai:session-bootstrap --task="<task>" --strict --json',
                'description' => 'Gera pacote canonico de inicio de sessao com docs obrigatorios, placement, KB status, provider projection, validacoes e riscos.',
                'surface' => 'cli',
                'kind' => 'bootstrap',
                'output' => 'json',
                'api_endpoint' => '/ai/session-bootstrap',
                'mcp_tool' => 'atlas_session_bootstrap',
                'output_contract' => [
                    'docs_split_plan' => [
                        'owner',
                        'status',
                        'split_required_count',
                        'total_split_required_count',
                        'execution_order',
                        'first_doc',
                        'command',
                    ],
                ],
                'strict_gate' => [
                    'enabled' => true,
                    'blocks_when' => 'gate_status=blocked',
                    'http_status' => 409,
                    'mcp_error' => 'session_bootstrap_blocked_by_strict_gate',
                ],
            ],
            [
                'id' => 'feature_placement',
                'command' => 'php artisan atlas:ai:place-feature "<feature>" --json',
                'strict_command' => 'php artisan atlas:ai:place-feature "<feature>" --strict --json',
                'description' => 'Localiza feature em Core/Domain/Surface/Runtime/AP, aponta doc dono e avisa possiveis duplicacoes antes de implementar.',
                'surface' => 'cli',
                'kind' => 'governance_gate',
                'output' => 'json',
                'api_endpoint' => '/ai/feature-placement',
                'mcp_tool' => 'atlas_feature_placement',
                'strict_gate' => [
                    'enabled' => true,
                    'blocks_when' => 'gate_status=blocked',
                    'http_status' => 409,
                    'mcp_error' => 'feature_placement_blocked_by_strict_gate',
                ],
            ],
            [
                'id' => 'documentation_split_plan',
                'command' => 'php artisan atlas:ai:docs-split-plan --json',
                'description' => 'Transforma docs split_required em fila priorizada e machine-readable para reduzir debt documental sem perder autoridade.',
                'surface' => 'cli',
                'kind' => 'governance_gate',
                'output' => 'json',
                'api_endpoint' => '/ai/docs-split-plan',
                'mcp_tool' => 'atlas_docs_split_plan',
                'filter_options' => ['owner', 'severity', 'status'],
                'focused_command' => 'php artisan atlas:ai:docs-split-plan --owner=<owner_area> --json',
            ],
            [
                'id' => 'ap_agent_workflow_registry',
                'command' => 'php artisan atlas:ai:ap-agent-workflow --json',
                'description' => 'Exibe a sequencia canonica AP-200..AP-225 para sessoes de agentes, incluindo handoff, gates, revisao humana e dry-run sem efeitos colaterais.',
                'surface' => 'cli',
                'kind' => 'governance_gate',
                'output' => 'json',
                'doc' => 'docs/ap/AP-204-ap-agent-workflow-registry.md',
            ],
            [
                'id' => 'provider_projection_status',
                'command' => 'php artisan atlas:memory:projection status --target=all --workspace=<workspace> --json',
                'description' => 'Verifica se AGENTS.md e CLAUDE.md estao sincronizados com a projection canonica e se o bloco manual permanece preservado.',
                'surface' => 'cli',
                'kind' => 'provider_projection',
                'output' => 'json',
            ],
            [
                'id' => 'provider_projection_write',
                'command' => 'php artisan atlas:memory:projection write --target=all --workspace=<workspace> --force --json',
                'description' => 'Regenera AGENTS.md e CLAUDE.md a partir da governanca canonica, preservando blocos manuais gerenciados.',
                'surface' => 'cli',
                'kind' => 'provider_projection',
                'output' => 'json',
            ],
            [
                'id' => 'provider_release_review',
                'command' => 'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json',
                'description' => 'Transforma lancamento de provider em Provider Release Envelope com docs donos, APs, Rivals, riscos e sinal seguro para Atlas Decide.',
                'surface' => 'cli',
                'kind' => 'provider_evolution',
                'output' => 'json',
                'api_endpoint' => '/ai/provider-release-review',
            ],
            [
                'id' => 'knowledge_sync',
                'command' => 'atlas engineering knowledge sync --prune --json',
                'description' => 'Sincroniza docs canonicos para a Knowledge Base operacional e remove entradas obsoletas.',
                'surface' => 'cli',
                'kind' => 'maintenance',
                'output' => 'json',
            ],
            [
                'id' => 'code_intelligence_index',
                'command' => 'atlas engineering knowledge index-code --prune --json',
                'description' => 'Atualiza Code Intelligence para que novas sessoes encontrem codigo, testes, docs e relacoes reais.',
                'surface' => 'cli',
                'kind' => 'maintenance',
                'output' => 'json',
            ],
            [
                'id' => 'kernel_slo_report',
                'command' => 'atlas ai slo --hours=24 --json',
                'description' => 'Audita SLOs do kernel por janela usando o Evidence Ledger e review_signal canonico.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_contract',
                'command' => 'atlas ai voice contract --json',
                'api_endpoint' => '/ai/voice/runtime/contract',
                'mobile_endpoint' => '/v1/mobile/ai/voice/runtime/contract',
                'description' => 'Exibe contrato machine-readable do Voice Realtime para LiveKit Agents SDK sem permitir bypass do Kernel.',
                'surface' => 'cli',
                'kind' => 'surface_contract',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_bootstrap',
                'command' => 'atlas ai voice bootstrap --json',
                'api_endpoint' => '/ai/voice/runtime/bootstrap',
                'mobile_endpoint' => '/v1/mobile/ai/voice/runtime/bootstrap',
                'description' => 'Gera manifesto de bootstrap para runtime LiveKit/Python subir obedecendo endpoints, auth, SLO e proibicoes do Kernel.',
                'surface' => 'cli',
                'kind' => 'surface_contract',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_dependencies',
                'command' => 'atlas ai voice dependencies --json',
                'api_endpoint' => '/ai/voice/runtime/dependencies',
                'mobile_endpoint' => '/v1/mobile/ai/voice/runtime/dependencies',
                'description' => 'Mostra manifesto versionado de dependencias do runtime Python de voz, separando core sem terceiros de LiveKit Agents SDK opcional.',
                'surface' => 'cli',
                'kind' => 'runtime_contract',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_scripted_worker_example',
                'command' => 'atlas ai voice scripted-example --json',
                'description' => 'Exibe o exemplo canonico e comando para ensaiar o worker LiveKit/Python sem SDK real, mantendo Kernel como unica autoridade.',
                'surface' => 'cli',
                'kind' => 'runtime_contract',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_scripted_smoke',
                'command' => 'atlas ai voice scripted-smoke --json',
                'description' => 'Executa smoke deterministico do worker LiveKit/Python contra Kernel mockado, validando session, turn, callbacks, sanitizacao e fechamento de sessao.',
                'surface' => 'cli',
                'kind' => 'validation',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_callback_smoke',
                'command' => 'atlas ai voice callback-smoke --json',
                'description' => 'Valida uma callback LiveKit/Python isolada contra Kernel mockado e garante que callback router nao persiste audio, token ou texto cru.',
                'surface' => 'cli',
                'kind' => 'validation',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_callback_sequence_smoke',
                'command' => 'atlas ai voice callback-sequence-smoke --json',
                'description' => 'Valida sequencia canonica de callbacks LiveKit/Python para impedir switches paralelos e drift entre turn, TTS, playback e health.',
                'surface' => 'cli',
                'kind' => 'validation',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_callback_loop_check',
                'command' => 'atlas ai voice callback-loop-check --json',
                'description' => 'Inspeciona se a camada de traducao de callbacks LiveKit esta completa e confirma que o loop SDK real ainda nao libera start sem gate.',
                'surface' => 'cli',
                'kind' => 'runtime_contract',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_preflight',
                'command' => 'atlas ai voice preflight --json',
                'description' => 'Executa preflight local do runtime Python de voz, checando env, bootstrap, SDK opcional e guardrails antes de qualquer worker.',
                'surface' => 'cli',
                'kind' => 'validation',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_activation_contract',
                'command' => 'atlas ai voice activation-contract --json',
                'description' => 'Publica contrato de ativacao do worker LiveKit real com gates, sequencia obrigatoria e atalhos proibidos.',
                'surface' => 'cli',
                'kind' => 'runtime_contract',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_sdk_check',
                'command' => 'atlas ai voice sdk-check --json',
                'description' => 'Inspeciona disponibilidade opcional do LiveKit Agents SDK no runtime Python sem importar provider, executar ferramenta ou persistir audio.',
                'surface' => 'cli',
                'kind' => 'runtime_contract',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_worker_plan',
                'command' => 'atlas ai voice worker-plan --json',
                'description' => 'Mostra o plano fail-closed para ativar o worker LiveKit Agents SDK real, incluindo adapters, env, guardrails e proximo gate.',
                'surface' => 'cli',
                'kind' => 'runtime_contract',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_production_loop_plan',
                'command' => 'atlas ai voice production-loop-plan --json',
                'description' => 'Mostra o plano fail-closed para conectar o loop real do LiveKit Agents SDK ao CallbackRouter sem iniciar daemon ou burlar o Kernel.',
                'surface' => 'cli',
                'kind' => 'runtime_contract',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_production_loop_smoke',
                'command' => 'atlas ai voice production-loop-smoke --json',
                'description' => 'Executa eventos em formato LiveKit SDK pelo production-loop runner, bridge e Kernel mockado sem importar SDK, iniciar daemon ou persistir audio raw.',
                'surface' => 'cli',
                'kind' => 'validation',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_worker_start_check',
                'command' => 'atlas ai voice worker-start-check --json',
                'description' => 'Executa uma tentativa governada de start do worker LiveKit; hoje deve bloquear sem SDK/callback loop real e provar que nada inicia fora do Kernel.',
                'surface' => 'cli',
                'kind' => 'runtime_contract',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_runtime_certification',
                'command' => 'atlas ai voice runtime-certify --json',
                'api_endpoint' => '/ai/voice/runtime/certification',
                'mobile_endpoint' => '/v1/mobile/ai/voice/runtime/certification',
                'description' => 'Agrega preflight, callback sequence, production-loop smoke e worker-start check em um certificado canonico do runtime de voz sem iniciar daemon.',
                'surface' => 'cli',
                'kind' => 'validation',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_readiness',
                'command' => 'atlas ai voice readiness --hours=24 --json',
                'api_endpoint' => '/ai/voice/readiness',
                'mobile_endpoint' => '/v1/mobile/ai/voice/readiness',
                'description' => 'Audita eventos VOICE_*, SLOs e gates Rivals-Voice para confirmar que a surface de voz esta pronta sem persistir audio raw.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_rivals_report',
                'command' => 'atlas ai voice rivals --hours=24 --json',
                'api_endpoint' => '/ai/voice/rivals',
                'mobile_endpoint' => '/v1/mobile/ai/voice/rivals',
                'description' => 'Compara readiness e evidencias do Atlas Voice contra baseline direto quando houver turnos comparaveis, sem executar provider ou gravar audio raw.',
                'surface' => 'cli',
                'kind' => 'maturity_report',
                'output' => 'json',
            ],
            [
                'id' => 'voice_python_runtime_contract_test',
                'command' => 'PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests',
                'description' => 'Valida que o runtime Python de Voice Realtime aceita apenas manifesto do Kernel e rejeita provider/tool/persistencia raw.',
                'surface' => 'runtime',
                'kind' => 'runtime_contract',
                'output' => 'text',
            ],
            [
                'id' => 'kernel_pipeline_report',
                'command' => 'atlas ai kernel-pipeline-report --hours=24 --json',
                'description' => 'Mostra aceite/rejeicao do Kernel Pipeline por surface, flow, input mode e contract source.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'repair_report',
                'command' => 'atlas ai repair-report --hours=24 --json',
                'description' => 'Resume Repair Loop por strategy, failure domain, status e recommended_action.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'provider_performance_report',
                'command' => 'atlas ai provider-performance --hours=24 --json',
                'description' => 'Projeta performance empirica de providers a partir do Ledger para apoiar Atlas Decide e Self-Improvement.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'agent_behavior_report',
                'command' => 'atlas ai agent-behavior-report --hours=24 --json',
                'description' => 'Resume findings comportamentais dos agentes por provider, modelo, agente e contrato para apoiar Curator e review de qualidade.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'dynamic_compute_market_report',
                'command' => 'atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
                'description' => 'Explica recomendacao shadow do Dynamic Compute Market sem trocar provider, sem executar tarefa e sem bypassar DecisionReceipt.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'provider_performance_curator_review',
                'command' => 'atlas ai self-improve --flow=provider_performance_review --hours=168 --json',
                'description' => 'Roda o Curator proposal-only sobre AP-99 para abrir findings revisaveis de policy patch, cost rates e Dynamic Compute Market benchmark.',
                'surface' => 'cli',
                'kind' => 'curator_review',
                'output' => 'json',
            ],
            [
                'id' => 'provider_release_curator_review',
                'command' => 'atlas ai self-improve --flow=provider_release_review --hours=168 --json',
                'description' => 'Roda o Curator proposal-only sobre Provider Evolution para revisar releases externos, Rivals pendente, skill packs e risco de hardcode.',
                'surface' => 'cli',
                'kind' => 'curator_review',
                'output' => 'json',
            ],
            [
                'id' => 'agent_behavior_curator_review',
                'command' => 'atlas ai self-improve --flow=agent_behavior_review --hours=168 --json',
                'description' => 'Roda o Curator proposal-only sobre comportamento dos agentes para revisar verificacao ausente, diffs pouco cirurgicos e drift de instrucoes.',
                'surface' => 'cli',
                'kind' => 'curator_review',
                'output' => 'json',
            ],
            [
                'id' => 'voice_realtime_curator_review',
                'command' => 'atlas ai self-improve --flow=voice_realtime_review --hours=168 --json',
                'description' => 'Roda o Curator proposal-only dedicado a Voice Realtime, revisando readiness, runtime certification, Rivals-Voice baseline e gates de privacidade.',
                'surface' => 'cli',
                'kind' => 'curator_review',
                'output' => 'json',
            ],
            [
                'id' => 'provider_cost_rates_missing',
                'command' => 'atlas ai telemetry cost-rates --missing --hours=168 --json',
                'description' => 'Lista pares provider/model sem cost rate ativo para fechar AP-99, Dynamic Compute Market e findings configure_provider_cost_rates.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'provider_cost_rates_upsert',
                'command' => 'atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json',
                'description' => 'Registra rate humano governado para provider/model; nao escolhe modelo, nao consulta preco externo e mantem override auditavel.',
                'surface' => 'cli',
                'kind' => 'review_action',
                'output' => 'json',
            ],
            [
                'id' => 'qualitative_levels_report',
                'command' => 'atlas ai qualitative-levels --hours=720 --json',
                'description' => 'Declara current_level, evidence, missing_gates e next_level_blockers para o roadmap P1-P7 sem alterar comportamento.',
                'surface' => 'cli',
                'kind' => 'maturity_report',
                'output' => 'json',
            ],
            [
                'id' => 'rivals_strategy_report',
                'command' => 'atlas ai rivals-strategy report --hours=8760 --json',
                'description' => 'Mede decisoes diretas vs assistidas por Atlas com revisitas 30/90/180/365 e scores de regret, alignment e agency.',
                'surface' => 'cli',
                'kind' => 'maturity_report',
                'output' => 'json',
            ],
            [
                'id' => 'rivals_strategy_due_reviews',
                'command' => 'atlas ai rivals-strategy due-reviews --due-days=30 --json',
                'description' => 'Lista revisitas pendentes do Rivals Strategy e mostra o comando seguro para registrar scores humanos.',
                'surface' => 'cli',
                'kind' => 'review_queue',
                'output' => 'json',
            ],
            [
                'id' => 'rivals_strategy_record_review',
                'command' => 'atlas ai rivals-strategy record-review --review-id=<id> --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json',
                'description' => 'Registra scores humanos de regret, alignment e agency para uma revisita do Rivals Strategy sem executar acao externa.',
                'surface' => 'cli',
                'kind' => 'review_action',
                'output' => 'json',
            ],
            [
                'id' => 'strategic_decision_review',
                'command' => 'atlas ai strategic-decision review --json',
                'description' => 'Gera packet plan-only de decisao estrategica com cool-down, counterargument, values alignment, agency gate e Rivals Strategy.',
                'surface' => 'cli',
                'kind' => 'planning_surface',
                'output' => 'json',
            ],
            [
                'id' => 'decision_receipt_report',
                'command' => 'atlas ai decision-receipt-report --envelope=<id> --json',
                'description' => 'Audita replay de DecisionReceipt por envelope, verificando receipt_hash, chain_hash e review_signal.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'ledger_replay',
                'command' => 'atlas ledger replay --envelope=<id> --json',
                'description' => 'Reproduz a timeline append-only do Evidence Ledger para um envelope, usando o mesmo report canonico de atlas ai ledger.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'ledger_projection_worker',
                'command' => 'atlas ai ledger-project --limit=500 --json',
                'description' => 'Projeta eventos append-only do Evidence Ledger para read models operacionais como ai_traces, atlas_engineering_runs e atlas_tool_runs.',
                'surface' => 'cli',
                'kind' => 'maintenance',
                'output' => 'json',
            ],
            [
                'id' => 'self_improvement_schedule_report',
                'command' => 'atlas ai self-improvement-schedule-report --hours=24 --json',
                'description' => 'Audita schedule replay do Self-Improvement, proposals emitidas e refs do Inbox.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
            [
                'id' => 'inbox_action_report',
                'command' => 'atlas ai inbox-action-report --hours=24 --json',
                'description' => 'Audita acoes humanas do Inbox, incluindo review_patch, refs de diff e gaps que o Curator deve aprender.',
                'surface' => 'cli',
                'kind' => 'evidence_report',
                'output' => 'json',
            ],
        ];
    }

    /**
     * @param  array{id?:string|null,kind?:string|null}  $filters
     * @return array{schema_version:string,section:string,command_count:int,operation_ids:array<int,string>,filters:array<string,string>,commands:array<int,array<string,mixed>>}
     */
    public function summary(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $commands = array_values(array_filter(
            $this->commands(),
            fn (array $command): bool => $this->matchesFilters($command, $filters),
        ));

        return [
            'schema_version' => 'atlas.architecture_operations.v1',
            'section' => $this->sectionKey(),
            'command_count' => count($commands),
            'operation_ids' => array_values(array_filter(array_map(
                fn (array $command): ?string => is_string($command['id'] ?? null) ? $command['id'] : null,
                $commands,
            ))),
            'filters' => $filters,
            'commands' => $commands,
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,string>
     */
    private function normalizeFilters(array $filters): array
    {
        return array_filter([
            'id' => is_string($filters['id'] ?? null) && $filters['id'] !== '' ? $filters['id'] : null,
            'kind' => is_string($filters['kind'] ?? null) && $filters['kind'] !== '' ? $filters['kind'] : null,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $command
     * @param  array<string,string>  $filters
     */
    private function matchesFilters(array $command, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (($command[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
