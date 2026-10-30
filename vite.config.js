import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // dashboards and auth (Inertia + React + Tailwind)
                'resources/css/app.css',
                'resources/js/app.tsx',
                // guest invitations (Blade + vanilla JS, no framework runtime)
                'resources/css/invite.css',
                'resources/js/invite.js',
                // guest album and the hall's live photo wall (phase 7B)
                'resources/css/album.css',
                'resources/js/album.js',
                'resources/css/wall.css',
                'resources/js/wall.js',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
