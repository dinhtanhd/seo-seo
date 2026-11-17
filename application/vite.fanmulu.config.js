import { defineConfig } from 'vite';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

export default defineConfig({
    publicDir: false,
    build: {
        outDir: 'public/build/fanmulu',
        // Historical publications retain the exact asset referenced in their snapshot.
        emptyOutDir: false,
        minify: true,
        sourcemap: false,
        lib: { entry: 'resources/js/fanmulu-runtime.js', name: 'TramFanmuluRuntime', formats: ['iife'], fileName: () => 'tram-fanmulu-runtime-[hash].js' },
        rolldownOptions: { output: { hashCharacters: 'hex' } },
    },
    plugins: [{name: 'self-hosted-fanmulu-runtime-manifest', generateBundle(_options, bundle) {
        const entry = Object.values(bundle).find(asset => asset.type === 'chunk' && asset.isEntry);
        if (!entry) throw new Error('Fanmulu runtime entry was not built.');
        this.emitFile({type: 'asset', fileName: 'manifest.json', source: JSON.stringify({
            script: '/build/fanmulu/' + entry.fileName, hash: createHash('sha256').update(entry.code).digest('hex'), context_version: 3,
        }, null, 2) + '\n'});
        const adapter = readFileSync(new URL('./resources/js/imported-movie-theme-runtime.js', import.meta.url));
        const adapterHash = createHash('sha256').update(adapter).digest('hex');
        this.emitFile({type: 'asset', fileName: `imported-theme/${adapterHash}/tram-imported-runtime.js`, source: adapter});
    }}],
});
