<?php

use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

// Ruta custom pentru rețeta random (TREBUIE să fie prima ca să evite 404)
Route::get('/recipes/random-recipe', [RecipeController::class, 'random']);

// Resursa automată (generează GET /recipes, POST /recipes, DELETE /recipes/{id})
Route::apiResource('recipes', RecipeController::class);