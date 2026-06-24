<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * TacticalNegotiationLibrary — Chris Voss's tactical empathy applied to direct-response copy, the named
 * gap the master-seller blueprint found (the OS had a single stray reference).
 *
 * Voss's moves disarm resistance by making the reader feel UNDERSTOOD before they're asked: the accusation
 * audit (say their worst objection first), labeling ("it seems like you've been burned before"), calibrated
 * how/what questions (that make them argue your case), the No-oriented question (a "no" that means "yes"),
 * and the drive to "that's right". Distinct from CloseTacticsLibrary's closes — this is the negotiation
 * layer that lowers the guard. Same PatternLibrary shape → single scorer, indexed by SalesMomentPatternIndex
 * (objection/close/lead). Provider-free, no moral brake.
 */
class TacticalNegotiationLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'tactical_negotiation';
    }

    public function categories(): array
    {
        return ['tactical_empathy', 'calibrated_question', 'labeling', 'no_oriented', 'accusation_audit'];
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>,sales_moment:string,aggression:string}>
     */
    public function all(): array
    {
        return [
            ['key' => 'accusation_audit', 'name' => 'Accusation audit', 'category' => 'accusation_audit', 'weight' => 5, 'sales_moment' => 'objection', 'aggression' => 'standard',
                'trigger' => 'Verbalizar a pior acusação ANTES do leitor a desarma — ele não pode usar o que você já admitiu.',
                'lever' => '"Você deve estar pensando que isso é só mais um golpe pra pegar seu dinheiro…" — dito na voz hostil dele.',
                'markers' => ['you are probably thinking', 'you might be thinking i', 'just another scam', 'just trying to take your', 'you have every right to be skeptical', 'você deve estar pensando que', 'só mais um golpe', 'mais um querendo te enganar']],
            ['key' => 'labeling', 'name' => 'Labeling (nomear a emoção)', 'category' => 'labeling', 'weight' => 4, 'sales_moment' => 'objection', 'aggression' => 'standard',
                'trigger' => 'Nomear o medo/emoção do leitor o valida e baixa a carga — "ele me entende".',
                'lever' => '"Parece que você já se decepcionou antes", "dá pra ver que você está cansado de tentar".',
                'markers' => ['it seems like', 'it sounds like', 'it looks like you', 'you have been burned', 'i can tell you are', 'parece que você', 'dá pra ver que você', 'sei que você já', 'você está cansad']],
            ['key' => 'calibrated_question', 'name' => 'Pergunta calibrada (how/what)', 'category' => 'calibrated_question', 'weight' => 4, 'sales_moment' => 'close', 'aggression' => 'standard',
                'trigger' => 'Perguntas abertas "como/o que" fazem o leitor argumentar A FAVOR da mudança — ele se convence sozinho.',
                'lever' => '"O que mais um ano assim vai te custar?", "como você quer estar daqui a 90 dias?"',
                'markers' => ['what would it', 'what is it costing you', 'how are you supposed to', 'what happens if nothing', 'how do you want to', 'o que mais um ano', 'quanto isso te custa', 'como você quer estar', 'o que acontece se nada']],
            ['key' => 'no_oriented_question', 'name' => 'Pergunta orientada ao Não', 'category' => 'no_oriented', 'weight' => 4, 'sales_moment' => 'close', 'aggression' => 'aggressive',
                'trigger' => 'Um "não" dá ao leitor a sensação de controle — e um "não" que significa "sim" fecha sem pressão.',
                'lever' => '"Seria loucura dar uma única chance?", "você é contra finalmente se sentir bem de novo?"',
                'markers' => ['would it be ridiculous', 'would it be crazy', 'are you against', 'is it a bad idea to', 'would you be opposed', 'seria loucura', 'você é contra', 'seria absurdo', 'tem algo de errado em']],
            ['key' => 'tactical_empathy', 'name' => 'Empatia tática', 'category' => 'tactical_empathy', 'weight' => 4, 'sales_moment' => 'lead', 'aggression' => 'standard',
                'trigger' => 'Demonstrar que entende o mundo do leitor (não só a dor, o RANCOR) cria confiança profunda.',
                'lever' => '"Quando você já tentou de tudo, mais uma promessa soa quase como insulto. Eu entendo."',
                'markers' => ['i get it', 'i understand why you', 'when you have tried everything', 'it feels almost insulting', 'i know how exhausting', 'eu entendo', 'quando você já tentou de tudo', 'soa quase como insulto', 'sei o quanto é exaustivo']],
            ['key' => 'thats_right_anchor', 'name' => 'Âncora "é isso mesmo"', 'category' => 'tactical_empathy', 'weight' => 3, 'sales_moment' => 'agitation', 'aggression' => 'standard',
                'trigger' => 'Levar o leitor a um "é exatamente isso" (não "você tem razão") = ele sente que foi COMPREENDIDO, abre pra solução.',
                'lever' => 'Resumir a dor dele tão bem que ele pensa "é exatamente isso que eu sinto".',
                'markers' => ['that is exactly', 'that is right', 'sound familiar', 'if that is you', 'é exatamente isso', 'é isso mesmo', 'soa familiar', 'se isso é você']],
        ];
    }
}
