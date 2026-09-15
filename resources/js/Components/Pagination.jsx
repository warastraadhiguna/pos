import { Link } from '@inertiajs/react';

/**
 * `links` adalah bentuk BAWAAN Laravel paginator (`->paginate()->toArray()`
 * lewat Inertia): elemen pertama & terakhir SELALU "Previous"/"Next"
 * (label HTML-entity `&laquo;`/`&raquo;`), elemen di tengah nomor halaman
 * biasa. Label ujung diganti versi Indonesia di sini -- label nomor
 * halaman di tengah dipakai apa adanya (angka polos, tidak ada HTML
 * entity). `url: null` berarti ujung yang sedang tidak bisa diklik
 * (halaman pertama tidak punya "Previous", halaman terakhir tidak punya
 * "Next") -- dirender sebagai teks abu-abu, bukan link.
 */
export default function Pagination({ links, className = '' }) {
    if (!links || links.length <= 3) {
        // <=3 berarti cuma Previous + 1 halaman + Next -- tidak ada apa
        // pun yang perlu dipaginasi.
        return null;
    }

    return (
        <div className={`flex flex-wrap items-center justify-center gap-1 ${className}`}>
            {links.map((link, index) => {
                const label =
                    index === 0
                        ? '‹ Sebelumnya'
                        : index === links.length - 1
                          ? 'Berikutnya ›'
                          : link.label;

                if (!link.url) {
                    return (
                        <span
                            key={index}
                            className="rounded-md px-3 py-1.5 text-sm text-gray-400"
                        >
                            {label}
                        </span>
                    );
                }

                return (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        className={
                            'rounded-md px-3 py-1.5 text-sm ' +
                            (link.active
                                ? 'bg-primary text-white'
                                : 'text-gray-600 hover:bg-gray-100')
                        }
                    >
                        {label}
                    </Link>
                );
            })}
        </div>
    );
}
