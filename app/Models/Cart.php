<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cart extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'article_id',
        'quantity',
        'order_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    /**
     * Get the article that owns the cart.
     */
    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Get the order that owns the cart.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the total price for this cart item.
     */
    public function getTotalPrice()
    {
        return $this->quantity * ($this->article->price ?? 0);
    }

    /**
     * Update quantity and recalculate order amount.
     */
    public function updateQuantity($quantity)
    {
        $this->quantity = $quantity;
        $this->save();

        // Recalculate order amount
        if ($this->order) {
            $this->order->calculateAmount();
        }

        return $this;
    }
}