<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Pembatalan (nota penerimaan barang / stock opname) yang melanggar aturan
 * -- nota sudah dibayar, atau stok akan jadi minus -- tidak diblokir, tapi
 * ditolak dengan exception ini sampai pemanggil mengirim konfirmasi
 * eksplisit. Jaring pengaman backend: UI sudah menampilkan dialog
 * konfirmasi lebih dulu (pola sama confirm_overreceipt di
 * GoodsReceiptController).
 */
class VoidConfirmationRequiredException extends RuntimeException
{
}
