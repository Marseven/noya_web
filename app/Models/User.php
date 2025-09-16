<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'first_name',
        'last_name',
        'email',
        'password',
        'status',
        'google_2fa_active',
        'google_2fa_secret',
        'google_2fa_recovery_codes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_2fa_secret',
        'google_2fa_recovery_codes',
    ];

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
            'google_2fa_active' => 'boolean',
            'google_2fa_recovery_codes' => 'array',
        ];
    }

    /**
     * Get the role that owns the user.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the merchants for the user.
     */
    public function merchants()
    {
        return $this->belongsToMany(Merchant::class, 'merchant_users');
    }

    /**
     * Check if user has a specific privilege.
     */
    public function hasPrivilege($privilegeName)
    {
        return $this->role && $this->role->hasPrivilege($privilegeName);
    }

    /**
     * Get user's full name.
     */
    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    /**
     * Scope a query to only include active users.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'APPROVED');
    }

    /**
     * Check if 2FA is enabled and properly configured.
     */
    public function is2FAEnabled()
    {
        return $this->google_2fa_active && !empty($this->google_2fa_secret);
    }
}
