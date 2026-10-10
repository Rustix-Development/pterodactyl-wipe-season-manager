<?php

use Illuminate\Support\Facades\Route;
use RustixDev\WipeSeasonManager\Http\Controllers\AdminController;

Route::get('/overview', [AdminController::class, 'index']);
