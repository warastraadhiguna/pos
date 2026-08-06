<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by SaleService::createSale() when payment_method is 'qris' but
 * the resolved cash_account_code is either missing entirely (QRIS never
 * configured in Pengaturan, see SettingController::updateQris()) or
 * resolves to Kas (CashAccountService::DEFAULT_CODE) -- the entire point
 * of QRIS as a payment method is that the money lands in a BANK account
 * (see rancangan fitur QRIS), never physically in the cash drawer. Same
 * discipline as InsufficientCashReceivedException: reflects bad/incomplete
 * input (a misconfigured account, or a client that somehow forced Kas),
 * expected to surface as a normal 422 to the caller, not a bug report.
 */
class InvalidQrisAccountException extends RuntimeException
{
}
