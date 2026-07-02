<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function index()
    {
        return response()->json(Recipe::latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'cook_time' => 'required|integer|min:1',
            'ingredients' => 'required|array',
            'instructions' => 'required|string',
        ]);

        $recipe = Recipe::create($validated);

        return response()->json($recipe, 201);
    }

    public function destroy(Recipe $recipe)
    {
        $recipe->delete();
        return response()->json(['message' => 'Rețetă ștearsă!']);
    }

    public function random()
    {
        $randomRecipe = Recipe::inRandomOrder()->first();

        if (!$randomRecipe) {
            return response()->json(['message' => 'Nicio rețetă!'], 404);
        }

        return response()->json($randomRecipe);
    }
}
