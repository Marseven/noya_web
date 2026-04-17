<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'amount',
        'merchant_id',
        'source_merchant_id',
        'destination_merchant_id',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = 'ORD-' . strtoupper(Str::random(10));
            }
        });
    }

    /**
     * Get the merchant that owns the order.
     * Legacy alias to destination actor.
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Source actor that emitted the preorder.
     */
    public function sourceMerchant()
    {
        return $this->belongsTo(Merchant::class, 'source_merchant_id');
    }

    /**
     * Destination actor that receives the preorder.
     */
    public function destinationMerchant()
    {
        return $this->belongsTo(Merchant::class, 'destination_merchant_id');
    }

    /**
     * Get the carts for the order.
     */
    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    /**
     * Get the payments for the order.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Delivery/status transition history for this order.
     */
    public function deliveryHistories()
    {
        return $this->hasMany(DeliveryHistory::class)->orderByDesc('changed_at');
    }

    /**
     * Calculate total amount from carts.
     */
    public function calculateAmount()
    {
        $total = $this->carts()->with('article')->get()->sum(function ($cart) {
            return $cart->quantity * ($cart->article->price ?? 0);
        });

        $this->amount = $total;
        $this->save();

        return $total;
    }

    /**
     * Get total paid amount.
     */
    public function getTotalPaidAmount()
    {
        return $this->payments()->where('status', 'PAID')->sum('amount');
    }

    /**
     * Check if order is fully paid.
     */
    public function isFullyPaid()
    {
        return $this->getTotalPaidAmount() >= $this->amount;
    }

    /**
     * Check if order is partially paid.
     */
    public function isPartiallyPaid()
    {
        $paidAmount = $this->getTotalPaidAmount();
        return $paidAmount > 0 && $paidAmount < $this->amount;
    }

    /**
     * Scope a query to only include paid orders.
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'PAID');
    }

    /**
     * Scope a query to only include pending orders.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'INIT');
    }
}
