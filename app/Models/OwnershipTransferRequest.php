<?php

namespace App\Models;

use Database\Factories\OwnershipTransferRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnershipTransferRequest extends Model
{
    /** @use HasFactory<OwnershipTransferRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'from_user_id',
        'to_user_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
