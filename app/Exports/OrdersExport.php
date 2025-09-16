<?php

namespace App\Exports;

use App\Models\Order;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\Exportable;
use Carbon\Carbon;

class OrdersExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $fromDate;
    protected $toDate;
    protected $status;
    protected $merchantId;

    public function __construct($fromDate = null, $toDate = null, $status = null, $merchantId = null)
    {
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->status = $status;
        $this->merchantId = $merchantId;
    }

    public function query()
    {
        $query = Order::with(['merchant', 'carts.article', 'payments'])->withTrashed();

        if ($this->fromDate) {
            $query->where('created_at', '>=', Carbon::parse($this->fromDate));
        }

        if ($this->toDate) {
            $query->where('created_at', '<=', Carbon::parse($this->toDate)->endOfDay());
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->merchantId) {
            $query->where('merchant_id', $this->merchantId);
        }

        return $query->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Order Number',
            'Merchant',
            'Amount',
            'Status',
            'Items Count',
            'Total Paid',
            'Payment Status',
            'Created At',
            'Updated At',
            'Deleted At'
        ];
    }

    public function map($order): array
    {
        $totalPaid = $order->payments->where('status', 'PAID')->sum('amount');
        $paymentStatus = $totalPaid >= $order->amount ? 'Fully Paid' : 
                        ($totalPaid > 0 ? 'Partially Paid' : 'Unpaid');

        return [
            $order->id,
            $order->order_number,
            $order->merchant ? $order->merchant->name : 'No Merchant',
            $order->amount,
            $order->status,
            $order->carts->count(),
            $totalPaid,
            $paymentStatus,
            $order->created_at->format('Y-m-d H:i:s'),
            $order->updated_at->format('Y-m-d H:i:s'),
            $order->deleted_at ? $order->deleted_at->format('Y-m-d H:i:s') : null
        ];
    }
}
