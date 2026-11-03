import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));

// Dev server port and the public origin browsers use to reach it. Set VITE_DEV_ORIGIN
// (e.g. http://10.0.0.5:5173 or https://vite.example.com) when the browser isn't on
// the Docker host; defaults to http://localhost:<port>.
const devPort = Number(process.env.VITE_PORT || 5173);
const devUrl = new URL(process.env.VITE_DEV_ORIGIN || `http://localhost:${devPort}`);
const devSecure = devUrl.protocol === 'https:';
const originServer = {
    origin: devUrl.origin,
    hmr: {
        host: devUrl.hostname,
        protocol: devSecure ? 'wss' : 'ws',
        clientPort: Number(devUrl.port || (devSecure ? 443 : 80)),
    },
};

export default defineConfig({
    plugins: [
        react(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    server: {
        host: '0.0.0.0',
        port: devPort,
        strictPort: true,
        cors: true,
        ...originServer,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});