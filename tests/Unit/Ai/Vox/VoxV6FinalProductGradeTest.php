<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxIntentExtractor;
use App\Services\Ai\Vox\VoxPromptCompiler;
use App\Services\Ai\Vox\VoxPromptPolisher;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

/**
 * V6-FPG-B · Atlas Vox Final Product Grade.
 *
 * 6 cenários reais que provam que o compiler entrega prompt forte para
 * Codex/Claude/Atlas e mantém Melhorar minimalista. Cada teste exige
 * `selfCheck.score >= 0.8` e zero `lost_negations`.
 *
 * NUNCA chama LLM. NUNCA toca rede. Determinístico.
 */
final class VoxV6FinalProductGradeTest extends TestCase
{
    private function pipe(string $voice): array
    {
        $polisher = new VoxPromptPolisher();
        $extractor = new VoxIntentExtractor($polisher);
        $compiler = new VoxPromptCompiler();

        $extracted = $extractor->extract(
            ['text' => $voice, 'session_id' => 's', 'transcript_id' => 't'],
            [],
        );
        $compiled = $compiler->compile($voice, [
            'goal' => $extracted['goal'],
            'constraints' => $extracted['constraints'],
            'provider_hint' => $extracted['provider_hint'],
            'executor_hint' => $extracted['executor_hint'],
            'output_format' => $extracted['output_format'],
            'context_refs' => $extracted['context_refs'],
            'risk_class' => $extracted['risk_class'],
            'risk_markers' => $extracted['risk_markers'],
            'normalised_text' => $extracted['normalised_text'],
        ]);
        return ['extracted' => $extracted, 'compiled' => $compiled];
    }

    private function assertHighQuality(array $out, string $context): void
    {
        $sc = $out['compiled']['quality_self_check'] ?? null;
        $this->assertIsArray($sc, "[$context] selfCheck deveria estar embutido no compile()");
        $this->assertGreaterThanOrEqual(
            0.8,
            $sc['score'],
            "[$context] score deveria ser ≥ 0.8 — obtido {$sc['score']} · issues=".implode(',', $sc['issues']),
        );
        $this->assertSame(
            [],
            $sc['lost_negations'],
            "[$context] negações perdidas: ".implode('; ', $sc['lost_negations']),
        );
        $this->assertTrue($sc['has_goal'], "[$context] objetivo ausente");
        $this->assertTrue($sc['has_expected_output'], "[$context] saída esperada ausente");
    }

    // ── 1. Fala bagunçada sobre código ───────────────────────────────

    public function test_messy_code_voice_becomes_strong_prompt(): void
    {
        $voice = 'eh tipo assim, o codex tem que investigar o vox compiler aí, '
            .'tipo entender por que o teste tá vermelho, mas não toque no '
            .'VoxEvidenceService, beleza, e nada de provider pago';
        $out = $this->pipe($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('codex_cli', $out['extracted']['provider_hint']);
        $this->assertSame('diagnostic', $out['extracted']['output_format']);
        $this->assertSame(VoxSchema::RISK_R1, $out['extracted']['risk_class']);
        $this->assertStringContainsString('Codex', $prompt);
        $this->assertStringContainsString('use `rg`', $prompt);
        $this->assertStringContainsString('## Restrições', $prompt);
        $this->assertStringContainsString('## O que NÃO fazer', $prompt);
        $this->assertHighQuality($out, 'messy_code');
    }

    // ── 2. Pedido puro de análise (sem editar) ───────────────────────

    public function test_pure_analysis_request_locks_read_only(): void
    {
        $voice = 'só analisa o módulo de hotkey e me diz por que está '
            .'falhando na inicialização, sem alterar nada';
        $out = $this->pipe($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('diagnostic', $out['extracted']['output_format']);
        $this->assertSame(VoxSchema::RISK_R1, $out['extracted']['risk_class']);
        // Não-edição é veto duro.
        $this->assertStringContainsString(
            'Não edite arquivo, não rode comando.',
            $prompt,
        );
        // "só analisa" virou constraint preservada.
        $hasSo = false;
        foreach ($out['extracted']['constraints'] as $c) {
            if (mb_stripos($c, 'só analisa') !== false || mb_stripos($c, 'so analisa') !== false) {
                $hasSo = true;
                break;
            }
        }
        $this->assertTrue($hasSo, 'constraint "só analisa" deveria estar em constraints[]');
        $this->assertHighQuality($out, 'pure_analysis');
    }

    // ── 3. Pedido explícito para Claude (plano, não editar ainda) ────

    public function test_claude_plan_request_yields_claude_template_and_plan_format(): void
    {
        $voice = 'Claude, faz um plano de refator para o overlay sem mexer '
            .'no kernel, e não implementa ainda';
        $out = $this->pipe($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('claude_cli', $out['extracted']['provider_hint']);
        $this->assertSame('plan', $out['extracted']['output_format']);
        $this->assertStringContainsString('plan mode', $prompt);
        // "não implementa ainda" deve sobreviver inteiro.
        $hasDefer = false;
        foreach ($out['extracted']['constraints'] as $c) {
            if (mb_stripos($c, 'não implementa') !== false || mb_stripos($c, 'nao implementa') !== false) {
                $hasDefer = true;
                break;
            }
        }
        $this->assertTrue($hasDefer, 'defer "não implementa ainda" deveria estar em constraints[]');
        $this->assertStringContainsString('builtin.intent_compile.claude_cli.plan.pt-br', $out['compiled']['compiled_prompt_template']);
        $this->assertHighQuality($out, 'claude_plan');
    }

    // ── 4. Pedido explícito para Codex (diff curto) ──────────────────

    public function test_codex_diff_request_yields_codex_template_and_diff_format(): void
    {
        $voice = 'Codex, aplica um diff curto pra corrigir o bug do useVoxOverlay '
            .'sem refatorar nada e sem instalar pacote novo';
        $out = $this->pipe($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('codex_cli', $out['extracted']['provider_hint']);
        $this->assertSame('diff', $out['extracted']['output_format']);
        $this->assertSame(VoxSchema::RISK_R2, $out['extracted']['risk_class']);
        $this->assertStringContainsString('builtin.intent_compile.codex_cli.diff.pt-br', $out['compiled']['compiled_prompt_template']);
        // Codex deve ler antes de editar.
        $this->assertStringContainsString('leia o código relevante', $prompt);
        $this->assertHighQuality($out, 'codex_diff');
    }

    // ── 5. Múltiplas restrições simultâneas ──────────────────────────

    public function test_multiple_constraints_are_all_preserved_as_vetoes(): void
    {
        $voice = 'investiga o erro no AtlasAiVoxController mas não toque '
            .'no VoxEvidenceService, sem API paga, sem dependência nova, '
            .'antes de tudo confirma o schema, e nada de deploy';
        $out = $this->pipe($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        // Espera ≥ 3 constraints capturadas.
        $this->assertGreaterThanOrEqual(
            3,
            count($out['extracted']['constraints']),
            'constraints capturadas: '.json_encode($out['extracted']['constraints']),
        );
        // "deploy" é R4 marker (force-veto destrutivo).
        // O importante: cada constraint da voz aparece como veto literal.
        $voiceCues = [
            'não toque', 'sem API paga', 'sem dependência',
            'antes de tudo', 'nada de deploy',
        ];
        foreach ($voiceCues as $cue) {
            // O cue tem que aparecer em ALGUMA forma no prompt
            // (constraints ou veto literal). Buscamos substring de palavra-chave.
            $token = preg_replace('/\s+.*/u', '', $cue);
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($token, '/').'/i',
                $prompt,
                "voz mencionou '$cue' mas token '$token' sumiu do compiled_prompt",
            );
        }
        $this->assertHighQuality($out, 'multi_constraints');
    }

    // ── 6. Pedido simples permanece simples (não vira mega-prompt) ───

    public function test_simple_dictation_stays_minimal(): void
    {
        $voice = 'lembrete: comprar café amanhã';
        $polish = (new VoxPromptPolisher())->polish($voice);

        // Polisher NÃO vira intent_compile. Sem seções, sem markdown.
        $this->assertSame('Lembrete: comprar café amanhã.', $polish['compiled_prompt']);
        $this->assertStringNotContainsString('## ', $polish['compiled_prompt']);
        $this->assertStringNotContainsString('Objetivo', $polish['compiled_prompt']);
        $this->assertStringNotContainsString('Saída esperada', $polish['compiled_prompt']);
        // Sem provider externo detectado.
        $this->assertSame('local', $polish['provider_hint']);
        // Sem constraints (nada negativo na voz).
        $this->assertSame([], $polish['constraints']);
        // Tamanho do output ≤ tamanho do input + margem mínima (não infla).
        $this->assertLessThanOrEqual(
            mb_strlen($voice) + 10,
            mb_strlen($polish['compiled_prompt']),
        );
    }

    // ── Bonus: Atlas interno como destinatário ───────────────────────

    public function test_atlas_internal_request_uses_atlas_template(): void
    {
        $voice = 'Atlas Dev, me explica como funciona o VoxEvidenceService '
            .'sem chamar provider externo';
        $out = $this->pipe($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('atlas', $out['extracted']['provider_hint']);
        $this->assertStringContainsString('Atlas Dev / Atlas AI / Kernel local', $prompt);
        $this->assertStringContainsString('docs/contracts', $prompt);
        $this->assertStringContainsString('builtin.intent_compile.atlas', $out['compiled']['compiled_prompt_template']);
        $this->assertHighQuality($out, 'atlas_internal');
    }
}
