import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/images/logo-600.png', 'resources/images/google-logo.png'],
            refresh: true,
            fonts: [
                bunny('Plus Jakarta Sans', {
                    weights: [400, 500, 600, 700, 800],
                }),
                bunny('IBM Plex Mono', {
                    weights: [400, 500],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        // Bind on all interfaces so the dev server is reachable from the Lando
        // containers and from the browser, not just the host loopback.
        host: '0.0.0.0',
        // The app is served over https by Lando's proxy, so the asset URLs the
        // Laravel plugin injects must point at a host the browser can reach.
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
