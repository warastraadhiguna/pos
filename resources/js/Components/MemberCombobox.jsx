import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

/**
 * Free-text-capable member/pelanggan picker for Kasir checkout. Unlike
 * SearchCombobox (ItemCombobox/SupplierCombobox), this does NOT revert
 * unselected typed text on blur — a cashier must be able to type a walk-in
 * customer's name and have it stick even if they never pick a result from
 * the list. Picking a result attaches member_id (for future per-member
 * history / the draft feature); typing afterwards releases member_id back
 * to null, since the text no longer corresponds to a known Member — the
 * name itself is still kept as a free-text snapshot.
 */
export default function MemberCombobox({
    value,
    onChange,
    onSelect,
    placeholder = 'Nama pelanggan (opsional)',
    className = '',
}) {
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [highlightedIndex, setHighlightedIndex] = useState(-1);
    const debounceRef = useRef(null);
    const blurTimeout = useRef(null);

    useEffect(() => {
        return () => {
            clearTimeout(debounceRef.current);
            clearTimeout(blurTimeout.current);
        };
    }, []);

    const search = async (q) => {
        setLoading(true);
        try {
            const response = await axios.get(route('master.members.search'), {
                params: { q },
            });
            setResults(response.data);
            setHighlightedIndex(response.data.length > 0 ? 0 : -1);
        } finally {
            setLoading(false);
        }
    };

    const handleChange = (e) => {
        const text = e.target.value;
        onChange(text);
        setOpen(true);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => search(text), 300);
    };

    const handleFocus = () => {
        clearTimeout(blurTimeout.current);
        setOpen(true);
        if (results.length === 0) {
            search(value);
        }
    };

    const handleBlur = () => {
        // Beda dari SearchCombobox: sengaja TIDAK mengembalikan teks ke
        // pilihan terakhir. Teks yang diketik bebas (tanpa memilih dari
        // daftar) tetap sah sebagai nama pelanggan.
        blurTimeout.current = setTimeout(() => setOpen(false), 150);
    };

    const pickResult = (member) => {
        clearTimeout(blurTimeout.current);
        setOpen(false);
        onSelect(member);
    };

    const handleKeyDown = (e) => {
        if (!open || results.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setHighlightedIndex((i) => Math.min(i + 1, results.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setHighlightedIndex((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            if (highlightedIndex >= 0 && highlightedIndex < results.length) {
                e.preventDefault();
                pickResult(results[highlightedIndex]);
            }
        } else if (e.key === 'Escape') {
            clearTimeout(blurTimeout.current);
            setOpen(false);
        }
    };

    return (
        <div className="relative">
            <input
                type="text"
                className={
                    'block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary ' +
                    className
                }
                value={value}
                placeholder={placeholder}
                onChange={handleChange}
                onFocus={handleFocus}
                onBlur={handleBlur}
                onKeyDown={handleKeyDown}
            />
            {open && (
                <div className="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-gray-200 bg-white shadow-lg">
                    {loading && (
                        <p className="px-3 py-2 text-sm text-gray-400">Mencari...</p>
                    )}
                    {!loading && results.length === 0 && (
                        <p className="px-3 py-2 text-sm text-gray-400">
                            Tidak ada member yang cocok — teks yang diketik
                            tetap dipakai sebagai nama pelanggan.
                        </p>
                    )}
                    {!loading &&
                        results.map((result, index) => (
                            <button
                                key={result.id}
                                type="button"
                                onMouseDown={(e) => e.preventDefault()}
                                onMouseEnter={() => setHighlightedIndex(index)}
                                onClick={() => pickResult(result)}
                                className={`block w-full px-3 py-2 text-left text-sm text-gray-700 ${
                                    index === highlightedIndex
                                        ? 'bg-primary/10'
                                        : 'hover:bg-primary/10'
                                }`}
                            >
                                {result.name}
                                {result.phone ? ` — ${result.phone}` : ''}
                            </button>
                        ))}
                </div>
            )}
        </div>
    );
}
