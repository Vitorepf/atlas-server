<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorPatternDetection;
use Illuminate\Support\Str;

/**
 * Turns a detected recurring pattern into a real, valid SKILL.md — the artifact Atlas
 * auto-BUILDS. A skill here is MARKDOWN (instructions/automation the agent can use), not
 * executable code, so generation is safe by nature and fully grounded:
 *
 *   • DETERMINISTIC — built from the pattern's own fields + cited evidence; nothing is
 *     invented (no hallucinated steps, no fabricated capability);
 *   • TOOL-LESS BY DEFAULT — `allowed-tools` is empty, so a promoted skill grants the
 *     agent NO new powers until the operator explicitly adds them on review;
 *   • UNTRUSTED + PROPOSED tier — it announces itself as a draft awaiting the operator.
 *
 * The operator refines the body and promotes with an explicit --confirm; only then does
 * it reach the live vault.
 */
final class SkillScaffoldGenerator
{
    public const SCHEMA = 'atlas.operator_skill_scaffold.v1';

    /**
     * @return array{slug:string,title:string,description:string,markdown:string}
     */
    public function generate(OperatorPatternDetection $detection): array
    {
        $taxonomy = (string) ($detection->taxonomy_item_id ?: 'OP');
        $slug = Str::slug('op-'.strtolower($taxonomy).'-'.substr((string) $detection->pattern_id, 0, 8));
        // The summary embeds operator/LLM free text — sanitize it HARD before it touches the
        // SKILL.md: collapse newlines (kills YAML frontmatter-injection via multiline scalars)
        // and redact any shell/exfil/secret-path/tool-grant tokens (a skill must never carry a
        // destructive or capability-escalating instruction grounded in nothing).
        $summary = $detection->privacy_class === 'normal'
            ? $this->sanitizeSummary((string) $detection->summary)
            : 'recorrência '.$detection->privacy_class;
        $title = Str::limit('Automação: '.$summary, 80, '');
        $description = Str::limit('Skill rascunhada pelo Atlas a partir de um padrão recorrente seu ('.(int) $detection->occurrence_count.'x). '.$summary, 200, '');

        $markdown = $this->frontmatter([
            'name' => $slug,
            'description' => $description,
            'tier' => 'proposed',
            'trust' => 'untrusted',
            'allowed-tools' => '',
            'metadata' => [
                'schema_version' => self::SCHEMA,
                'source' => 'operator_pattern_detector',
                'pattern_id' => (string) $detection->pattern_id,
                'pattern_kind' => (string) $detection->kind,
                'occurrence_count' => (int) $detection->occurrence_count,
                'confidence' => round((float) $detection->confidence, 3),
            ],
        ]).$this->body($detection, $title, $summary, $slug);

        return ['slug' => $slug, 'title' => $title, 'description' => $description, 'markdown' => $markdown];
    }

    /**
     * @param  array<string,mixed>  $fm
     */
    private function frontmatter(array $fm): string
    {
        $lines = ['---'];
        foreach ($fm as $key => $value) {
            if (is_array($value)) {
                $lines[] = $key.':';
                foreach ($value as $k => $v) {
                    $lines[] = '  '.$k.': '.$this->scalar($v);
                }

                continue;
            }
            $lines[] = $key.': '.$this->scalar($value);
        }
        $lines[] = '---';

        return implode("\n", $lines)."\n\n";
    }

    /**
     * Collapse all whitespace + redact dangerous content from the attacker-reachable summary.
     * This is the single chokepoint that makes the generated skill safe: no newline can reach
     * the YAML frontmatter, and no shell/exfil/tool-grant instruction can reach the body.
     */
    private function sanitizeSummary(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        $danger = '/(~\/\.ssh|id_rsa|rm\s+-rf|\bcurl\b|\bwget\b|\bnc\b|\/etc\/passwd|allowed[_-]tools|trust\s*:|tier\s*:|\$\(|`|\bbase64\b|\/dev\/tcp|\bsudo\b|\beval\b)/i';
        if (preg_match($danger, $s) === 1) {
            return '[recorrência redigida — conteúdo potencialmente perigoso ou sensível]';
        }

        return Str::limit($s, 300, '');
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        // Defense-in-depth: never let a newline/control char reach a YAML scalar (a multi-line
        // scalar would be re-read as injected top-level keys by the naive frontmatter parsers).
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));
        // Quote anything with YAML-special chars or leading/trailing space; escape quotes.
        if ($s === '' || preg_match('/[:#\-\[\]{}",\n]/', $s) === 1 || trim($s) !== $s) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';
        }

        return $s;
    }

    private function body(OperatorPatternDetection $detection, string $title, string $summary, string $slug): string
    {
        $evidence = is_array($detection->evidence) ? $detection->evidence : [];
        $dates = [];
        foreach (array_slice($evidence, 0, 8) as $row) {
            $when = (string) ($row['occurred_at'] ?? '');
            if ($when !== '') {
                $dates[] = '- '.Str::limit($when, 19, '');
            }
        }
        $evidenceBlock = $dates === [] ? '- (occurrences recorded in the pattern ledger)' : implode("\n", $dates);

        return <<<MD
        # {$title}

        > Rascunho **auto-gerado pelo Atlas** a partir de um padrão recorrente no seu trabalho.
        > Revise, refine os passos, e promova quando quiser. Nada aqui está ativo até você promover.

        ## O que o Atlas notou
        Você repetiu **{$detection->occurrence_count}x** (em {$detection->window_days} dias): {$summary}.
        Confiança da detecção: {$detection->confidence}.

        ## Automação proposta (preencha/ajuste)
        Esta skill padroniza a tarefa acima. Descreva abaixo os passos que você quer que o Atlas siga
        quando esse padrão aparecer — o scaffold é um ponto de partida ancorado no que foi observado,
        não uma sequência inventada.

        1. (passo 1 — defina)
        2. (passo 2 — defina)

        ## Evidência (ocorrências reais)
        {$evidenceBlock}

        ## Segurança
        - `allowed-tools` está **vazio**: esta skill NÃO concede nenhuma ferramenta nova ao agente até você adicionar explicitamente.
        - Promover para o vault vivo: `php artisan atlas:ai:operator-skill approve {$slug} --confirm`
        - Rejeitar: `php artisan atlas:ai:operator-skill reject {$slug}`

        MD;
    }
}
