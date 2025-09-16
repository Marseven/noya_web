<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Privilege extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'nom',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the roles that have this privilege.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_privileges');
    }

    /**
     * Scope a query to only include active privileges.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}