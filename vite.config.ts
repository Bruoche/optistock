import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        // Wayfinder shells out to `php artisan` at build time. The Docker assets
        // stage has no PHP, so the helpers are generated in a PHP stage and copied
        // in; WAYFINDER_SKIP disables the plugin there. Local/dev builds keep it.
        ...(process.env.WAYFINDER_SKIP
            ? []
            : [wayfinder({ formVariants: true })]),
    ],
});
