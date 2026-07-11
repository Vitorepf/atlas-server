#!/usr/bin/env bash
# ACOS Excellence MEM-04 — seed pétrea decision memories (idempotent via source_id).
# Replay: ./scripts/acos-mem-04-petrea-decisions.sh
# Verify: for q in 'loop morto autonomos vivo' 'foco terminal-first review' 'commit escopado na main' 'keep-list AtlasLoop'; do
#   php artisan atlas:memory:recall "$q" --peek --json | jq -e '[.memory_recall.recall[] | select(.type=="decision")] | length > 0' || exit 1
# done; echo ALL_RECALLED

set -euo pipefail
cd "$(dirname "$0")/.."

dedupe() {
  local sid="$1"
  php artisan atlas:memory:list --json 2>/dev/null \
    | jq -e --arg sid "$sid" '[.memories[] | select(.source_id == $sid and .status == "active")] | length > 0' >/dev/null 2>&1
}

add_if_missing() {
  local sid="$1" title="$2" summary="$3" body="$4"
  if dedupe "$sid"; then
    echo "skip (exists): $sid"
    return 0
  fi
  php artisan atlas:memory:add \
    --type=decision \
    --scope-type=global \
    --title="$title" \
    --summary="$summary" \
    --priority=95 \
    --importance=5 \
    --allow-external-ai \
    --source-type=acos_excellence_slice \
    --source-id="$sid" \
    --tag=petrea \
    --tag=acos-mem-04 \
    --tag=decision \
    --json \
    -- "$body" >/dev/null
  echo "added: $sid"
}

add_if_missing \
  'mem-04-acde-loop-cadaver' \
  'ACDE-Loop cadáver — AutonomousEvolution raiz morto' \
  'O MVP ACDE em AutonomousEvolution/ (~600 classes AtlasLoop* raiz e 335 cmds atlas:loop:*) não é o motor vivo; mirar ATLAS_LOOP_MASTER_ENABLED como meta evolui o cadáver errado.' \
  'O repositório carrega ~600 classes AtlasLoop* na raiz de AutonomousEvolution/ fora de Brain/, mais 335 comandos atlas:loop:* — herança do MVP ACDE encerrado. Esse caminho não origina nem implementa evolução hoje. Por quê: IAs repetidamente trataram esse bloco como sistema autônomo 24/7 e gastaram ciclos religando switches do cadáver; o doc canon separa morto (ACDE) de vivo (Autônomos). Evidência: docs/engineering-knowledge-base/atlas-autonomos-live-system.md; docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md.'

add_if_missing \
  'mem-04-autonomos-vivo' \
  'Autônomos VIVO — brain:* origina, task:* implementa' \
  'Cérebro externo (atlas:brain:next author≠judge + atlas:brain:seed gate ~50%) projeta specs; músculo (atlas:task next) implementa via provider — já entregou milhares de landings na main local.' \
  'A arquitetura viva de auto-evolução é cérebro + músculo: Brain/ (~96 AtlasBrain*) origina e gateia tasks; SelfConstruction/ implementa com provider externo. Comandos vivos são atlas:brain:* e atlas:task:* — não atlas:loop:*. Por quê: confundir ACDE morto com Autônomos vivo faz agentes operarem superfície descontinuada enquanto o caminho real já provou milhares de landings. Evidência: docs/engineering-knowledge-base/atlas-autonomos-live-system.md.'

add_if_missing \
  'mem-04-terminal-first-review' \
  'North-star terminal: moat = verificação, não casca' \
  'Atlas não constrói shell própria (must_build_own_shell=false); aluga harness L1, investe CLI L2 como API do produto, e o M vive em seams brain-behind L3 — gargalo 2026 é confiar na saída do agente.' \
  'Decisão estratégica pétrea: Atlas roda em host de terminal neutro (Maestri/iTerm/tmux) com poder cheio via CLI; não compete construindo IDE/casca. O moat competitivo é verificação e review (gates, evidence, proof) — não geração de código nem edição inline. Modelo de 3 camadas: L1 harness commodity (alugar), L2 superfície CLI (investir), L3 seams memória/gates/evidence (o M). Por quê: construir casca própria dilui investimento e perde para IDEs commodity; o gargalo real em 2026 é confiar na saída autônoma. Evidência: docs/engineering-knowledge-base/atlas-terminal-first-focus.md; docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md.'

add_if_missing \
  'mem-04-commit-escopado' \
  'Landing autônomo = git add restrito + commit na branch atual' \
  'AtlasTaskScopedCommitter faz git add -- <só arquivos da task> e commit na main local; proibido git add -A, merge no caminho comum, branch-and-merge por task, ou push automático.' \
  'Todos os agentes autônomos trabalham na mesma branch (main local) e commitam só os arquivos escopados da task via SelfConstruction/AtlasTaskScopedCommitter: git add -- <files> seguido de git commit — nunca git add -A, nunca merge (exceto obra grande via AtlasLoopObraAutoMergeService). Por quê: commits globais ou merges por task contaminam a main com trabalho alheio e quebram rastreabilidade de prova por task; o padrão escopado já sustenta milhares de landings atlas-task. Evidência: docs/engineering-knowledge-base/atlas-autonomos-live-system.md; app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php.'

add_if_missing \
  'mem-04-atlasloop-keep-list' \
  '26 classes AtlasLoop* legadas mas VIVAS — keep-list obrigatório' \
  'Prefixo AtlasLoop* parece ACDE morto, mas 26 classes são consumidas por SelfConstruction/, Brain/ ou cmds brain/task — deletar pelo prefixo quebra o autônomo; rg --no-ignore -w <Classe> antes de qualquer remoção.' \
  'Das ~600 classes AtlasLoop* sob AutonomousEvolution/ raiz, 26 permanecem referenciadas por consumidores vivos (AtlasLoopMasterSwitch, AtlasLoopMergeActuator, AtlasLoopOriginationPipeline, etc.). Deletá-las por associação de prefixo viola o piso operacional do autônomo. Regra: rg --no-ignore -w <Classe> ≠ 0 ⇒ PARE; lista canônica em atlas-autonomos-live-system.md. Por quê: limpeza agressiva por prefixo já foi tentada conceitualmente e quebraria Brain/SelfConstruction/comandos vivos — o keep-list é sinal antecipado para IAs não iniciarem deleção. Evidência: docs/engineering-knowledge-base/atlas-autonomos-live-system.md.'

echo "MEM-04 seed complete."
