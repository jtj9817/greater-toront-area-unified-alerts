import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    test: {
        globals: true,
        environment: 'jsdom',
        setupFiles: './resources/js/tests/setup.ts',
        include: ['resources/js/**/*.test.{ts,tsx}'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json', 'html'],
            include: ['resources/js/features/gta-alerts/**/*.{ts,tsx}'],
            exclude: [
                '**/*.d.ts',
                'resources/js/**/index.{ts,tsx}',
                'resources/js/app.{ts,tsx}',
                'resources/js/ssr.{ts,tsx}',
                'resources/js/tests/**',
            ],
            thresholds: {
                lines: 50,
                functions: 20,
                branches: 35,
                statements: 50,
            },
        },
    },
});
