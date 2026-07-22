import SearchCombobox from '@/Components/SearchCombobox';

function itemLabel(item) {
    return `${item.sku} — ${item.name}${item.base_uom ? ` (${item.base_uom.code})` : ''}`;
}

/**
 * Search-as-you-type item picker. Never loads the full item catalog — see
 * SearchCombobox for the shared search/keyboard-nav behavior.
 */
export default function ItemCombobox({ initialItem = null, onSelect, placeholder = 'Cari SKU atau nama item...', className = '' }) {
    return (
        <SearchCombobox
            searchRoute="master.items.search"
            getLabel={itemLabel}
            initialItem={initialItem}
            onSelect={onSelect}
            placeholder={placeholder}
            className={className}
            noResultsText="Tidak ada item yang cocok."
        />
    );
}
