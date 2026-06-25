<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OptimizationController;

Route::get('/', [OptimizationController::class, 'index']);
Route::post('/optimize', [OptimizationController::class, 'optimize'])->name('optimize');