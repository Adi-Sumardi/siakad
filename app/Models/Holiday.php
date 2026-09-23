<?php

namespace App\Models;

use App\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasUlidKey;

    protected $fillable = ['date', 'label'];

    protected $casts = ['date' => 'date'];
}
