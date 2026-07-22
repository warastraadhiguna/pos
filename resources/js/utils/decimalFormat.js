// Format lapisan tampilan/input angka gaya Indonesia (titik ribuan, koma
// desimal). Ini TIDAK mengubah cara nilai disimpan/dihitung — backend tetap
// decimal(18,4) + bcmath; ini murni presentasi & parsing input pengguna.

/**
 * Format nilai angka/decimal-string ke tampilan Indonesia, mis. 1000.5 -> "1.000,5".
 */
export function formatDecimalID(value, maxDecimals = 2) {
    if (value === null || value === undefined || value === '') return '';
    const num = Number(value);
    if (Number.isNaN(num)) return '';

    return num.toLocaleString('id-ID', { maximumFractionDigits: maxDecimals });
}

/**
 * Pecah teks yang sedang diketik pengguna (gaya Indonesia, mis. "1.000,5")
 * menjadi { display, plain }:
 *  - display: versi terformat ulang untuk ditampilkan di input (titik ribuan
 *    otomatis disisipkan saat mengetik supaya salah ketik seperti "80020"
 *    langsung terlihat sebagai "80.020").
 *  - plain: string desimal biasa ("1000.5") siap dikirim ke backend.
 */
export function parseTypedDecimalID(raw, maxDecimals = 2) {
    const cleaned = String(raw).replace(/[^0-9,]/g, '');
    const commaIndex = cleaned.indexOf(',');

    let intPart = commaIndex === -1 ? cleaned : cleaned.slice(0, commaIndex);
    let decPart = commaIndex === -1
        ? undefined
        : cleaned.slice(commaIndex + 1).replace(/,/g, '').slice(0, maxDecimals);

    intPart = intPart.replace(/^0+(?=\d)/, '');
    const withThousands = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    const display = decPart !== undefined ? `${withThousands},${decPart}` : withThousands;
    const plain = intPart === ''
        ? (decPart !== undefined ? `0.${decPart}` : '')
        : (decPart !== undefined ? `${intPart}.${decPart}` : intPart);

    return { display, plain };
}
