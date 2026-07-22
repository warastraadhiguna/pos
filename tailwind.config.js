import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // Sumber tunggal warna brand (dari logo WAnPOS) — jangan hardcode
            // hex/indigo-*/blue-* di komponen, selalu lewat token ini.
            colors: {
                primary: {
                    DEFAULT: '#0146BB',
                    dark: '#012A72',
                },
                // Aksen kecil saja (garis, ikon, badge, border) — JANGAN
                // dipakai sebagai latar sesuatu yang menahan teks putih,
                // kontrasnya gagal WCAG (lihat docs/PRINCIPLES.md kalau ada
                // catatan desain, atau laporan ekstraksi warna terkait).
                accent: {
                    DEFAULT: '#03ACAB',
                },
            },
        },
    },

    plugins: [forms],
};
