<?php

use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

// Folosim un grup explicit. Asta îi spune lui Laravel că cele două rute sunt complet separate.
Route::controller(RecipeController::class)->group(function () {
    // Înregistrăm ruta random cu un nume intern complet diferit și izolat
    Route::get('recipes/random', 'random')->name('recipes.get.random');
});

// Resursa standard rămâne jos
Route::apiResource('recipes', RecipeController::class);