<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to join an organization's team (role staff | door) or to
 * edit one of its events as the client host (role client, event_id set).
 * The link carries the token; only its SHA-256 is stored. Expires after
 * config('org.invitation_days'). Handled by App\Services\Org\OrgTeam.
 */
class OrgInvitation extends Model
{
    use HasUuids;

    public const ROLE_CLIENT = 'client';

    public const ROLES = [OrgMember::ROLE_STAFF, OrgMember::ROLE_DOOR, self::ROLE_CLIENT];

    protected $fillable = ['org_id', 'event_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_by', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isClient(): bool
    {
        return $this->role === self::ROLE_CLIENT;
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<OrgInvitation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
