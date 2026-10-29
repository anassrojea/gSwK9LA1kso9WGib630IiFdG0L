<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'entity', 'entity_id', 'data', 'at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(string $action, Model $entity, array $data = [], ?User $actor = null): self
    {
        return static::create([
            'actor_id' => $actor?->getKey() ?? auth()->id(),
            'action' => $action,
            'entity' => class_basename($entity),
            'entity_id' => (string) $entity->getKey(),
            'data' => $data ?: null,
            'at' => now(),
        ]);
    }
}
