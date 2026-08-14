import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    // Vite runs inside the `vite` compose service, so it must bind to all
    // interfaces, advertise a host-reachable origin for asset URLs and the
    // public/hot file, and poll for changes because inotify events do not
    // cross Docker Desktop bind mounts on macOS.
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5173',
        // The app is served from a different port than the dev server, so every
        // module script is a cross-origin request. Vite 8 restricts CORS to the
        // configured `origin` by default, which makes the browser block all of
        // them and render a blank page. Allow any localhost port in development.
        cors: {
            origin: /^https?:\/\/(?:localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/,
        },
        hmr: {
            host: 'localhost',
        },
        watch: {
            usePolling: true,
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
});
