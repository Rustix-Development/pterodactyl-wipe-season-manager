<?php

use Illuminate\Support\Facades\Route;
use RustixDev\WipeSeasonManager\Http\Controllers\PlanController;

// Every route needs ext.wipe-season-manager.manage (owners and admins always have it).
Route::get('/plans', [PlanController::class, 'index']);
Route::post('/plans', [PlanController::class, 'store']);
Route::put('/plans/{plan}', [PlanController::class, 'update'])->whereNumber('plan');
Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->whereNumber('plan');
Route::post('/plans/{plan}/run', [PlanController::class, 'run'])->whereNumber('plan');
Route::post('/plans/{plan}/skip', [PlanController::class, 'skip'])->whereNumber('plan');
Route::post('/runs/{run}/cancel', [PlanController::class, 'cancel'])->whereNumber('run');
