<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by `StockOpnameService::postOpname()` when stock of an opname item
 * moved (sale, goods receipt, ...) after the opname draft was created and
 * the caller did not confirm the counts were taken AFTER those movements.
 *
 * Insiden produksi: Pocari dihitung 19 saat draft dibuat, 96 pcs masuk
 * 27 menit kemudian, opname baru diposting 4 hari setelahnya -- hitungan
 * lama dibandingkan stok terkini, tercatat -43 pcs (Rp290.041) padahal
 * seharusnya +31. Bukan error server; StockOpnameController menampilkannya
 * sebagai pesan biasa.
 */
class StockChangedSinceOpnameStartedException extends RuntimeException
{
}
