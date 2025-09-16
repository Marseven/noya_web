<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\Exportable;
use Carbon\Carbon;

class UsersExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $fromDate;
    protected $toDate;
    protected $status;

    public function __construct($fromDate = null, $toDate = null, $status = null)
    {
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->status = $status;
    }

    public function query()
    {
        $query = User::with(['role'])->withTrashed();

        if ($this->fromDate) {
            $query->where('created_at', '>=', Carbon::parse($this->fromDate));
        }

        if ($this->toDate) {
            $query->where('created_at', '<=', Carbon::parse($this->toDate)->endOfDay());
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        return $query->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'First Name',
            'Last Name',
            'Email',
            'Role',
            'Status',
            'Email Verified At',
            '2FA Active',
            'Created At',
            'Updated At',
            'Deleted At'
        ];
    }

    public function map($user): array
    {
        return [
            $user->id,
            $user->first_name,
            $user->last_name,
            $user->email,
            $user->role ? $user->role->name : 'No Role',
            $user->status,
            $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i:s') : null,
            $user->google_2fa_active ? 'Yes' : 'No',
            $user->created_at->format('Y-m-d H:i:s'),
            $user->updated_at->format('Y-m-d H:i:s'),
            $user->deleted_at ? $user->deleted_at->format('Y-m-d H:i:s') : null
        ];
    }
}
