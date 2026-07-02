<?php

use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

//Route::get('random-recipe', [RecipeController::class, 'random'])->name('recipes.custom.random');

Route::apiResource('recipes', RecipeController::class);