<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property int|null $family_owner_id
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'family_owner_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function familyOwner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'family_owner_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function familyMembers(): HasMany
    {
        return $this->hasMany(self::class, 'family_owner_id');
    }

    /**
     * @return HasMany<FamilyInvite, $this>
     */
    public function sentFamilyInvites(): HasMany
    {
        return $this->hasMany(FamilyInvite::class, 'owner_id');
    }

    /**
     * @return HasOne<FamilyInvite, $this>
     */
    public function receivedFamilyInvite(): HasOne
    {
        return $this->hasOne(FamilyInvite::class, 'invited_user_id');
    }

    /**
     * Vagas restantes até o limite de 2 membros convidados, considerando
     * membros já aceitos e convites ainda pendentes.
     */
    public function remainingFamilySlots(): int
    {
        return max(0, 2 - ($this->familyMembers()->count() + $this->sentFamilyInvites()->count()));
    }
}
