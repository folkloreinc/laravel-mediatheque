import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// One script and one stylesheet with stable names, committed in ../dist and
// served by the package: the application builds nothing.
export default defineConfig({
    plugins: [react()],
    build: {
        outDir: '../dist',
        emptyOutDir: true,
        cssCodeSplit: false,
        sourcemap: false,
        // React, React Aria and react-intl, in one file the page loads once
        chunkSizeWarningLimit: 700,
        rolldownOptions: {
            input: 'src/main.tsx',
            output: {
                entryFileNames: 'dashboard.js',
                assetFileNames: 'dashboard[extname]',
                codeSplitting: false,
            },
        },
    },
    test: {
        environment: 'jsdom',
        setupFiles: ['src/setup.ts'],
        // @panneau/ui imports its CSS: let Vite transform it
        server: { deps: { inline: [/@panneau\//] } },
    },
});
