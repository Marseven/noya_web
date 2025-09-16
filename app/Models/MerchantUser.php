<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MerchantUser extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'user_id',
    ];

    protected $primaryKey = ['merchant_id', 'user_id'];
    public $incrementing = false;

    /**
     * Get the merchant that owns the user.
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the user that belongs to the merchant.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}