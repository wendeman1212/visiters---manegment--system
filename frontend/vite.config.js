import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import os from 'node:os';

/**
 * LAN IPv4 address of this machine.
 *
 * The dev server must be reachable from phones/tablets on the same Wi-Fi and
 * the URLs it injects (the hot file, module URLs) must never point at
 * "localhost" — a phone that loads http://localhost:5173 would be loading its
 * own loopback interface instead of this computer.
 */
function lanAddress(override) {
    const preferred = [];
    const fallback = [];

    for (const nets of Object.values(os.networkInterfaces())) {
        for (const net of nets || []) {
            if (net.family !== 'IPv4' || net.internal) continue;
            const isPrivate = /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(net.address);
            (isPrivate ? preferred : fallback).push(net.address);
        }
    }

    return override || preferred[0] || fallback[0] || null;
}

export default defineConfig(({ mode }) => {
    // Read VITE_LAN_IP from frontend/.env (Vite does not expose .env files to
    // process.env when the config is evaluated), so the dev server advertises
    // the same host as the app/QR URL instead of a random network interface.
    const env = loadEnv(mode, process.cwd(), '');
    const LAN_IP = lanAddress(env.VITE_LAN_IP || process.env.VITE_LAN_IP);
    const DEV_ORIGIN = LAN_IP ? `http://${LAN_IP}:5173` : undefined;

    return {
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'src/app.jsx'],
            refresh: true,
            publicDirectory: '../backend/public',
        }),
        react(),
    ],
    server: {
        // Listen on every interface so devices on the same Wi-Fi/LAN can reach it.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,

        // Injected asset URLs (and the Laravel hot file) use the LAN address
        // instead of localhost, so a phone that scans a QR code can load them.
        origin: DEV_ORIGIN,

        // The app may be opened via localhost on this PC, via the LAN IP from a
        // phone, or through the Cloudflare Quick Tunnel hostname — allow all.
        cors: true,
        allowedHosts: true,

        watch: {
            ignored: ['**/../backend/storage/framework/views/**'],
        },
        proxy: {
            '^/$': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/about': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/features': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/guidelines': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/faq': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/contact': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/request-visit': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/login': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/register': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/verify-email': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/logout': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/forgot-password': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/reset-password': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/profile': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/representative': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/visit-requests': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/cron': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/ceo': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/admin': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/host': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/security': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/system-admin': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
'/api': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/qr': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/messages': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/notifications': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/visitor': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/reschedule-requests': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
            '/up': {
                target: 'http://localhost:8000',
                changeOrigin: true,
            },
        },
    },
    };
});
