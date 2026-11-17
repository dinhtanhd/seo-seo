(function (window, document) {
    'use strict';
    function initialize() {
        var player = document.getElementById('fzhm-native-player'); if (!player) return;
        var source; try { source = new URL(player.dataset.sourceUrl); if (!/^https?:$/.test(source.protocol)) return; } catch (_) { return; }
        player.preload = 'none'; player.autoplay = false; player.src = source.href;
        player.addEventListener('error', function () { var message = document.querySelector('.fzhm-player-message'); if (message) message.hidden = false; });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize); else initialize();
})(window, document);
