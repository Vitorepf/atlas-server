<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\AtlasDialecticTensionService;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Verbatim rendermarkdown family extracted from the AOBG context-pack
 * façade; behavior-preserving (private helpers -> Support; public API stays on the façade).
 */
final class RenderMarkdownSection
{
    public function __construct(
        private readonly Support $support,
    ) {}

    /**
     * Render the pack as a compact, human/agent-readable markdown brief — the
     * one block a hook injects into a provider prompt. Empty sections are
     * labelled honestly (never silently dropped) so the consumer can tell
     * "the brain has nothing here" from "the brain was not consulted".
     *
     * @param  array<string,mixed>  $pack
     */
    public function renderMarkdown(array $pack): string
    {
        $lines = [];
        $lines[] = '# Atlas Open Brain Context Pack (AOBG)';
        $lines[] = sprintf(
            'task="%s"  workspace=%s  provider-bound=yes  %s',
            (string) ($pack['task'] ?? ''),
            (string) ($pack['workspace'] ?? ''),
            AtlasOpenBrainContextPackService::HONESTY_LABEL,
        );
        $lines[] = '';

        // WO-17-T1 — resumption FIRST: on resume, "você estava no slice N, provou X,
        // falta Y, cuidado com Z" is the most important thing the session can read.
        $retomada = (array) ($pack['retomada'] ?? []);
        if (($retomada['present'] ?? false) === true) {
            $last = (array) ($retomada['last_session'] ?? []);
            $lines[] = '## Retomada da obra ativa';
            $lines[] = sprintf('- obra=%s  fase=%s', (string) ($retomada['obra_id'] ?? ''), (string) ($retomada['phase'] ?? '—'));
            if ($last !== []) {
                $result = $last['result'] ?? null;
                $resultStr = is_array($result) ? (($result['delivered'] ?? false) ? 'delivered' : (string) ($result['status'] ?? 'registrado')) : (is_scalar($result) ? (string) $result : '—');
                $lines[] = sprintf(
                    '- última sessão: tocou %d arquivo(s); resultado=%s; em %s',
                    count((array) ($last['files'] ?? [])),
                    $resultStr,
                    (string) ($last['at'] ?? '—'),
                );
                $files = array_slice((array) ($last['files'] ?? []), 0, 6);
                if ($files !== []) {
                    $lines[] = '  arquivos: '.implode(', ', array_map('strval', $files));
                }
            }
            $lines[] = '- '.(string) ($retomada['drift'] ?? '');
            $pend = array_slice((array) ($retomada['pendencies'] ?? []), 0, 6);
            if ($pend !== []) {
                $lines[] = '- falta: '.implode('; ', array_map('strval', $pend));
            }
            foreach (array_slice((array) ($retomada['refutacoes'] ?? []), 0, 3) as $ref) {
                $lines[] = '- ⚠️ forbidden-context: '.$this->formatRefutationMatch($ref);
            }
            $lines[] = '';
        }

        // WO-17-T2 — the deterministic brief. Staleness is ALWAYS visible (never a
        // silent stale brief): "BRIEF STALE desde X" when HEAD moved past it.
        $brief = (array) ($pack['brief'] ?? []);
        if (($brief['present'] ?? false) === true) {
            $lines[] = '## Brief (determinístico)';
            if (($brief['stale'] ?? false) === true) {
                $lines[] = '- ⚠️ BRIEF STALE desde '.(string) ($brief['generated_at'] ?? '').' — o HEAD mudou; rode `atlas:brief --generate`';
            } else {
                $lines[] = '- fresh (gerado '.(string) ($brief['generated_at'] ?? '').')';
            }
            $invariants = array_slice((array) ($brief['invariants'] ?? []), 0, 3);
            if ($invariants !== []) {
                $lines[] = '- invariantes (pétreas): '.implode('; ', array_map('strval', $invariants));
            }
            foreach (array_slice((array) ($brief['refutations'] ?? []), 0, 3) as $ref) {
                $lines[] = '- ⚠️ refutação: '.(string) $ref;
            }
            $modules = array_map(
                static fn ($m): string => is_array($m) ? (string) ($m['module'] ?? '') : (string) $m,
                array_slice((array) ($brief['modules'] ?? []), 0, 3),
            );
            $modules = array_values(array_filter($modules, static fn (string $m): bool => $m !== ''));
            if ($modules !== []) {
                $lines[] = '- módulos quentes: '.implode(', ', $modules);
            }
            $lines[] = '';
        }

        $policy = (array) ($pack['context_delivery_policy'] ?? []);
        $lines[] = '## Context delivery policy';
        $lines[] = sprintf(
            '- mode=%s status=%s actions=%s budget_multiplier=%.2f applied=%s',
            (string) ($policy['delivery_mode'] ?? 'standard_minimal_top_k'),
            (string) ($policy['status'] ?? 'inactive'),
            implode(',', $this->support->stringList($policy['actions'] ?? [])) ?: 'keep_current_pack',
            AiValueNormalizer::finiteFloatOrNull($policy['initial_context_budget_multiplier'] ?? null) ?? 1.0,
            (bool) ($policy['applied_to_initial_budget'] ?? false) ? 'yes' : 'no',
        );
        $handles = $this->support->stringList($policy['on_demand_handles'] ?? []);
        if ($handles !== []) {
            $lines[] = '- expand_on_demand: '.implode(', ', array_slice($handles, 0, 8));
        }
        $pathFiltered = (int) data_get($pack, 'context_hygiene.path_filtered', 0);
        $feedbackDemoted = (int) data_get($pack, 'context_hygiene.feedback_demoted', 0);
        $memoryFiltered = (int) data_get($pack, 'context_hygiene.memory_relevance_filtered', 0);
        $sessionEchoFiltered = (int) data_get($pack, 'context_hygiene.session_echo_filtered', 0);
        $docMissionFiltered = (int) data_get($pack, 'context_hygiene.doc_mission_filtered', 0);
        $totalCeilingTrimmed = (int) data_get($pack, 'context_hygiene.total_ceiling_trimmed', 0);
        if ($pathFiltered > 0 || $feedbackDemoted > 0 || $memoryFiltered > 0 || $sessionEchoFiltered > 0 || $docMissionFiltered > 0 || $totalCeilingTrimmed > 0) {
            $lines[] = sprintf(
                '- context_hygiene: path_filtered=%d feedback_demoted=%d memory_relevance_filtered=%d session_echo_filtered=%d doc_mission_filtered=%d total_ceiling_trimmed=%d',
                $pathFiltered,
                $feedbackDemoted,
                $memoryFiltered,
                $sessionEchoFiltered,
                $docMissionFiltered,
                $totalCeilingTrimmed,
            );
        }
        $sourceSelection = (array) ($policy['source_selection_policy'] ?? []);
        if ((bool) ($sourceSelection['applied_to_initial_pack'] ?? false)) {
            $multipliers = (array) ($sourceSelection['budget_multipliers'] ?? []);
            $lines[] = sprintf(
                '- source_mix: code=%.2f graph=%.2f memory=%.2f',
                AiValueNormalizer::finiteFloatOrNull($multipliers['code'] ?? null) ?? 1.0,
                AiValueNormalizer::finiteFloatOrNull($multipliers['graph'] ?? null) ?? 1.0,
                AiValueNormalizer::finiteFloatOrNull($multipliers['memory'] ?? null) ?? 1.0,
            );
        }
        $initialCodePolicy = (array) ($policy['initial_code_graph_delivery_policy'] ?? []);
        if ((int) ($initialCodePolicy['deferred_count'] ?? 0) > 0) {
            $deferredTypes = $this->support->stringList($initialCodePolicy['deferred_source_types'] ?? []);
            $lines[] = sprintf(
                '- deferred_code_symbols: count=%d sources=%s',
                (int) ($initialCodePolicy['deferred_count'] ?? 0),
                $deferredTypes !== [] ? implode(',', $deferredTypes) : 'n/a',
            );
        }
        $lines[] = '';

        $fusion = (array) ($pack['retrieval_fusion'] ?? []);
        if ($fusion !== []) {
            $lines[] = '## Unified retrieval priority';
            $lines[] = sprintf(
                '- status=%s algorithm=%s candidates=%d',
                (string) ($fusion['status'] ?? 'unknown'),
                (string) ($fusion['algorithm'] ?? 'unknown'),
                count((array) ($fusion['candidates'] ?? [])),
            );
            foreach (array_slice((array) ($fusion['candidates'] ?? []), 0, 8) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- [%s] %s (rank=%d score=%.8f)',
                    (string) ($candidate['source'] ?? 'unknown'),
                    mb_substr((string) ($candidate['label'] ?? $candidate['ref'] ?? ''), 0, 160),
                    (int) ($candidate['source_rank'] ?? 0),
                    AiValueNormalizer::finiteFloatOrNull($candidate['fused_score'] ?? null) ?? 0.0,
                );
            }
            $lines[] = '';
        }

        $feedback = (array) ($pack['context_feedback_request'] ?? []);
        if ($feedback !== []) {
            $lines[] = '## Context feedback request';
            $lines[] = sprintf(
                '- after_execution: call %s with context_pack_hash=%s flow=%s record=true',
                (string) ($feedback['tool'] ?? 'atlas_context_feedback'),
                substr((string) ($feedback['context_pack_hash'] ?? ''), 0, 16),
                (string) ($feedback['flow_id'] ?? ''),
            );
            $lines[] = sprintf(
                '- report used/noise/missed refs from %d delivered refs; no raw logs or source text',
                (int) ($feedback['delivered_ref_count'] ?? 0),
            );
            $lines[] = '- in the report, cite every used context item with its exact rendered ref= value';
            $lines[] = '';
        }

        $compacted = (array) ($pack['compacted'] ?? []);
        if (($compacted['present'] ?? false) === true) {
            $lines[] = '## Compactação';
            $lines[] = sprintf(
                '- scope=%s:%s receipt_hash=%s coverage=%s loss_risk=%s unresolved_loss=%d',
                (string) ($compacted['scope_type'] ?? 'unknown'),
                (string) ($compacted['scope_id'] ?? ''),
                substr((string) ($compacted['receipt_hash'] ?? ''), 0, 16),
                is_numeric($compacted['must_keep_coverage'] ?? null) ? (string) $compacted['must_keep_coverage'] : 'n/a',
                (string) ($compacted['loss_risk'] ?? 'unknown'),
                (int) ($compacted['unresolved_loss_count'] ?? 0),
            );
            foreach (array_slice($this->support->stringList($compacted['recovery_queries'] ?? []), 0, 8) as $query) {
                $lines[] = '- recovery_query: `'.$query.'`';
            }
            $lines[] = '- workflow: '.(string) ($compacted['workflow'] ?? 'review_compaction_receipt');
            $lines[] = '';
        }

        $obraWorkingSet = (array) ($pack['obra_working_set'] ?? []);
        if ($obraWorkingSet !== []) {
            $lines[] = '## Obra working set (pointers)';
            $lines[] = sprintf(
                '- obra_id=%s status=%s count=%d soak=%s/%s',
                (string) ($obraWorkingSet['obra_id'] ?? ''),
                ($obraWorkingSet['present'] ?? false) === true ? 'present' : 'empty_honest',
                (int) ($obraWorkingSet['count'] ?? 0),
                (string) data_get($obraWorkingSet, 'soak.status', 'pending_window'),
                (string) data_get($obraWorkingSet, 'soak.basis', 'real_retomadas_only'),
            );
            foreach (array_slice((array) ($obraWorkingSet['items'] ?? []), 0, 16) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $decision = trim((string) ($item['decision_id'] ?? ''));
                $lines[] = sprintf(
                    '- ref=%s origin=%s source_scope=%s%s',
                    (string) ($item['ref'] ?? ''),
                    (string) ($item['origin'] ?? 'obra_working_set'),
                    (string) ($item['source_scope'] ?? ''),
                    $decision !== '' ? ' decision_id='.$decision : '',
                );
            }
            $lines[] = '- invariant: refs are pointers to existing context entries; no content copies are stored here.';
            $lines[] = '';
        }

        // 1) code-graph
        $lines[] = '## Code graph (symbols)';
        $code = (array) ($pack['code_graph'] ?? []);
        if ($code === []) {
            $lines[] = '_no matching symbols in this workspace_';
        } else {
            foreach ($code as $item) {
                $sig = trim((string) ($item['signature'] ?? ''));
                $sig = $sig !== '' ? '; sig='.mb_substr($sig, 0, 160) : '';
                $lines[] = sprintf(
                    '- %s ref=%s [%s] type=%s%s',
                    (string) ($item['id'] ?? ''),
                    AtlasCanonicalContextRef::fromCodeItem((array) $item),
                    (string) ($item['file_path'] ?? 'n/a'),
                    ($item['symbol_type'] ?? '') !== '' ? (string) $item['symbol_type'] : 'n/a',
                    $sig,
                );
            }
        }
        $lines[] = '';

        // 2) reality graph paths
        $lines[] = '## Reality graph (cross-layer paths)';
        $paths = (array) ($pack['reality_graph_paths'] ?? []);
        if ($paths === []) {
            $lines[] = '_no provider-safe paths from the brain for this task_';
        } else {
            foreach ($paths as $path) {
                $chain = array_map(
                    static fn (array $n): string => (string) ($n['label'] !== '' ? $n['label'] : $n['id']),
                    (array) ($path['chain'] ?? []),
                );
                $lines[] = sprintf(
                    '- ref=%s %s%s',
                    AtlasCanonicalContextRef::fromGraphPath((array) $path),
                    implode(' -> ', $chain),
                    ($path['cross_layer'] ?? false) ? '  (cross-layer)' : '',
                );
            }
        }
        $lines[] = '';

        // 3) memory
        $lines[] = '## Memory (provider-safe, redacted)';
        $memory = (array) ($pack['memory'] ?? []);
        if ($memory === []) {
            $memoryStatus = (string) data_get($pack, 'provenance.memory.status', 'empty');
            $lines[] = match ($memoryStatus) {
                'retrieval_error' => '_memory retrieval unavailable (retrieval_error); no memory was fabricated_',
                'filtered' => '_memory candidates were filtered by provider-safe relevance policy_',
                default => '_no provider-safe memory recalled for this task_',
            };
        } else {
            foreach ($memory as $item) {
                $title = (string) ($item['title'] ?? '');
                $summary = trim((string) ($item['summary'] ?? ($item['body'] ?? '')));
                $lines[] = sprintf(
                    '- ref=%s [%s] %s%s',
                    AtlasCanonicalContextRef::fromMemoryItem((array) $item),
                    ($item['type'] ?? '') !== '' ? (string) $item['type'] : 'memory',
                    $title !== '' ? $title : '(untitled)',
                    $summary !== '' ? ' — '.mb_substr($summary, 0, 200) : '',
                );
            }

            // T4-S5 (Obra #17) — dialectic contradiction: MARK any OPEN conflict among the
            // recalled memories, so two sides of an unresolved tension are never delivered
            // as settled truth. Consumes the D3 conflict edges; fail-open (no relations /
            // any fault → no marks, pack byte-identical).
            foreach (app(AtlasDialecticTensionService::class)->tensionMarks($memory) as $mark) {
                $lines[] = $mark;
            }
        }

        $spanLevel = (array) ($pack['span_level_retrieval'] ?? []);
        if (($spanLevel['present'] ?? false) === true) {
            $lines[] = '';
            $lines[] = '## Span-level citations';
            $lines[] = '- claim/span refs are deterministic over delivered content only; cite `ref=...` exactly when using a span.';
            foreach (array_slice((array) ($spanLevel['claims'] ?? []), 0, 8) as $claim) {
                if (! is_array($claim)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- claim=%s status=%s',
                    mb_substr((string) ($claim['claim'] ?? ''), 0, 160),
                    (string) ($claim['status'] ?? 'unknown'),
                );
                foreach (array_slice((array) ($claim['spans'] ?? []), 0, 3) as $span) {
                    if (! is_array($span)) {
                        continue;
                    }
                    $lines[] = sprintf(
                        '  - ref=%s parent=%s content_version=%s bytes=%d-%d — %s',
                        (string) ($span['span_ref'] ?? ''),
                        (string) ($span['parent_ref'] ?? ''),
                        substr((string) ($span['content_version'] ?? ''), 0, 16),
                        (int) ($span['start'] ?? 0),
                        (int) ($span['end'] ?? 0),
                        mb_substr((string) ($span['span_excerpt'] ?? ''), 0, 180),
                    );
                }
            }
        }

        $epistemicEvidence = (array) ($pack['epistemic_evidence_bundle'] ?? []);
        if (($epistemicEvidence['present'] ?? false) === true) {
            $lines[] = '';
            $lines[] = '## Epistemic evidence bundle';
            $mustCarryRefs = (array) data_get($epistemicEvidence, 'must_carry.refs', []);
            $lines[] = '- must_carry: '.($mustCarryRefs === [] ? '_empty_honest_' : implode(', ', array_slice($mustCarryRefs, 0, 3)));
            $noveltyRefs = (array) data_get($epistemicEvidence, 'novelty_pool.refs', []);
            $lines[] = '- novelty_pool: '.($noveltyRefs === [] ? '_empty_honest_' : implode(', ', array_slice($noveltyRefs, 0, 5)));
            $lines[] = sprintf(
                '- operator_policy: %s (separate_from_evidence=%s)',
                (string) data_get($epistemicEvidence, 'operator_policy.mode', 'report_only'),
                data_get($epistemicEvidence, 'operator_policy.separate_from_evidence', true) ? 'true' : 'false',
            );
            $lines[] = sprintf(
                '- CONTRAEVIDÊNCIA: %s',
                (string) data_get($epistemicEvidence, 'counter_evidence.status', 'unknown'),
            );
            foreach (array_slice((array) data_get($epistemicEvidence, 'counter_evidence.slots', []), 0, 3) as $slot) {
                if (! is_array($slot)) {
                    continue;
                }
                $lines[] = sprintf(
                    '  - claim=%s status=%s refs_against=%d',
                    mb_substr((string) ($slot['claim'] ?? ''), 0, 120),
                    (string) ($slot['status'] ?? 'unknown'),
                    count((array) ($slot['refs_against'] ?? [])),
                );
            }
            foreach (array_slice((array) data_get($epistemicEvidence, 'claim_citations.items', []), 0, 5) as $claim) {
                if (! is_array($claim)) {
                    continue;
                }
                foreach (array_slice((array) ($claim['citations'] ?? []), 0, 2) as $citation) {
                    if (! is_array($citation)) {
                        continue;
                    }
                    $lines[] = sprintf(
                        '  - claim=%s ref=%s content_version=%s bytes=%d-%d',
                        mb_substr((string) ($claim['claim'] ?? ''), 0, 120),
                        (string) ($citation['span_ref'] ?? ''),
                        substr((string) ($citation['content_version'] ?? ''), 0, 16),
                        (int) ($citation['start'] ?? 0),
                        (int) ($citation['end'] ?? 0),
                    );
                }
            }
        }

        // MAXC-05 — sufficiency block: named gaps + expand:* handles so the
        // external agent has ACTION for "brain didn't find X" instead of silence.
        // Only rendered when the pack actually carries a sufficiency block AND
        // it flags `not_enough_context=true` — otherwise the pack stays quiet
        // (progressive disclosure invariant of the hook: no chatter when covered).
        $sufficiency = (array) ($pack['sufficiency'] ?? []);
        if (($sufficiency['present'] ?? false) === true
            && ($sufficiency['not_enough_context'] ?? false) === true) {
            $missing = array_values(array_filter(
                (array) ($sufficiency['missing_essential'] ?? []),
                static fn ($m): bool => is_array($m) && ($m['value'] ?? '') !== '',
            ));
            $handles = array_values(array_filter(
                (array) ($sufficiency['handles'] ?? []),
                static fn ($h): bool => is_string($h) && $h !== '',
            ));

            if ($missing !== [] || $handles !== []) {
                $lines[] = '';
                $lines[] = '## Suficiência';
                $lines[] = '- not_enough_context=true — o cérebro entregou pack mas SEM cobertura para 1+ faceta essencial da tarefa (progressive disclosure).';
                foreach (array_slice($missing, 0, 8) as $item) {
                    $lines[] = sprintf(
                        '- falta: %s=%s → use handle `%s`',
                        (string) ($item['type'] ?? '?'),
                        (string) ($item['value'] ?? '?'),
                        (string) ($item['handle'] ?? ('expand:'.($item['type'] ?? '?').':'.($item['value'] ?? '?'))),
                    );
                }
                if ($handles !== []) {
                    $lines[] = '- expand_handles: '.implode(', ', array_slice($handles, 0, 12));
                }
                $lines[] = '- workflow: chame de novo o context pack passando os handles listados (progressive disclosure), NUNCA invente conteúdo.';
            }
        }

        return implode("\n", $lines);
    }

    public function formatRefutationMatch(mixed $ref): string
    {
        if (! is_array($ref)) {
            return (string) $ref;
        }

        $title = trim((string) ($ref['title'] ?? ''));
        $strength = data_get($ref, 'refutation_strength.strength');
        $denominator = data_get($ref, 'refutation_strength.denominator');
        $strengthNumeric = AiValueNormalizer::finiteFloatOrNull($strength);
        $denominatorNumeric = AiValueNormalizer::finiteFloatOrNull($denominator);
        if ($strengthNumeric !== null && $denominatorNumeric !== null) {
            return sprintf('%s (refutation_strength=%.4f, denominator=%d)', $title, $strengthNumeric, (int) $denominatorNumeric);
        }

        return $title;
    }
}
