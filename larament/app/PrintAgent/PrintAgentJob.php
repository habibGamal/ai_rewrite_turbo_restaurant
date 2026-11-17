<?php

namespace App\PrintAgent;

use App\Models\Terminal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw ESC/POS bytes waiting for the Turbo Label app on a drawer's computer.
 *
 * @property int $id
 * @property int $terminal_id
 * @property string $kind receipt | drawer | test
 * @property string|null $printer
 * @property string $payload base64
 * @property string $status pending | sent | done | failed
 * @property string|null $error
 * @property Carbon|null $sent_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
class PrintAgentJob extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = [
        'terminal_id',
        'kind',
        'printer',
        'payload',
        'status',
        'error',
        'sent_at',
        'finished_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return BelongsTo<Terminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'receipt' => 'فاتورة',
            'drawer' => 'فتح الدرج',
            'test' => 'تجربة طباعة',
            default => $this->kind,
        };
    }
}
