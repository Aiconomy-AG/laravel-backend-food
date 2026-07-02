<?php

use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

// 1. Rutele specifice / custom se pun MEREU primele:
Route::get('/recipes/random-recipe', [RecipeController::class, 'random']);

// 2. Resursa se pune ultima:
Route::apiResource('recipes', RecipeController::class);