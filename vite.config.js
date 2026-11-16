import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Storefront assets. No web fonts (fast LCP, no third-party requests).
export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/css/help.css', 'resources/js/app.js'], refresh: true }),
        tailwindcss(),
    ],
    build: { cssMinify: true },
    server: { watch: { ignored: ['**/storage/framework/views/**'] } },
});
