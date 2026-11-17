<section class="tram-movie-player" data-tram-player data-tram-src="{{ $play['source_url'] }}" data-tram-type="{{ $type }}" aria-label="影片播放器">
    <video data-tram-video controls playsinline preload="none" aria-label="{{ $play['episode']['title'] ?? '影片' }}"></video>
    <div class="tram-player-toolbar"><button type="button" data-tram-play>播放</button><button type="button" data-tram-retry hidden>重试</button><p data-tram-status role="status" aria-live="polite">点击播放 · {{ $type === 'hls' ? 'M3U8 / HLS' : 'HTML5' }}</p><a href="{{ $play['navigation_url'] ?? $play['source_url'] }}" target="_blank" rel="noopener noreferrer nofollow">{{ $play['navigation_label'] ?? '打开来源' }}</a></div>
    <noscript><p>{{ $play['noscript_message'] ?? '需要启用 JavaScript 才能使用 HLS 播放器；也可打开来源链接。' }}</p></noscript>
</section>
<style>
.play-source:has(> .tram-movie-player),#fzhm-player-surface:has(> .tram-movie-player){position:relative!important;height:auto!important;min-height:0!important;aspect-ratio:auto!important;padding:0!important;overflow:visible!important}
.tram-movie-player{position:relative!important;inset:auto!important;width:100%;height:auto!important;min-height:0!important;background:#080c12;color:#f5f5f5;box-sizing:border-box}
.tram-movie-player > video[data-tram-video]{position:static!important;inset:auto!important;display:block;width:100%;height:auto!important;min-height:0!important;aspect-ratio:16/9;background:#000;max-height:75vh;object-fit:contain}
.tram-movie-player > .tram-player-toolbar{position:relative!important;inset:auto!important;height:auto!important;min-height:0!important;display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px;font-size:14px;line-height:1.5}
.tram-player-toolbar p{margin:0;flex:1;min-width:160px}.tram-player-toolbar button{border:0;border-radius:5px;background:#ff8b23;color:#fff;padding:9px 18px;cursor:pointer}.tram-player-toolbar a{color:#b9d6ff}.tram-player-toolbar [hidden]{display:none!important}
</style>
