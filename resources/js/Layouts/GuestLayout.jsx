import BrandMark from '@/Components/BrandMark';

const features = [
    {
        title: 'Kasir secepat kilat',
        description: 'Transaksi jalan dalam hitungan detik, online maupun offline.',
    },
    {
        title: 'Stok selalu akurat',
        description: 'Moving average cost dihitung otomatis di setiap pergerakan barang.',
    },
    {
        title: 'Akuntansi rapi',
        description: 'Setiap transaksi otomatis jadi jurnal double-entry yang seimbang.',
    },
];

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen bg-gray-50">
            <div className="relative hidden w-full max-w-md flex-col justify-between overflow-hidden bg-gray-900 px-10 py-12 lg:flex xl:max-w-lg">
                <div
                    className="pointer-events-none absolute inset-0 opacity-40"
                    style={{
                        backgroundImage:
                            'radial-gradient(circle at 20% 20%, rgba(99,102,241,0.35), transparent 45%), radial-gradient(circle at 80% 70%, rgba(79,70,229,0.35), transparent 45%)',
                    }}
                />

                <div className="relative">
                    <BrandMark variant="wide" />

                    <p className="mt-16 text-3xl font-semibold leading-tight text-white">
                        Satu sistem untuk kasir, stok, dan pembukuan toko
                        Anda.
                    </p>
                </div>

                <ul className="relative space-y-6">
                    {features.map((feature) => (
                        <li key={feature.title} className="flex gap-3">
                            <span className="mt-1 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-accent/20 text-accent">
                                <svg
                                    className="h-3 w-3"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                >
                                    <path
                                        fillRule="evenodd"
                                        d="M16.7 5.3a1 1 0 010 1.4l-7 7a1 1 0 01-1.4 0l-3-3a1 1 0 111.4-1.4l2.3 2.29 6.3-6.29a1 1 0 011.4 0z"
                                        clipRule="evenodd"
                                    />
                                </svg>
                            </span>
                            <div>
                                <p className="text-sm font-medium text-white">
                                    {feature.title}
                                </p>
                                <p className="mt-0.5 text-sm text-gray-400">
                                    {feature.description}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>

                <p className="relative text-xs text-gray-500">
                    &copy; {new Date().getFullYear()} POS Akuntansi.
                </p>
            </div>

            <div className="flex flex-1 flex-col items-center justify-center px-6 py-12 sm:px-10">
                <div className="mb-8 lg:hidden">
                    <BrandMark variant="wide" />
                </div>

                <div className="w-full sm:max-w-sm">{children}</div>
            </div>
        </div>
    );
}
