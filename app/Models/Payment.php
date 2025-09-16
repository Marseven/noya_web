<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_id',
        'amount',
        'partner_name',
        'partner_fees',
        'total_amount',
        'status',
        'partner_reference',
        'callback_data',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'partner_fees' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'callback_data' => 'array',
    ];

    /**
     * Get the order that owns the payment.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Mark payment as paid and update order status.
     */
    public function markAsPaid()
    {
        $this->status = 'PAID';
        $this->save();

        // Update order status based on payment
        if ($this->order) {
            if ($this->order->isFullyPaid()) {
                $this->order->status = 'PAID';
            } elseif ($this->order->isPartiallyPaid()) {
                $this->order->status = 'PARTIALY_PAID';
            }
            $this->order->save();
        }

        return $this;
    }

    /**
     * Scope a query to only include paid payments.
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'PAID');
    }

    /**
     * Scope a query to only include pending payments.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'INIT');
    }
}