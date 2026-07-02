<?php

use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

Route::get('recipes/random', [RecipeController::class, 'random'])->name('recipes.random');

Route::apiResource('recipes', RecipeController::class);