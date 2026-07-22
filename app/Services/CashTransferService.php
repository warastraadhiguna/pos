<?php

namespace App\Services;

use App\Models\CashTransfer;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CashTransferService
{
    private const SCALE = 4;

    public function __construct(
        private readonly PostingService $posting,
        private readonly CashAccountService $cashAccounts,
    ) {}

    /**
     * Pindahkan uang antar akun Kas/Bank -- Dr {to} / Cr {from}, selalu
     * tepat 2 baris, otomatis seimbang. Tidak ada validasi "saldo cukup":
     * konsisten dengan seluruh sistem ini, yang memang tidak pernah
     * menahan posting berdasarkan saldo (mis. pelunasan hutang supplier
     * juga tidak dicek terhadap saldo Kas).
     *
     * @param  array{
     *     outlet_id: int,
     *     date: DateTimeInterface|string,
     *     from_account_code: string,
     *     to_account_code: string,
     *     amount: int|float|string,
     *     memo?: ?string,
     *     created_by_user_id?: ?int,
     * }  $data
     *
     * @throws InvalidArgumentException if from == to, either account isn't a valid active Kas/Bank account, or amount <= 0.
     */
    public function recordTransfer(array $data): CashTransfer
    {
        $fromCode = $data['from_account_code'];
        $toCode = $data['to_account_code'];

        if ($fromCode === $toCode) {
            throw new InvalidArgumentException('Akun asal dan tujuan transfer tidak boleh sama.');
        }

        $this->cashAccounts->assertValidCashAccount($fromCode);
        $this->cashAccounts->assertValidCashAccount($toCode);

        $amount = (string) $data['amount'];

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Jumlah transfer harus lebih besar dari nol.');
        }

        return DB::transaction(function () use ($data, $fromCode, $toCode, $amount) {
            $transfer = new CashTransfer([
                'outlet_id' => $data['outlet_id'],
                'date' => $data['date'],
                'from_account_code' => $fromCode,
                'to_account_code' => $toCode,
                'amount' => $amount,
                'memo' => $data['memo'] ?? null,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ]);
            $transfer->save();

            $this->posting->post(
                lines: [
                    ['account' => $toCode, 'debit' => $amount, 'credit' => 0],
                    ['account' => $fromCode, 'debit' => 0, 'credit' => $amount],
                ],
                date: $data['date'],
                source: $transfer,
                memo: $data['memo'] ?? "Transfer {$fromCode} -> {$toCode}",
            );

            return $transfer->fresh();
        });
    }
}
