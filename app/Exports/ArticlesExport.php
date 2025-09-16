<?php

namespace App\Exports;

use App\Models\Article;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\Exportable;
use Carbon\Carbon;

class ArticlesExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $fromDate;
    protected $toDate;
    protected $merchantId;
    protected $isActive;

    public function __construct($fromDate = null, $toDate = null, $merchantId = null, $isActive = null)
    {
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->merchantId = $merchantId;
        $this->isActive = $isActive;
    }

    public function query()
    {
        $query = Article::with(['merchant', 'stocks'])->withTrashed();

        if ($this->fromDate) {
            $query->where('created_at', '>=', Carbon::parse($this->fromDate));
        }

        if ($this->toDate) {
            $query->where('created_at', '<=', Carbon::parse($this->toDate)->endOfDay());
        }

        if ($this->merchantId) {
            $query->where('merchant_id', $this->merchantId);
        }

        if ($this->isActive !== null) {
            $query->where('is_active', $this->isActive);
        }

        return $query->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Price',
            'Merchant',
            'Is Active',
            'Photo URL',
            'Total Stock',
            'Created At',
            'Updated At',
            'Deleted At'
        ];
    }

    public function map($article): array
    {
        $totalStock = $article->stocks->sum('stock');

        return [
            $article->id,
            $article->name,
            $article->price,
            $article->merchant ? $article->merchant->name : 'No Merchant',
            $article->is_active ? 'Yes' : 'No',
            $article->photo_url,
            $totalStock,
            $article->created_at->format('Y-m-d H:i:s'),
            $article->updated_at->format('Y-m-d H:i:s'),
            $article->deleted_at ? $article->deleted_at->format('Y-m-d H:i:s') : null
        ];
    }
}
