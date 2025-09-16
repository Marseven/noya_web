<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockHistory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'stock_id',
        'article_id',
        'action_type',
        'last_stock',
        'new_stock',
    ];

    protected $casts = [
        'last_stock' => 'integer',
        'new_stock' => 'integer',
    ];

    /**
     * Get the stock that owns the history.
     */
    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Get the article that owns the history.
     */
    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Scope a query to only include add actions.
     */
    public function scopeAddActions($query)
    {
        return $query->whereIn('action_type', ['MANUALLY_ADD', 'AUTO_ADD']);
    }

    /**
     * Scope a query to only include withdraw actions.
     */
    public function scopeWithdrawActions($query)
    {
        return $query->whereIn('action_type', ['MANUALLY_WITHDRAW', 'AUTO_WITHDRAW']);
    }
}