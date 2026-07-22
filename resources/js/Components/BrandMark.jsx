// `variant="square"` (default): lockup ikon+teks yang ditumpuk (persegi) —
// dipakai di badge kecil sidebar (h-10 w-10), pas untuk ruang sempit.
// `variant="wide"`: lockup ikon+teks berdampingan (memanjang) — dipakai di
// tempat yang punya ruang horizontal lebih lega (mis. panel login), supaya
// tidak memaksakan gambar persegi ke ruang yang seharusnya memanjang (atau
// sebaliknya) yang membuatnya terlihat janggal.
export default function BrandMark({ className = '', variant = 'square' }) {
    if (variant === 'wide') {
        return (
            <div
                className={
                    'inline-flex items-center rounded-xl bg-white px-4 py-2 shadow-lg shadow-black/10 ' +
                    className
                }
            >
                <img
                    src="/images/logo-wide.png"
                    alt="WAnPOS"
                    className="h-8 w-auto object-contain"
                />
            </div>
        );
    }

    return (
        <div className={'flex items-center gap-3 ' + className}>
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white shadow-lg shadow-black/10">
                <img
                    src="/images/logo.png"
                    alt="WAnPOS"
                    className="h-8 w-8 object-contain"
                />
            </span>
        </div>
    );
}
