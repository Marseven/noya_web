<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the users for the role.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the privileges for the role.
     */
    public function privileges()
    {
        return $this->belongsToMany(Privilege::class, 'role_privileges');
    }

    /**
     * Check if role has a specific privilege.
     */
    public function hasPrivilege($privilegeName)
    {
        return $this->privileges()->where('nom', $privilegeName)->where('is_active', true)->exists();
    }

    /**
     * Scope a query to only include active roles.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}