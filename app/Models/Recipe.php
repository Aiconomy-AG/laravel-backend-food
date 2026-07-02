<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'cook_time',
        'ingredients',
        'instructions',
        'macros',
    ];

    protected $casts = [
        'ingredients' => 'array',
        'macros' => 'array',
    ];
}
