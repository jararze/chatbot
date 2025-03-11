<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Truck extends Model
{
    use hasFactory;
    protected $guarded = [];

    protected $casts = [
        'last_maintenance' => 'date',
    ];

}
