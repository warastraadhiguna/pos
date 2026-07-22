<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['supplier_payment_id', 'goods_receipt_id', 'amount'])]
class SupplierPaymentAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    /**
     * Null when this allocation isn't tied to a specific nota — an
     * advance/overpayment, or a legacy aggregate-model payment backfilled
     * by the migration that dropped supplier_payments.purchase_order_id.
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }
}
