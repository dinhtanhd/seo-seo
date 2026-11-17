import { defineConfig } from 'vite';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

export default defineConfig({
    publicDir: false,
    build: {
        outDir: 'public/build/player',
        emptyOutDir: true,
        minify: true,
        sourcemap: false,
        lib: { entry: 'resources/js/movie-player.js', name: 'TramMoviePlayer', formats: ['iife'], fileName: () => 'tram-movie-player-[hash].js' },
        rolldownOptions: { output: { hashCharacters: 'hex' } },
    },
    plugins: [{
        name: 'self-hosted-movie-player-manifest',
        generateBundle(_options, bundle) {
            const entry = Object.values(bundle).find(asset => asset.type === 'chunk' && asset.isEntry);
            if (!entry) throw new Error('Movie player entry was not built.');
            this.emitFile({ type: 'asset', fileName: 'manifest.json', source: JSON.stringify({
                script: '/build/player/' + entry.fileName, integrity: 'sha256-' + createHash('sha256').update(entry.code).digest('base64'),
                hls_version: '1.7.3', format: 'iife',
            }, null, 2) + '\n' });
            this.emitFile({ type: 'asset', fileName: 'hls.js-LICENSE.txt', source: readFileSync('node_modules/hls.js/LICENSE', 'utf8') });
        },
    }],
});
