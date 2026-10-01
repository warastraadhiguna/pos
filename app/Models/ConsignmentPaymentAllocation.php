<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['consignment_payment_id', 'consignment_accrual_id', 'amount'])]
class ConsignmentPaymentAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function consignmentPayment(): BelongsTo
    {
        return $this->belongsTo(ConsignmentPayment::class);
    }

    /**
     * Null when this allocation isn't tied to a specific accrual — an
     * advance/overpayment, same convention as SupplierPaymentAllocation::goodsReceipt().
     */
    public function consignmentAccrual(): BelongsTo
    {
        return $this->belongsTo(ConsignmentAccrual::class);
    }
}
