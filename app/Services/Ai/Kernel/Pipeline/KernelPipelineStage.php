<?php

namespace App\Services\Ai\Kernel\Pipeline;

enum KernelPipelineStage: string
{
    case Input = 'input';
    case OperationEnvelope = 'operation_envelope';
    case Intent = 'intent';
    case Decide = 'decide';
    case DecisionReceipt = 'decision_receipt';
    case Domain = 'domain';
    case Context = 'context';
    case Policy = 'policy';
    case Runtime = 'runtime';
    case Gate = 'gate';
    case Repair = 'repair';
    case Evidence = 'evidence';
    case Learning = 'learning';
    case Output = 'output';

    /**
     * @return array<int,self>
     */
    public static function ordered(): array
    {
        return [
            self::Input,
            self::OperationEnvelope,
            self::Intent,
            self::Decide,
            self::DecisionReceipt,
            self::Domain,
            self::Context,
            self::Policy,
            self::Runtime,
            self::Gate,
            self::Repair,
            self::Evidence,
            self::Learning,
            self::Output,
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function orderedValues(): array
    {
        return array_map(
            fn (self $stage): string => $stage->value,
            self::ordered(),
        );
    }
}
