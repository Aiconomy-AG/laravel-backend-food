<?php

use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

Route::get('/recipes/random', [RecipeController::class, 'random']);
Route::apiResource('recipes', RecipeController::class);
