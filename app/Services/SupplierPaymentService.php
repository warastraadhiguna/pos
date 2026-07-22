<?php

namespace App\Services;

use App\Models\SupplierPayment;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SupplierPaymentService
{
    private const SCALE = 4;

    // Chart of accounts codes seeded by Database\Seeders\FoundationSeeder.
    private const ACCOUNT_HUTANG_USAHA = '2-1000';

    public function __construct(
        private readonly PostingService $posting,
        private readonly CashAccountService $cashAccounts,
    ) {}

    /**
     * Record a payment toward a supplier's accounts payable: always Dr
     * Hutang Usaha / Cr Kas — ONE journal per payment event regardless of
     * how many notas (GoodsReceipt) it covers. Allocation across notas is
     * tracked separately in supplier_payment_allocations; it's metadata on
     * top of the journal, not a second journal per allocation.
     *
     * Invariant enforced here (not a DB constraint — MySQL can't CHECK a
     * cross-table SUM): SUM($data['allocations'][*]['amount']) MUST equal
     * $data['amount'] exactly. A null goods_receipt_id in an allocation
     * means "not tied to a specific nota" (advance/overpayment) — this is
     * how overpayment is represented, not a separate code path, and it's
     * also how legacy aggregate-model payments were backfilled when
     * purchase_order_id was dropped (see the migration).
     *
     * @param  array{
     *     outlet_id: int,
     *     supplier_id: int,
     *     date: DateTimeInterface|string,
     *     amount: int|float|string,
     *     cash_account_code?: ?string,
     *     memo?: ?string,
     *     allocations: array<int, array{goods_receipt_id: ?int, amount: int|float|string}>,
     * }  $data
     *
     * cash_account_code -- which Kas/Bank account this payment came FROM
     * (see CashAccountService). Defaults to Kas when absent.
     *
     * @throws InvalidArgumentException if the allocations don't sum to the payment amount, or the cash account is invalid.
     */
    public function recordPayment(array $data): SupplierPayment
    {
        $amount = (string) $data['amount'];
        $cashAccountCode = $data['cash_account_code'] ?? CashAccountService::DEFAULT_CODE;
        $this->cashAccounts->assertValidCashAccount($cashAccountCode);

        $allocationTotal = array_reduce(
            $data['allocations'],
            fn (string $carry, array $allocation) => bcadd($carry, (string) $allocation['amount'], self::SCALE),
            '0',
        );

        if (bccomp($allocationTotal, $amount, self::SCALE) !== 0) {
            throw new InvalidArgumentException(
                "Total alokasi ({$allocationTotal}) harus sama dengan jumlah pembayaran ({$amount})."
            );
        }

        return DB::transaction(function () use ($data, $amount, $cashAccountCode) {
            $payment = new SupplierPayment([
                'outlet_id' => $data['outlet_id'],
                'supplier_id' => $data['supplier_id'],
                'date' => $data['date'],
                'amount' => $amount,
                'cash_account_code' => $cashAccountCode,
                'memo' => $data['memo'] ?? null,
            ]);
            $payment->save();

            foreach ($data['allocations'] as $allocation) {
                $payment->allocations()->create([
                    'goods_receipt_id' => $allocation['goods_receipt_id'] ?? null,
                    'amount' => (string) $allocation['amount'],
                ]);
            }

            $this->posting->post(
                lines: [
                    ['account' => self::ACCOUNT_HUTANG_USAHA, 'debit' => $amount, 'credit' => 0],
                    ['account' => $cashAccountCode, 'debit' => 0, 'credit' => $amount],
                ],
                date: $data['date'],
                source: $payment,
                memo: "Pembayaran hutang ke supplier #{$payment->supplier_id}",
            );

            return $payment->fresh('allocations');
        });
    }

    /**
     * Greedily fill the oldest outstanding notas first. Pure function (no
     * DB writes) — used to compute the FIFO preview shown in the payment
     * form, and submitted as-is unless the user switches to manual mode.
     * Any amount left over after every nota is fully covered becomes a
     * null-goods_receipt_id allocation (advance/overpayment) rather than
     * being rejected.
     *
     * @param  array<int, array{goods_receipt_id: int, remaining: int|float|string}>  $notas  Oldest first.
     * @return array<int, array{goods_receipt_id: ?int, amount: string}>
     */
    public function allocateFifo(array $notas, int|float|string $amount): array
    {
        // Normalize once up front so every amount this method returns is a
        // consistent scale-4 string, regardless of how the caller formatted
        // $amount (e.g. "60000" from a query string vs "60000.0000").
        $remainingToAllocate = bcadd((string) $amount, '0', self::SCALE);
        $allocations = [];

        foreach ($notas as $nota) {
            if (bccomp($remainingToAllocate, '0', self::SCALE) <= 0) {
                break;
            }

            $notaRemaining = bcadd((string) $nota['remaining'], '0', self::SCALE);
            if (bccomp($notaRemaining, '0', self::SCALE) <= 0) {
                continue;
            }

            $fillAmount = bccomp($remainingToAllocate, $notaRemaining, self::SCALE) < 0
                ? $remainingToAllocate
                : $notaRemaining;

            $allocations[] = ['goods_receipt_id' => $nota['goods_receipt_id'], 'amount' => $fillAmount];
            $remainingToAllocate = bcsub($remainingToAllocate, $fillAmount, self::SCALE);
        }

        if (bccomp($remainingToAllocate, '0', self::SCALE) > 0) {
            $allocations[] = ['goods_receipt_id' => null, 'amount' => $remainingToAllocate];
        }

        return $allocations;
    }
}
