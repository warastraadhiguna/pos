import SearchCombobox from '@/Components/SearchCombobox';

function supplierLabel(supplier) {
    return supplier.name;
}

/**
 * Search-as-you-type supplier picker. Never loads the full supplier list —
 * see SearchCombobox for the shared search/keyboard-nav behavior.
 */
export default function SupplierCombobox({ initialItem = null, onSelect, placeholder = 'Cari nama supplier...', className = '' }) {
    return (
        <SearchCombobox
            searchRoute="master.suppliers.search"
            getLabel={supplierLabel}
            initialItem={initialItem}
            onSelect={onSelect}
            placeholder={placeholder}
            className={className}
            noResultsText="Tidak ada supplier yang cocok."
        />
    );
}
