<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\NutritionController;
use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

// Autentificare publică
Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

// Tot ce urmează necesită un token Sanctum valid (header Authorization: Bearer <token>)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('user', [AuthController::class, 'me']);

    // Ruta custom pentru rețeta random (TREBUIE să fie prima ca să evite 404)
    Route::get('/recipes/random-recipe', [RecipeController::class, 'random']);

    // Resursa automată (generează GET /recipes, POST /recipes, DELETE /recipes/{id})
    Route::apiResource('recipes', RecipeController::class);

    // Calcul de macros în timp real, fără a salva rețeta
    Route::post('nutrition/calculate', [NutritionController::class, 'calculate'])->name('nutrition.calculate');
});
