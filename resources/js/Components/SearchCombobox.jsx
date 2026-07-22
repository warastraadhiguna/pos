import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

/**
 * Generic search-as-you-type picker. Never loads a full list up front — it
 * only ever holds the currently selected item plus whatever short list the
 * server just returned for the current query, so this stays cheap no matter
 * how large the underlying table is (items, suppliers, or anything else).
 *
 * Keyboard: type -> Arrow Up/Down moves the highlight -> Enter picks the
 * highlighted result -> Escape cancels and reverts to the last selection.
 *
 * ItemCombobox/SupplierCombobox are thin wrappers around this — they only
 * supply what's specific to their entity (search route, result label).
 */
export default function SearchCombobox({
    searchRoute,
    getLabel,
    initialItem = null,
    onSelect,
    placeholder = 'Cari...',
    className = '',
    noResultsText = 'Tidak ada hasil yang cocok.',
}) {
    const [query, setQuery] = useState(initialItem ? getLabel(initialItem) : '');
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [highlightedIndex, setHighlightedIndex] = useState(-1);
    const debounceRef = useRef(null);
    const blurTimeout = useRef(null);
    const committedLabel = useRef(initialItem ? getLabel(initialItem) : '');

    useEffect(() => {
        return () => {
            clearTimeout(debounceRef.current);
            clearTimeout(blurTimeout.current);
        };
    }, []);

    const search = async (q) => {
        setLoading(true);
        try {
            const response = await axios.get(route(searchRoute), {
                params: { q },
            });
            setResults(response.data);
            setHighlightedIndex(response.data.length > 0 ? 0 : -1);
        } finally {
            setLoading(false);
        }
    };

    const handleChange = (e) => {
        const value = e.target.value;
        setQuery(value);
        setOpen(true);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => search(value), 300);
    };

    const handleFocus = () => {
        clearTimeout(blurTimeout.current);
        setOpen(true);
        if (results.length === 0) {
            search(query);
        }
    };

    const handleBlur = () => {
        // Delay closing so a click on a result registers before the
        // dropdown disappears, then snap back to the last real selection —
        // an unselected in-progress search shouldn't linger in the box.
        blurTimeout.current = setTimeout(() => {
            setOpen(false);
            setQuery(committedLabel.current);
        }, 150);
    };

    const pickResult = (result) => {
        clearTimeout(blurTimeout.current);
        committedLabel.current = getLabel(result);
        setQuery(committedLabel.current);
        setOpen(false);
        onSelect(result);
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
            setQuery(committedLabel.current);
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
                value={query}
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
                        <p className="px-3 py-2 text-sm text-gray-400">{noResultsText}</p>
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
                                {getLabel(result)}
                            </button>
                        ))}
                </div>
            )}
        </div>
    );
}
