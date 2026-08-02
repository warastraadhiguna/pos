import { useEffect, useState } from 'react';
import Modal from './Modal';
import PrimaryButton from './PrimaryButton';
import SecondaryButton from './SecondaryButton';

const formatRupiah = (value) => 'Rp' + Math.round(value).toLocaleString('id-ID');

/// Pemilih variasi multi-select -- muncul HANYA saat produk yang ditap
/// punya variasi aktif & fitur menyala (lihat Kasir/Index.jsx addToCart()).
/// Harga baris (produk + Σ variasi tercentang) diperbarui REAL-TIME sebelum
/// konfirmasi, sama pentingnya baik untuk menambah item baru maupun
/// mengedit variasi item yang sudah ada di keranjang (`initialSelectedIds`
/// terisi untuk kasus edit).
export default function VariationPickerModal({
    show,
    product,
    initialSelectedIds = [],
    onConfirm,
    onClose,
}) {
    const [selectedIds, setSelectedIds] = useState(initialSelectedIds);

    // Reset pilihan tiap kali modal dibuka untuk produk/item yang berbeda --
    // tanpa ini, membuka modal utk produk lain akan mewarisi centang dari
    // produk sebelumnya.
    useEffect(() => {
        if (show) {
            setSelectedIds(initialSelectedIds);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, product?.id]);

    if (!product) return null;

    const activeVariations = (product.variations ?? []).filter(
        (v) => v.is_active,
    );

    const toggle = (variationId) => {
        setSelectedIds((previous) =>
            previous.includes(variationId)
                ? previous.filter((id) => id !== variationId)
                : [...previous, variationId],
        );
    };

    const selectedVariations = activeVariations.filter((v) =>
        selectedIds.includes(v.id),
    );
    const runningTotal =
        Number(product.sell_price) +
        selectedVariations.reduce((sum, v) => sum + Number(v.additional_price), 0);

    const confirm = () => {
        onConfirm(selectedVariations);
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="p-6">
                <h2 className="text-lg font-medium text-gray-900">
                    {product.name}
                </h2>
                <p className="mt-1 text-sm text-gray-500">
                    Pilih variasi (boleh lebih dari satu, atau tidak sama
                    sekali).
                </p>

                <div className="mt-4 max-h-64 space-y-2 overflow-y-auto">
                    {activeVariations.map((variation) => (
                        <label
                            key={variation.id}
                            className="flex cursor-pointer items-center justify-between gap-3 rounded-md border border-gray-200 px-3 py-2 hover:border-accent"
                        >
                            <span className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="rounded text-primary focus:ring-primary"
                                    checked={selectedIds.includes(variation.id)}
                                    onChange={() => toggle(variation.id)}
                                />
                                <span className="text-sm text-gray-900">
                                    {variation.name}
                                </span>
                            </span>
                            <span className="text-sm text-gray-500">
                                +{formatRupiah(variation.additional_price)}
                            </span>
                        </label>
                    ))}
                </div>

                <div className="mt-4 flex items-center justify-between border-t border-gray-200 pt-4">
                    <span className="text-sm font-medium text-gray-700">
                        Harga item
                    </span>
                    <span className="text-lg font-semibold text-gray-900">
                        {formatRupiah(runningTotal)}
                    </span>
                </div>

                <div className="mt-4 flex items-center gap-4">
                    <PrimaryButton type="button" onClick={confirm}>
                        Tambah ke Keranjang
                    </PrimaryButton>
                    <SecondaryButton type="button" onClick={onClose}>
                        Batal
                    </SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}
