<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RolePrivilege extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'role_id',
        'privilege_id',
    ];

    protected $primaryKey = ['role_id', 'privilege_id'];
    public $incrementing = false;

    /**
     * Get the role that owns the privilege.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the privilege that belongs to the role.
     */
    public function privilege()
    {
        return $this->belongsTo(Privilege::class);
    }
}