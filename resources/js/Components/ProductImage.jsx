import { useState } from 'react';

// Palet warna tetap (bukan HSL acak) -- dipilih supaya semuanya cukup
// gelap untuk kontras dengan teks putih, dan enak dipandang berdampingan
// di grid yang sama. Warna dipilih deterministik dari nama produk (hash
// sederhana), jadi produk yang sama SELALU dapat warna yang sama.
const PALETTE = [
    '#0146BB', // biru brand
    '#0F766E', // teal
    '#B45309', // amber gelap
    '#7C3AED', // ungu
    '#BE123C', // merah delima
    '#15803D', // hijau
    '#C2410C', // oranye gelap
    '#4338CA', // indigo
];

function colorForName(name) {
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = (hash * 31 + name.charCodeAt(i)) | 0;
    }
    return PALETTE[Math.abs(hash) % PALETTE.length];
}

/**
 * Kotak gambar produk berukuran TETAP (dikendalikan className dari
 * pemanggil, mis. "h-16 w-full") -- fallback inisial+warna dipakai baik
 * saat produk memang tidak punya gambar (`src` null) MAUPUN saat gambar
 * gagal dimuat (404/rusak, lewat onError), supaya kartu tidak pernah
 * menampilkan kotak kosong/rusak.
 */
export default function ProductImage({ src, name, className = '' }) {
    const [errored, setErrored] = useState(false);

    if (!src || errored) {
        const initial = (name?.trim()?.[0] ?? '?').toUpperCase();
        return (
            <div
                className={`flex items-center justify-center rounded-md text-lg font-semibold text-white ${className}`}
                style={{ backgroundColor: colorForName(name ?? '') }}
            >
                {initial}
            </div>
        );
    }

    return (
        <img
            src={src}
            alt={name}
            loading="lazy"
            onError={() => setErrored(true)}
            className={`rounded-md object-cover ${className}`}
        />
    );
}
