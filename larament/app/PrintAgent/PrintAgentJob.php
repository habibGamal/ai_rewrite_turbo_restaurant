<?php

namespace App\PrintAgent;

use App\Models\Printer;
use App\Models\Terminal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw ESC/POS bytes waiting for the Turbo Label app on a shop computer.
 *
 * @property int $id
 * @property int|null $terminal_id
 * @property int|null $printer_id
 * @property string $kind receipt | drawer | kitchen | kitchen_changes | test
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
        'printer_id',
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

    /**
     * Named kitchenPrinter() instead of printer() to avoid colliding with the
     * string column $this->printer (the Windows printer name).
     *
     * @return BelongsTo<Printer, $this>
     */
    public function kitchenPrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'printer_id');
    }

    public function target(): ?PrintAgentTarget
    {
        if ($this->terminal_id !== null) {
            return $this->terminal;
        }

        if ($this->printer_id !== null) {
            return $this->kitchenPrinter;
        }

        return null;
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'receipt' => 'فاتورة',
            'drawer' => 'فتح درج النقود',
            'kitchen' => 'طلب مطبخ',
            'kitchen_changes' => 'تغييرات طلب مطبخ',
            'test' => 'تجربة طباعة',
            default => $this->kind,
        };
    }
}
