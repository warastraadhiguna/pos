import Checkbox from '@/Components/Checkbox';
import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import ItemCombobox from '@/Components/ItemCombobox';
import NumberInput from '@/Components/NumberInput';
import PrimaryButton from '@/Components/PrimaryButton';
import ProductImage from '@/Components/ProductImage';
import QuickItemPicker from '@/Components/QuickItemPicker';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

// Sebuah produk yang dijual "apa adanya" (mis. minuman kaleng dibeli lalu
// dijual lagi tanpa diracik) tetap direpresentasikan sebagai BOM satu baris
// (qty 1, satuan dasar item itu) — sama persis strukturnya dengan racikan
// banyak komponen. "Mode" di sini murni kemudahan pengisian form; keduanya
// menghasilkan bentuk `components` yang identik saat dikirim ke server.
function isDirectSaleComponents(components) {
    return components.length === 1 && Number(components[0].qty) === 1;
}

export default function Form({ product, uoms, taxRates, productCategories }) {
    const editing = product !== null;

    const { data, setData, post, put, processing, errors } = useForm({
        name: product?.name ?? '',
        barcode: product?.barcode ?? '',
        sell_price: product?.sell_price ?? '0',
        tax_rate_id: product?.tax_rate_id ?? '',
        product_category_id: product?.product_category_id ?? '',
        is_active: product?.is_active ?? true,
        components: (product?.components ?? []).map((c) => ({
            item_id: c.item_id,
            qty: c.qty,
            uom_id: c.uom_id,
        })),
        image: null,
        remove_image: false,
    });

    // Pratinjau gambar YANG BARU DIPILIH (belum ter-upload) -- beda dari
    // `product.image_url_web` (gambar yang SUDAH tersimpan di server).
    // Dilepas (revokeObjectURL) tiap kali file berganti/form unmount supaya
    // tidak bocor memori.
    const [newImagePreview, setNewImagePreview] = useState(null);
    const fileInputRef = useRef(null);

    const handleImageChange = (e) => {
        const file = e.target.files?.[0] ?? null;
        setData((current) => ({ ...current, image: file, remove_image: false }));
        setNewImagePreview((previous) => {
            if (previous) URL.revokeObjectURL(previous);
            return file ? URL.createObjectURL(file) : null;
        });
    };

    const handleRemoveImage = () => {
        setNewImagePreview((previous) => {
            if (previous) URL.revokeObjectURL(previous);
            return null;
        });
        setData((current) => ({ ...current, image: null, remove_image: true }));
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    // Item lengkap (sku/nama/satuan) per baris komponen — cuma untuk
    // menampilkan label di ItemCombobox, tidak dikirim ke server. Selaras
    // indeksnya dengan data.components.
    const [bomItems, setBomItems] = useState(() =>
        (product?.components ?? []).map((c) => c.item ?? null),
    );

    const [mode, setMode] = useState(() => {
        if (editing && isDirectSaleComponents(data.components)) {
            return 'direct';
        }
        return editing && data.components.length > 0 ? 'bom' : 'direct';
    });

    const switchMode = (newMode) => {
        setMode(newMode);
        // Ganti mode selalu mulai dari kosong — mencegah baris BOM lama yang
        // tidak relevan diam-diam ikut terkirim saat pindah mode.
        setData('components', []);
        setBomItems([]);
    };

    const handleDirectItemSelect = (item) => {
        setData('components', [
            { item_id: item.id, qty: '1', uom_id: item.base_uom_id },
        ]);

        // Sinkron dua arah: kalau orang mulai dari "+ Buat Item Baru"/cari
        // item duluan (Nama/Barcode produk masih kosong), salin baliknya
        // dari Item yang dipilih — supaya urutan pengisian tidak masalah.
        // Tidak menimpa kalau produk sudah ada isinya.
        if (!data.name) {
            setData('name', item.name);
        }
        if (!data.barcode) {
            setData('barcode', item.sku);
        }
    };

    const addComponent = () => {
        setData('components', [
            ...data.components,
            { item_id: '', qty: '', uom_id: '' },
        ]);
        setBomItems((previous) => [...previous, null]);
    };

    const removeComponent = (index) => {
        setData(
            'components',
            data.components.filter((_, i) => i !== index),
        );
        setBomItems((previous) => previous.filter((_, i) => i !== index));
    };

    const updateComponent = (index, field, value) => {
        const updated = data.components.map((component, i) =>
            i === index ? { ...component, [field]: value } : component,
        );
        setData('components', updated);
    };

    const selectBomItem = (index, item) => {
        // Build the whole row in one setData call — two sequential
        // updateComponent() calls here would each read data.components from
        // the same stale closure and the second call would overwrite the
        // first, silently dropping item_id (exactly the bug this avoids).
        const updated = data.components.map((component, i) => {
            if (i !== index) {
                return component;
            }
            return {
                ...component,
                item_id: item.id,
                // Default satuan ke satuan dasar item — tetap bisa diganti
                // kalau resepnya memang mengukur dalam satuan lain (mis. KG
                // dari base GR).
                uom_id: component.uom_id || item.base_uom_id,
            };
        });
        setData('components', updated);
        setBomItems((previous) =>
            previous.map((existing, i) => (i === index ? item : existing)),
        );
    };

    const generateBarcode = () => {
        // 12 digit acak — cukup untuk barcode internal toko (bukan barcode
        // pabrik resmi), dipakai apa adanya sebagai kode yang di-scan kasir.
        const digits = Array.from({ length: 12 }, () =>
            Math.floor(Math.random() * 10),
        ).join('');
        setData('barcode', digits);
    };

    const formRef = useRef(null);

    const submit = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('master.products.update', product.id));
        } else {
            post(route('master.products.store'));
        }
    };

    // Enter pindah ke field berikutnya, bukan submit tak sengaja — pola
    // sama dengan form Pembelian. Enter di dalam ItemCombobox tetap berarti
    // "pilih hasil highlight" (combobox itu sendiri sudah preventDefault
    // saat menangani Enter, jadi e.defaultPrevented di sini menghormatinya).
    const handleFormKeyDown = (e) => {
        if (e.key !== 'Enter' || e.ctrlKey || e.defaultPrevented) return;

        e.preventDefault();

        const focusable = Array.from(
            formRef.current?.querySelectorAll('input, select') ?? [],
        ).filter((el) => !el.disabled);
        const currentIndex = focusable.indexOf(e.target);
        if (currentIndex === -1) return;

        focusable[currentIndex + 1]?.focus();
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    {editing ? 'Edit Produk' : 'Tambah Produk'}
                </h2>
            }
        >
            <Head title={editing ? 'Edit Produk' : 'Tambah Produk'} />

            <div className="py-12">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <form
                            ref={formRef}
                            onSubmit={submit}
                            onKeyDown={handleFormKeyDown}
                            className="space-y-6"
                        >
                            <div>
                                <InputLabel htmlFor="name" value="Nama" />
                                <TextInput
                                    id="name"
                                    className="mt-1 block w-full"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                    isFocused
                                    required
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="barcode"
                                    value="Barcode"
                                />
                                <div className="mt-1 flex gap-2">
                                    <TextInput
                                        id="barcode"
                                        className="block w-full"
                                        value={data.barcode}
                                        placeholder="Scan atau ketik barcode"
                                        onChange={(e) =>
                                            setData(
                                                'barcode',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <SecondaryButton
                                        type="button"
                                        onClick={generateBarcode}
                                        className="whitespace-nowrap"
                                    >
                                        Buat Acak
                                    </SecondaryButton>
                                </div>
                                <p className="mt-1 text-xs text-gray-400">
                                    Kosongkan kalau produk ini belum punya
                                    barcode.
                                </p>
                                <InputError
                                    className="mt-2"
                                    message={errors.barcode}
                                />
                            </div>

                            <div>
                                <InputLabel value="Gambar Produk" />
                                <div className="mt-2 flex items-center gap-4">
                                    <ProductImage
                                        src={
                                            data.remove_image
                                                ? null
                                                : (newImagePreview ??
                                                  product?.image_url_web ??
                                                  null)
                                        }
                                        name={data.name}
                                        className="h-24 w-24 shrink-0 text-2xl"
                                    />
                                    <div className="flex flex-col gap-2">
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            onChange={handleImageChange}
                                            className="text-sm text-gray-600"
                                        />
                                        <p className="text-xs text-gray-400">
                                            JPEG/PNG/WebP, maksimal 5MB.
                                            Otomatis dikompres & diperkecil
                                            saat disimpan.
                                        </p>
                                        {(newImagePreview ||
                                            (product?.image_url_web &&
                                                !data.remove_image)) && (
                                            <SecondaryButton
                                                type="button"
                                                className="w-fit"
                                                onClick={handleRemoveImage}
                                            >
                                                Hapus Gambar
                                            </SecondaryButton>
                                        )}
                                    </div>
                                </div>
                                <InputError
                                    className="mt-2"
                                    message={errors.image}
                                />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="product_category_id"
                                    value="Kategori Produk"
                                />
                                <SelectInput
                                    id="product_category_id"
                                    className="mt-1 block w-full"
                                    value={data.product_category_id}
                                    onChange={(e) =>
                                        setData(
                                            'product_category_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Tanpa kategori</option>
                                    {productCategories.map((category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
                                            {category.name}
                                        </option>
                                    ))}
                                </SelectInput>
                                <InputError
                                    className="mt-2"
                                    message={errors.product_category_id}
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel
                                        htmlFor="sell_price"
                                        value="Harga Jual"
                                    />
                                    <NumberInput
                                        id="sell_price"
                                        className="mt-1 block w-full"
                                        value={data.sell_price}
                                        onChange={(plain) =>
                                            setData('sell_price', plain)
                                        }
                                        required
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.sell_price}
                                    />
                                </div>

                                <div>
                                    <InputLabel
                                        htmlFor="tax_rate_id"
                                        value="Pajak"
                                    />
                                    <SelectInput
                                        id="tax_rate_id"
                                        className="mt-1 block w-full"
                                        value={data.tax_rate_id}
                                        onChange={(e) =>
                                            setData(
                                                'tax_rate_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="">Tanpa pajak</option>
                                        {taxRates.map((taxRate) => (
                                            <option
                                                key={taxRate.id}
                                                value={taxRate.id}
                                            >
                                                {taxRate.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <InputError
                                        className="mt-2"
                                        message={errors.tax_rate_id}
                                    />
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="is_active"
                                    checked={data.is_active}
                                    onChange={(e) =>
                                        setData(
                                            'is_active',
                                            e.target.checked,
                                        )
                                    }
                                />
                                <InputLabel
                                    htmlFor="is_active"
                                    value="Aktif"
                                />
                            </div>

                            <div>
                                <InputLabel value="Cara Menjual" />
                                <div className="mt-2 flex gap-6">
                                    <label className="flex items-center gap-2 text-sm text-gray-700">
                                        <input
                                            type="radio"
                                            name="sell_mode"
                                            checked={mode === 'direct'}
                                            onChange={() => switchMode('direct')}
                                            className="text-primary focus:ring-primary"
                                        />
                                        Jual Apa Adanya (1 item langsung)
                                    </label>
                                    <label className="flex items-center gap-2 text-sm text-gray-700">
                                        <input
                                            type="radio"
                                            name="sell_mode"
                                            checked={mode === 'bom'}
                                            onChange={() => switchMode('bom')}
                                            className="text-primary focus:ring-primary"
                                        />
                                        Racikan (BOM, banyak komponen)
                                    </label>
                                </div>

                                {mode === 'direct' ? (
                                    <div className="mt-3">
                                        <p className="mb-3 text-sm text-gray-500">
                                            Produk ini sama dengan satu Item tertentu, dijual
                                            persis 1 satuan dasarnya tanpa dicampur item lain.
                                        </p>
                                        <QuickItemPicker
                                            initialItem={bomItems[0] ?? null}
                                            onSelect={handleDirectItemSelect}
                                            uoms={uoms}
                                            suggestedSku={data.barcode}
                                            suggestedName={data.name}
                                            error={
                                                errors.components ??
                                                errors['components.0.item_id']
                                            }
                                        />
                                    </div>
                                ) : (
                                    <div className="mt-3">
                                        <div className="flex items-center justify-between">
                                            <p className="text-sm text-gray-500">
                                                Produk ini diracik dari beberapa item, masing-masing
                                                dengan qty & satuannya sendiri.
                                            </p>
                                            <SecondaryButton
                                                type="button"
                                                className="whitespace-nowrap"
                                                onClick={addComponent}
                                            >
                                                Tambah Komponen
                                            </SecondaryButton>
                                        </div>
                                        <InputError
                                            className="mt-2"
                                            message={errors.components}
                                        />

                                        <div className="mt-3 space-y-3">
                                            {data.components.map(
                                                (component, index) => (
                                                    <div
                                                        key={index}
                                                        className="flex items-center gap-2 rounded-md border border-gray-200 p-3"
                                                    >
                                                        <div className="flex-1">
                                                            <ItemCombobox
                                                                key={
                                                                    bomItems[index]?.id ??
                                                                    `empty-${index}`
                                                                }
                                                                className="h-10"
                                                                initialItem={
                                                                    bomItems[index] ?? null
                                                                }
                                                                onSelect={(item) =>
                                                                    selectBomItem(
                                                                        index,
                                                                        item,
                                                                    )
                                                                }
                                                            />
                                                            <InputError
                                                                className="mt-1"
                                                                message={
                                                                    errors[
                                                                        `components.${index}.item_id`
                                                                    ]
                                                                }
                                                            />
                                                        </div>

                                                        <div className="w-28">
                                                            <NumberInput
                                                                className="h-10 block w-full"
                                                                placeholder="Qty"
                                                                maxDecimals={4}
                                                                value={component.qty}
                                                                onChange={(plain) =>
                                                                    updateComponent(
                                                                        index,
                                                                        'qty',
                                                                        plain,
                                                                    )
                                                                }
                                                                required
                                                            />
                                                            <InputError
                                                                className="mt-1"
                                                                message={
                                                                    errors[
                                                                        `components.${index}.qty`
                                                                    ]
                                                                }
                                                            />
                                                        </div>

                                                        <div className="w-36">
                                                            <SelectInput
                                                                className="h-10 block w-full"
                                                                value={
                                                                    component.uom_id
                                                                }
                                                                onChange={(e) =>
                                                                    updateComponent(
                                                                        index,
                                                                        'uom_id',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                required
                                                            >
                                                                <option
                                                                    value=""
                                                                    disabled
                                                                >
                                                                    Ukuran
                                                                </option>
                                                                {uoms.map((uom) => (
                                                                    <option
                                                                        key={uom.id}
                                                                        value={uom.id}
                                                                    >
                                                                        {uom.code}
                                                                    </option>
                                                                ))}
                                                            </SelectInput>
                                                            <InputError
                                                                className="mt-1"
                                                                message={
                                                                    errors[
                                                                        `components.${index}.uom_id`
                                                                    ]
                                                                }
                                                            />
                                                        </div>

                                                        <DangerButton
                                                            type="button"
                                                            className="h-10"
                                                            onClick={() =>
                                                                removeComponent(index)
                                                            }
                                                        >
                                                            Hapus
                                                        </DangerButton>
                                                    </div>
                                                ),
                                            )}
                                            {data.components.length === 0 && (
                                                <p className="text-sm text-gray-500">
                                                    Belum ada komponen. Produk tanpa
                                                    komponen tidak akan memotong stok
                                                    apa pun saat dijual.
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>

                            <div className="flex items-center gap-4">
                                <PrimaryButton disabled={processing}>
                                    Simpan
                                </PrimaryButton>
                                <Link href={route('master.products.index')}>
                                    <SecondaryButton type="button">
                                        Batal
                                    </SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
