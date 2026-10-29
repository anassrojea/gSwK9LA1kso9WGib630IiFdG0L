<?php

namespace App\Models;

use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\InvitationConfigValidator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invitation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['event_id', 'template_id', 'config', 'config_version', 'published_at', 'slug'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'config_version' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invitation $invitation) {
            $invitation->slug ??= Str::lower(Str::random(10));
        });

        // The JSON Schema is the authoritative check: no invalid config reaches the database.
        static::saving(function (Invitation $invitation) {
            if ($invitation->isDirty('config')) {
                app(InvitationConfigValidator::class)->assertValid($invitation->config ?? []);
                $invitation->config_version = $invitation->config['version'];
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * The config upgraded to the current schema version, for rendering.
     */
    public function renderConfig(): array
    {
        return InvitationConfig::upgrade($this->config ?? []);
    }
}
