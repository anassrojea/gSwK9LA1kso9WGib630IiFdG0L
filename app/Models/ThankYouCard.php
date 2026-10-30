<?php

namespace App\Models;

use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\InvitationConfigValidator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * The event's thank-you card (phase 8D, plan section 13.2): a config of schema
 * v2 with kind "thank_you", validated on every save like an invitation.
 * Guests see it at /t/{token}, and on /i/{token} after the event when
 * show_on_invite (App\Services\Keepsakes\ThankYouCards).
 */
class ThankYouCard extends Model
{
    use HasUuids;

    protected $fillable = ['event_id', 'config', 'show_on_invite', 'published_at'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'config_version' => 'integer',
            'show_on_invite' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ThankYouCard $card) {
            if ($card->isDirty('config')) {
                $config = $card->config ?? [];
                app(InvitationConfigValidator::class)->assertValid($config);
                if (InvitationConfig::kind($config) !== 'thank_you') {
                    throw ValidationException::withMessages(['config.kind' => 'A thank-you card has kind "thank_you".']);
                }
                $card->config_version = $config['version'];
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function renderConfig(): array
    {
        return InvitationConfig::upgrade($this->config ?? []);
    }
}
