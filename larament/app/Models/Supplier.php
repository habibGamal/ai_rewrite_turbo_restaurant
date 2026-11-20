<?php

namespace App\Models;

use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'name',
        'phone',
        'address',
    ];
}
