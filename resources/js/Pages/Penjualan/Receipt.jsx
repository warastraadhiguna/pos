import { formatDecimalID } from '@/utils/decimalFormat';
import { Head, Link } from '@inertiajs/react';

const formatRupiah = (value) => 'Rp' + Math.round(Number(value)).toLocaleString('id-ID');
const formatDate = (value) => String(value).slice(0, 10);

// Sama persis dengan formatter di Penjualan/Index.jsx & Show.jsx (duplikasi
// yang sudah diterima di codebase ini, lihat komentar identik di sana) --
// `occurred_at` (momen transaksi sebenarnya) selalu diformat eksplisit ke
// Asia/Jakarta, TIDAK diasumsikan dari string mentahnya.
const formatDateTimeWIB = (occurredAt, fallbackDate) => {
    if (!occurredAt) {
        return `${formatDate(fallbackDate)} (jam tidak tercatat)`;
    }
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Jakarta',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(new Date(occurredAt));
    const get = (type) => parts.find((p) => p.type === type)?.value;
    return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')} WIB`;
};

// Segmen pertama dari local_uuid, huruf besar -- persis ReceiptFormatter._shortUuid()
// di mobile, supaya "No:" di struk web & mobile untuk transaksi yang sama terlihat identik.
const shortUuid = (localUuid) => (localUuid ? localUuid.split('-')[0].toUpperCase() : '');

function Row({ label, value, bold = false }) {
    return (
        <div className={`flex justify-between ${bold ? 'font-bold' : ''}`}>
            <span>{label}</span>
            <span>{value}</span>
        </div>
    );
}

export default function Receipt({ sale, store }) {
    const cashierName = sale.created_by_user?.name;
    const hasTax = Number(sale.tax_total) > 0;

    return (
        <>
            <Head title={`Struk Transaksi #${sale.id}`} />

            {/* Toolbar tidak pernah ikut tercetak (lihat @media print di
                bawah) -- halaman ini sengaja BUKAN AuthenticatedLayout
                supaya tidak perlu memodifikasi sidebar/nav global cuma
                untuk menyembunyikannya saat print; cukup sembunyikan
                toolbar milik halaman ini sendiri. */}
            <div className="no-print flex items-center justify-between gap-4 bg-gray-100 p-4">
                <Link href={route('penjualan.show', sale.id)} className="text-sm text-primary hover:underline">
                    &larr; Kembali ke Detail Transaksi
                </Link>
                <button
                    type="button"
                    onClick={() => window.print()}
                    className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-dark"
                >
                    Cetak Struk
                </button>
            </div>

            <div className="flex justify-center bg-gray-200 py-8 print:bg-white print:py-0">
                <div id="receipt" className="w-[58mm] bg-white p-2 font-mono text-[11px] leading-tight text-gray-900 shadow print:w-auto print:shadow-none">
                    {store.name && <div className="text-center font-bold">{store.name}</div>}
                    {store.address && <div className="text-center">{store.address}</div>}
                    {store.phone && <div className="text-center">{store.phone}</div>}

                    <div className="my-1 border-t border-dashed border-gray-900" />

                    <div>{formatDateTimeWIB(sale.occurred_at, sale.date)}</div>
                    <div>No: {shortUuid(sale.local_uuid)}</div>
                    {cashierName && <div>Kasir: {cashierName}</div>}

                    <div className="my-1 border-t border-dashed border-gray-900" />

                    {sale.lines.map((line) => (
                        <div key={line.id} className="mb-1">
                            <div>{line.product_name ?? line.product.name}</div>
                            <Row
                                label={`${formatDecimalID(line.qty, 4)} x ${formatRupiah(line.unit_price)}`}
                                value={formatRupiah(line.line_total)}
                            />
                        </div>
                    ))}

                    <div className="my-1 border-t border-dashed border-gray-900" />

                    <Row label="Subtotal" value={formatRupiah(sale.subtotal)} />
                    {/* Baris PPN HANYA muncul kalau tax_total > 0 -- itu sudah
                        merangkum baik "saklar PPN mati" maupun "tidak ada
                        baris kena pajak", persis ReceiptFormatter di mobile. */}
                    {hasTax && <Row label="Termasuk PPN" value={formatRupiah(sale.tax_total)} />}
                    <Row label="TOTAL" value={formatRupiah(sale.grand_total)} bold />
                    <Row label="Metode" value="Tunai (Cash)" />
                    <Row label="Uang Diterima" value={formatRupiah(sale.cash_received)} />
                    <Row label="Kembali" value={formatRupiah(sale.change_amount)} />

                    <div className="my-1 border-t border-dashed border-gray-900" />

                    {store.footer && <div className="text-center">{store.footer}</div>}
                </div>
            </div>

            <style>{`
                @media print {
                    .no-print { display: none !important; }
                    @page { size: 58mm auto; margin: 0; }
                    html, body { margin: 0; padding: 0; }
                }
            `}</style>
        </>
    );
}
