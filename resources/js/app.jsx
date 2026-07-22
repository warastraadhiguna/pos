import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    // Inertia's progress config needs a raw CSS color (bukan kelas Tailwind,
    // jadi tidak bisa lewat token `primary`) — nilai ini WAJIB tetap sama
    // dengan `primary` di tailwind.config.js kalau palet berubah lagi.
    progress: {
        color: '#0146BB',
    },
});
