<?php

namespace App\Exports;

use App\Models\Merchant;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\Exportable;
use Carbon\Carbon;

class MerchantsExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $fromDate;
    protected $toDate;
    protected $status;
    protected $type;

    public function __construct($fromDate = null, $toDate = null, $status = null, $type = null)
    {
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->status = $status;
        $this->type = $type;
    }

    public function query()
    {
        $query = Merchant::with(['parent'])->withTrashed();

        if ($this->fromDate) {
            $query->where('created_at', '>=', Carbon::parse($this->fromDate));
        }

        if ($this->toDate) {
            $query->where('created_at', '<=', Carbon::parse($this->toDate)->endOfDay());
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->type) {
            $query->where('type', $this->type);
        }

        return $query->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Address',
            'Type',
            'Status',
            'Phone',
            'Email',
            'Parent Merchant',
            'Latitude',
            'Longitude',
            'Entity File',
            'Other Document File',
            'Created At',
            'Updated At',
            'Deleted At'
        ];
    }

    public function map($merchant): array
    {
        return [
            $merchant->id,
            $merchant->name,
            $merchant->address,
            $merchant->type,
            $merchant->status,
            $merchant->tel,
            $merchant->email,
            $merchant->parent ? $merchant->parent->name : null,
            $merchant->lat,
            $merchant->long,
            $merchant->entity_file,
            $merchant->other_document_file,
            $merchant->created_at->format('Y-m-d H:i:s'),
            $merchant->updated_at->format('Y-m-d H:i:s'),
            $merchant->deleted_at ? $merchant->deleted_at->format('Y-m-d H:i:s') : null
        ];
    }
}
