<?php

namespace App\Services;

use App\Models\ConsignmentPayment;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pelunasan Hutang Konsinyasi -- struktur & disiplin PERSIS
 * SupplierPaymentService, sengaja TIDAK reuse kelas itu sama sekali (lihat
 * rancangan yang disetujui): SupplierPaymentService hardcode akun 2-1000,
 * mengubahnya untuk mendukung 2-3000 berarti menyentuh badan method yang
 * sudah berjalan untuk Hutang Usaha biasa.
 */
class ConsignmentPaymentService
{
    private const SCALE = 4;

    // Chart of accounts code -- diseed 2026_10_01_100800_seed_consignment_account_and_permission.php.
    private const ACCOUNT_HUTANG_KONSINYASI = '2-3000';

    public function __construct(
        private readonly PostingService $posting,
        private readonly CashAccountService $cashAccounts,
    ) {}

    /**
     * @param  array{
     *     outlet_id: int,
     *     supplier_id: int,
     *     date: DateTimeInterface|string,
     *     amount: int|float|string,
     *     cash_account_code?: ?string,
     *     memo?: ?string,
     *     allocations: array<int, array{consignment_accrual_id: ?int, amount: int|float|string}>,
     * }  $data
     *
     * @throws InvalidArgumentException kalau alokasi tidak sama dengan jumlah pembayaran, atau akun kas tidak valid.
     */
    public function recordPayment(array $data): ConsignmentPayment
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
            $payment = new ConsignmentPayment([
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
                    'consignment_accrual_id' => $allocation['consignment_accrual_id'] ?? null,
                    'amount' => (string) $allocation['amount'],
                ]);
            }

            $this->posting->post(
                lines: [
                    ['account' => self::ACCOUNT_HUTANG_KONSINYASI, 'debit' => $amount, 'credit' => 0],
                    ['account' => $cashAccountCode, 'debit' => 0, 'credit' => $amount],
                ],
                date: $data['date'],
                source: $payment,
                memo: "Pembayaran hutang konsinyasi ke supplier #{$payment->supplier_id}",
            );

            return $payment->fresh('allocations');
        });
    }

    /**
     * Identik SupplierPaymentService::allocateFifo() -- fungsi murni, pola
     * sama persis, cuma kunci array-nya `consignment_accrual_id`.
     *
     * @param  array<int, array{consignment_accrual_id: int, remaining: int|float|string}>  $accruals  Oldest first.
     * @return array<int, array{consignment_accrual_id: ?int, amount: string}>
     */
    public function allocateFifo(array $accruals, int|float|string $amount): array
    {
        $remainingToAllocate = bcadd((string) $amount, '0', self::SCALE);
        $allocations = [];

        foreach ($accruals as $accrual) {
            if (bccomp($remainingToAllocate, '0', self::SCALE) <= 0) {
                break;
            }

            $accrualRemaining = bcadd((string) $accrual['remaining'], '0', self::SCALE);
            if (bccomp($accrualRemaining, '0', self::SCALE) <= 0) {
                continue;
            }

            $fillAmount = bccomp($remainingToAllocate, $accrualRemaining, self::SCALE) < 0
                ? $remainingToAllocate
                : $accrualRemaining;

            $allocations[] = ['consignment_accrual_id' => $accrual['consignment_accrual_id'], 'amount' => $fillAmount];
            $remainingToAllocate = bcsub($remainingToAllocate, $fillAmount, self::SCALE);
        }

        if (bccomp($remainingToAllocate, '0', self::SCALE) > 0) {
            $allocations[] = ['consignment_accrual_id' => null, 'amount' => $remainingToAllocate];
        }

        return $allocations;
    }
}
