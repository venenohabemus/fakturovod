<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mapping extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }
}
