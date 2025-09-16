<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Stock extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'article_id',
        'stock',
        'last_action_type',
    ];

    protected $casts = [
        'stock' => 'integer',
    ];

    /**
     * Get the merchant that owns the stock.
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the article that owns the stock.
     */
    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Get the stock histories for the stock.
     */
    public function histories()
    {
        return $this->hasMany(StockHistory::class);
    }

    /**
     * Add stock and create history.
     */
    public function addStock($quantity, $actionType = 'MANUALLY_ADD')
    {
        $oldStock = $this->stock;
        $this->stock += $quantity;
        $this->last_action_type = $actionType;
        $this->save();

        // Create history record
        StockHistory::create([
            'stock_id' => $this->id,
            'article_id' => $this->article_id,
            'action_type' => $actionType,
            'last_stock' => $oldStock,
            'new_stock' => $this->stock,
        ]);

        return $this;
    }

    /**
     * Withdraw stock and create history.
     */
    public function withdrawStock($quantity, $actionType = 'MANUALLY_WITHDRAW')
    {
        $oldStock = $this->stock;
        $this->stock = max(0, $this->stock - $quantity);
        $this->last_action_type = $actionType;
        $this->save();

        // Create history record
        StockHistory::create([
            'stock_id' => $this->id,
            'article_id' => $this->article_id,
            'action_type' => $actionType,
            'last_stock' => $oldStock,
            'new_stock' => $this->stock,
        ]);

        return $this;
    }
}