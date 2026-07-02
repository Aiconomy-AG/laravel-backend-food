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
     * Grams represented by one unit, for converting a quantity like
     * "100g" or "1 cup" into a multiplier over USDA's per-100g values.
     *
     * @var array<string, float>
     */
    private const GRAMS_PER_UNIT = [
        'g' => 1,
        'kg' => 1000,
        'ml' => 1,
        'l' => 1000,
        'cup' => 240,
        'cups' => 240,
        'tbsp' => 15,
        'tsp' => 5,
        'oz' => 28.3495,
        'lb' => 453.592,
        'lbs' => 453.592,
    ];

    /**
     * Words that usually signal a processed/preserved form of a food
     * (dried, juiced, candied, etc). Deprioritized in favor of the raw
     * ingredient a recipe is more likely to mean.
     *
     * @var array<int, string>
     */
    private const PROCESSED_FORM_HINTS = [
        'dried', 'juice', 'butter', 'candie', 'candy', 'dessert', 'cracker',
        'chip', 'powder', 'sauce', 'extract', 'concentrate', 'canned',
        'syrup', 'jam', 'jelly', 'dehydrated', 'sweetened',
    ];

    /**
     * Calculate aggregated macros for a list of free-text ingredients
     * (e.g. "2 eggs", "100g flour") by matching each one against the
     * USDA FoodData Central database and summing its macros, scaled by
     * the quantity found in the ingredient text.
     *
     * Quantity handling is approximate: a recognized weight/volume unit
     * (g, kg, ml, l, cup, tbsp, tsp, oz, lb) is converted to grams and
     * scaled against the per-100g USDA values. A bare count with no unit
     * (e.g. "2 eggs") is treated as "N reference 100g servings" since we
     * have no per-item gram weight to work with — good enough to make
     * quantity matter, not gram-accurate.
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

        $cacheKey = 'nutrition:usda:v2:'.md5(implode('|', $ingredients));

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
     * ingredient and return its macros scaled by quantity, or null on
     * no match/error.
     */
    private function lookup(string $ingredient): ?array
    {
        $apiKey = config('services.usda.key');

        [$searchTerm, $multiplier] = $this->parseQuantity($ingredient);

        $response = Http::get('https://api.nal.usda.gov/fdc/v1/foods/search', [
            'api_key' => $apiKey,
            'query' => $searchTerm,
            'pageSize' => 15,
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

        $food = $this->bestMatch($foods, $searchTerm);

        $item = [
            'name' => $food['description'],
            'source_ingredient' => $ingredient,
        ];

        foreach (self::NUTRIENT_NUMBERS as $field => $nutrientNumber) {
            $value = $this->nutrientValue($food['foodNutrients'] ?? [], $nutrientNumber);
            $item[$field] = $value !== null ? round($value * $multiplier, 2) : null;
        }

        return $item;
    }

    /**
     * Split an ingredient like "2 eggs" or "100g flour" into a plain-text
     * search term and a multiplier over USDA's per-100g nutrient values.
     *
     * @return array{0: string, 1: float}
     */
    private function parseQuantity(string $ingredient): array
    {
        $matched = preg_match(
            '/^\s*(\d+[.,]?\d*)\s*(g|kg|ml|l|cup|cups|tbsp|tsp|oz|lb|lbs)?\s*/iu',
            $ingredient,
            $matches
        );

        $searchTerm = $matched
            ? trim(mb_substr($ingredient, mb_strlen($matches[0])))
            : $ingredient;
        $searchTerm = $searchTerm !== '' ? $searchTerm : $ingredient;

        if (! $matched) {
            return [$searchTerm, 1.0];
        }

        $quantity = (float) str_replace(',', '.', $matches[1]);
        $unit = isset($matches[2]) ? strtolower($matches[2]) : null;

        if ($unit !== null && isset(self::GRAMS_PER_UNIT[$unit])) {
            $grams = $quantity * self::GRAMS_PER_UNIT[$unit];

            return [$searchTerm, $grams / 100];
        }

        // No recognized weight/volume unit: treat as "N whole items",
        // approximated as N reference 100g servings.
        return [$searchTerm, $quantity > 0 ? $quantity : 1.0];
    }

    /**
     * Pick the most sensible match from USDA's search results. Their
     * relevance score doesn't prioritize simple/plain matches (e.g.
     * "milk" can rank "Crackers, milk" above "Milk, whole"), so instead:
     * prefer descriptions that start with the search term, then prefer
     * "raw"/unprocessed forms over dried, juiced, candied, etc, then
     * prefer the shortest (usually plainest) description.
     */
    private function bestMatch(array $foods, string $searchTerm): array
    {
        $candidates = array_values(array_filter(
            $foods,
            fn (array $f) => str_starts_with(strtolower($f['description']), strtolower($searchTerm))
        ));

        if (empty($candidates)) {
            return $foods[0];
        }

        $raw = array_values(array_filter(
            $candidates,
            fn (array $f) => ! $this->looksProcessed($f['description'])
        ));

        if (! empty($raw)) {
            $candidates = $raw;
        }

        usort($candidates, fn (array $a, array $b) => strlen($a['description']) <=> strlen($b['description']));

        return $candidates[0];
    }

    private function looksProcessed(string $description): bool
    {
        $description = strtolower($description);

        foreach (self::PROCESSED_FORM_HINTS as $hint) {
            if (str_contains($description, $hint)) {
                return true;
            }
        }

        return false;
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
