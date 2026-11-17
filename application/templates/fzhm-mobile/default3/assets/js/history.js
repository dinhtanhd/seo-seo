/* Browser-local playback history; no source-site cookies, eval, or HTML interpolation. */
(function (window, document) {
    'use strict';
    function remember() { var state = document.getElementById('fzhm-runtime-state'); if (state && window.FzhmUI) window.FzhmUI.record({title: state.dataset.title, url: window.location.href, episode: state.dataset.episodeTitle}); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', remember); else remember();
})(window, document);
