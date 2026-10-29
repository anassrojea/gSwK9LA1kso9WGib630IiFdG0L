<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * org_members: composite primary key (org_id, user_id), role owner | staff | door.
 */
class OrgMember extends Pivot
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';

    public const ROLE_STAFF = 'staff';

    public const ROLE_DOOR = 'door';

    protected $table = 'org_members';

    public $incrementing = false;

    protected $fillable = ['org_id', 'user_id', 'role'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
