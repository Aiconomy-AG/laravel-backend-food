<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $fillable = [
        'title',
        'cook_time',
        'ingredients',
        'instructions'
    ];

    protected $casts = [
        'ingredients' => 'array',
    ];
}
