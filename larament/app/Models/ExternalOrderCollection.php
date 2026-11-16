<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTerminalSession;
use Illuminate\Database\Eloquent\Model;

class ExternalOrderCollection extends Model
{
    use BelongsToTerminalSession;

    protected $guarded = [];
}
