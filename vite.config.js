import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

const systemSans = ['ui-sans-serif', 'system-ui', 'Segoe UI', 'Tahoma', 'sans-serif'];

// Self-hosted WOFF2 from node_modules, one file per weight and script. The `fontsource()` provider is not
// used on purpose: it emits a separate @font-face for the .woff twin of every file, and browsers then
// download both (about 125 KB wasted on an Arabic page).
const plex = (pkg, file) => `node_modules/@fontsource/${pkg}/files/${file}.woff2`;
const variants = (pkg, subset, weights) =>
    weights.map((weight) => ({ src: plex(pkg, `${pkg}-${subset}-${weight}-normal`), weight }));

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Arabic pages load Plex Sans Arabic (Arabic script file + a Latin companion for digits and
            // English words) only; English pages load Plex Sans + Playfair only, so neither language
            // downloads the other's glyphs (the layout picks the aliases).
            fonts: [
                local('IBM Plex Sans Arabic', {
                    alias: 'ibm-plex-sans-arabic',
                    variants: variants('ibm-plex-sans-arabic', 'arabic', [400, 700]),
                    fallbacks: ['IBM Plex Sans Arabic Latin', ...systemSans],
                    optimizedFallbacks: false,
                }),
                local('IBM Plex Sans Arabic Latin', {
                    alias: 'ibm-plex-sans-arabic-latin',
                    variants: variants('ibm-plex-sans-arabic', 'latin', [400, 700]),
                    optimizedFallbacks: false,
                }),
                local('IBM Plex Sans', {
                    alias: 'ibm-plex-sans',
                    variants: variants('ibm-plex-sans', 'latin', [400, 700]),
                    fallbacks: systemSans,
                    optimizedFallbacks: false,
                }),
                local('Playfair Display', {
                    alias: 'playfair-display',
                    variants: variants('playfair-display', 'latin', [700]),
                    fallbacks: ['Iowan Old Style', 'Georgia', 'Times New Roman', 'serif'],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    build: {
        // Tailwind's one-off scan dominates a ~4 s build; the timing hint is noise, not a problem.
        rolldownOptions: { checks: { bundlerTimings: false } },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
