<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NutritionService
{
    /**
     * USDA nutrient numbers we care about, keyed by the field name we expose.
     *
     * @var array<string, string>
     */
    private const NUTRIENT_NUMBERS = [
        'calories' => '208',
        'protein_g' => '203',
        'fat_g' => '204',
        'carbohydrates_g' => '205',
        'sugar_g' => '269',
    ];

    /**
     * Calculate aggregated macros for a list of free-text ingredients
     * (e.g. "2 eggs", "100g flour") by matching each one against the
     * USDA FoodData Central database and summing the per-100g values of
     * the best match. This is an approximation: quantities/units in the
     * ingredient text are not converted to grams, so it should be read as
     * "roughly one serving of each ingredient", not a precise gram count.
     *
     * @param  array<int, string>  $ingredients
     * @return array{calories: ?float, protein_g: ?float, carbohydrates_g: ?float, fat_g: ?float, sugar_g: ?float, items: array}
     */
    public function calculate(array $ingredients): array
    {
        $ingredients = array_values(array_filter(array_map('trim', $ingredients)));

        if (empty($ingredients)) {
            return $this->emptyResult();
        }

        $cacheKey = 'nutrition:usda:'.md5(implode('|', $ingredients));

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($ingredients) {
            $items = array_values(array_filter(
                array_map(fn (string $ingredient) => $this->lookup($ingredient), $ingredients)
            ));

            return [
                'calories' => $this->sumField($items, 'calories'),
                'protein_g' => $this->sumField($items, 'protein_g'),
                'carbohydrates_g' => $this->sumField($items, 'carbohydrates_g'),
                'fat_g' => $this->sumField($items, 'fat_g'),
                'sugar_g' => $this->sumField($items, 'sugar_g'),
                'items' => $items,
            ];
        });
    }

    /**
     * Search USDA FoodData Central for the best match of a single
     * ingredient and return its per-100g macros, or null on no match/error.
     */
    private function lookup(string $ingredient): ?array
    {
        $apiKey = config('services.usda.key');

        // Strip a leading quantity/unit like "2", "100g", "1 cup" so the
        // search term is closer to a plain food name.
        $searchTerm = preg_replace(
            '/^\s*\d+[.,]?\d*\s*(g|kg|ml|l|cup|cups|tbsp|tsp|oz|lb|lbs|buc|bucati|bucăți)?\s*/iu',
            '',
            $ingredient
        );
        $searchTerm = trim($searchTerm) ?: $ingredient;

        $response = Http::get('https://api.nal.usda.gov/fdc/v1/foods/search', [
            'api_key' => $apiKey,
            'query' => $searchTerm,
            'pageSize' => 10,
            'dataType' => 'Foundation,SR Legacy',
        ]);

        if ($response->failed()) {
            Log::warning('USDA FoodData Central request failed.', [
                'ingredient' => $ingredient,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $foods = $response->json('foods') ?? [];

        if (empty($foods)) {
            return null;
        }

        // USDA's relevance score doesn't prioritize simple/plain matches
        // (e.g. "milk" can rank "Crackers, milk" above "Milk, whole"), so
        // prefer a result whose description starts with the search term.
        $food = collect($foods)->first(
            fn (array $f) => str_starts_with(strtolower($f['description']), strtolower($searchTerm))
        ) ?? $foods[0];

        $item = [
            'name' => $food['description'],
            'source_ingredient' => $ingredient,
        ];

        foreach (self::NUTRIENT_NUMBERS as $field => $nutrientNumber) {
            $item[$field] = $this->nutrientValue($food['foodNutrients'] ?? [], $nutrientNumber);
        }

        return $item;
    }

    private function nutrientValue(array $foodNutrients, string $nutrientNumber): ?float
    {
        foreach ($foodNutrients as $nutrient) {
            if (($nutrient['nutrientNumber'] ?? null) === $nutrientNumber) {
                return (float) $nutrient['value'];
            }
        }

        return null;
    }

    private function sumField(array $items, string $field): ?float
    {
        $values = array_filter(
            array_map(fn (array $item) => $item[$field] ?? null, $items),
            fn ($value) => is_numeric($value)
        );

        if (empty($values)) {
            return null;
        }

        return round(array_sum($values), 1);
    }

    private function emptyResult(): array
    {
        return [
            'calories' => null,
            'protein_g' => null,
            'carbohydrates_g' => null,
            'fat_g' => null,
            'sugar_g' => null,
            'items' => [],
        ];
    }
}
