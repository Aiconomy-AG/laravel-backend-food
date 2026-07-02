<?php

namespace App\Http\Controllers;

use App\Services\NutritionService;
use Illuminate\Http\Request;

class NutritionController extends Controller
{
    public function __construct(private NutritionService $nutritionService) {}

    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'ingredients' => 'required|array|min:1',
            'ingredients.*' => 'string',
        ]);

        $macros = $this->nutritionService->calculate($validated['ingredients']);

        return response()->json($macros);
    }
}
