<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGeneration extends Model
{
    use HasFactory, HasUuids;

    public const KINDS = ['story', 'poem', 'clean'];

    protected $fillable = ['event_id', 'user_id', 'kind', 'input', 'output', 'model', 'cost_cents', 'status', 'error', 'result'];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['input' => 'array', 'result' => 'array', 'cost_cents' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function finished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }
}
