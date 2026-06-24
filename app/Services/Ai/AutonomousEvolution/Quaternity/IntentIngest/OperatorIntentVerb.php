<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/**
 * The closed set of operator-intent verbs the deterministic extractor recognizes.
 */
enum OperatorIntentVerb: string
{
    case ADD = 'ADD';
    case REMOVE = 'REMOVE';
    case CHANGE = 'CHANGE';
    case FOCUS = 'FOCUS';
    case FORBID = 'FORBID';
    case ESCALATE = 'ESCALATE';
    case ASK = 'ASK';
    case OBSERVE = 'OBSERVE';
}
