<?php

declare(strict_types=1);

use App\Http\Controllers\AtlasCalendarBlockController;
use App\Http\Controllers\AtlasMemoryController;
use App\Http\Controllers\AtlasProjectBlockerController;
use App\Http\Controllers\AtlasProjectController;
use App\Http\Controllers\AtlasProjectPlanProposalController;
use App\Http\Controllers\AtlasRoutineController;
use App\Http\Controllers\AtlasTaskController;
use App\Http\Controllers\EngineeringProjectBlueprintController;
use Illuminate\Support\Facades\Route;

/**
 * Tasks schedule/API resource, routines, projects, plan proposals, calendar (full-pass routes split). Inside atlas.token.
 *
 * @return \Closure(): void
 */
return static function (): void {
    Route::post('/tasks/{task}/schedule', [AtlasTaskController::class, 'schedule']);
    Route::post('/tasks/{task}/defer', [AtlasTaskController::class, 'defer']);
    Route::post('/tasks/{task}/complete', [AtlasTaskController::class, 'complete']);
    Route::apiResource('tasks', AtlasTaskController::class)->only(['index', 'show', 'update']);
    Route::post('/routines/generate-due', [AtlasRoutineController::class, 'generateDue']);
    Route::get('/routines/{routine}/events', [AtlasRoutineController::class, 'events']);
    Route::post('/routines/{routine}/generate', [AtlasRoutineController::class, 'generate']);
    Route::apiResource('routines', AtlasRoutineController::class)->only(['index', 'store', 'show', 'update']);
    Route::get('/projects/review', [AtlasProjectController::class, 'reviewQueue']);
    Route::get('/projects/{project}/execution', [AtlasProjectController::class, 'execution']);
    Route::get('/projects/{project}/memory', [AtlasMemoryController::class, 'forProject']);
    Route::get('/projects/{project}/engineering/blueprint', [EngineeringProjectBlueprintController::class, 'show']);
    Route::post('/projects/{project}/engineering/blueprint/prepare', [EngineeringProjectBlueprintController::class, 'prepare']);
    Route::post('/projects/{project}/engineering/blueprint/create', [EngineeringProjectBlueprintController::class, 'create']);
    Route::post('/projects/{project}/engineering/blueprint/validate', [EngineeringProjectBlueprintController::class, 'validateBlueprint']);
    Route::post('/projects/{project}/engineering/blueprint/freeze', [EngineeringProjectBlueprintController::class, 'freeze']);
    Route::post('/projects/{project}/engineering/tasks/generate', [EngineeringProjectBlueprintController::class, 'generateTasks']);
    Route::post('/projects/{project}/execution/start', [AtlasProjectController::class, 'startExecution']);
    Route::post('/projects/{project}/recover', [AtlasProjectController::class, 'recover']);
    Route::get('/projects/{project}/events', [AtlasProjectController::class, 'events']);
    Route::get('/projects/{project}/steps', [AtlasProjectController::class, 'steps']);
    Route::get('/projects/{project}/blockers', [AtlasProjectBlockerController::class, 'index']);
    Route::post('/projects/{project}/blockers', [AtlasProjectBlockerController::class, 'store']);
    Route::post('/projects/{project}/blockers/{blocker}/resolve', [AtlasProjectBlockerController::class, 'resolve']);
    Route::post('/projects/{project}/blockers/{blocker}/task', [AtlasProjectBlockerController::class, 'convertToTask']);
    Route::post('/projects/{project}/review', [AtlasProjectController::class, 'reviewAction']);
    Route::patch('/projects/{project}/steps/{step}', [AtlasProjectController::class, 'updateStep']);
    Route::post('/projects/{project}/steps/{step}/activate', [AtlasProjectController::class, 'activateStep']);
    Route::get('/projects/{project}/plan/proposals', [AtlasProjectPlanProposalController::class, 'indexForProject']);
    Route::post('/projects/{project}/plan/propose', [AtlasProjectPlanProposalController::class, 'proposeForProject']);
    Route::post('/projects/{project}/plan/proposals/{proposal}/accept', [AtlasProjectPlanProposalController::class, 'acceptForProject']);
    Route::post('/projects/{project}/plan/proposals/{proposal}/reject', [AtlasProjectPlanProposalController::class, 'rejectForProject']);
    Route::post('/projects/{project}/plan/proposals/{proposal}/regenerate', [AtlasProjectPlanProposalController::class, 'regenerateForProject']);
    Route::post('/projects/{project}/plan', [AtlasProjectController::class, 'plan']);
    Route::post('/projects/{project}/next-action', [AtlasProjectController::class, 'nextAction']);
    Route::apiResource('projects', AtlasProjectController::class)->only(['index', 'store', 'show', 'update']);
    Route::post('/project-plan-proposals/{proposal}/accept', [AtlasProjectPlanProposalController::class, 'accept']);
    Route::post('/project-plan-proposals/{proposal}/reject', [AtlasProjectPlanProposalController::class, 'reject']);
    Route::post('/project-plan-proposals/{proposal}/regenerate', [AtlasProjectPlanProposalController::class, 'regenerate']);
    Route::get('/calendar/blocks', [AtlasCalendarBlockController::class, 'index']);
    Route::post('/calendar/blocks', [AtlasCalendarBlockController::class, 'store']);
    Route::patch('/calendar/blocks/{calendarBlock}', [AtlasCalendarBlockController::class, 'update']);
    Route::delete('/calendar/blocks/{calendarBlock}', [AtlasCalendarBlockController::class, 'destroy']);

};
