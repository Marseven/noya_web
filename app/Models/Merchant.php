<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'entity_file',
        'other_document_file',
        'tel',
        'email',
        'merchant_parent_id',
        'status',
        'type',
        'lat',
        'long',
    ];

    protected $casts = [
        'lat' => 'decimal:8',
        'long' => 'decimal:8',
    ];

    /**
     * Get the parent merchant.
     */
    public function parent()
    {
        return $this->belongsTo(Merchant::class, 'merchant_parent_id');
    }

    /**
     * Get the child merchants.
     */
    public function children()
    {
        return $this->hasMany(Merchant::class, 'merchant_parent_id');
    }

    /**
     * Get the users for the merchant.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'merchant_users');
    }

    /**
     * Get the articles for the merchant.
     */
    public function articles()
    {
        return $this->hasMany(Article::class);
    }

    /**
     * Get the stocks for the merchant.
     */
    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Get the orders for the merchant.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Scope a query to only include approved merchants.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'APPROVED');
    }

    /**
     * Scope a query to only include active merchants.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['APPROVED']);
    }
}