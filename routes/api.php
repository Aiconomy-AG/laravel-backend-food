<?php

use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

// Forțăm toate rutele să aibă prefixul api/ și numele api.recipes
Route::group(['prefix' => 'api', 'as' => 'api.'], function () {

    // Ruta de random complet izolată
    Route::get('recipes/random', [RecipeController::class, 'random']);

    // Resursa standard de rețete
    Route::apiResource('recipes', RecipeController::class);

});