/**
 * DokuLLM admin config page — model list refresh button
 *
 * Model dropdowns are now rendered server-side (PHP multichoice) from a
 * JSON cache file. This script only adds a single "Refresh model lists"
 * button to the dokullm config section. Clicking it forces a cache update
 * for all providers via AJAX, then reloads the page so the fresh dropdowns
 * appear immediately.
 */
(function () {
    'use strict';

    const AJAX_URL = DOKU_BASE + 'lib/exe/ajax.php';

    /** Refresh model cache for one provider; returns a Promise. */
    function refreshProvider(provider) {
        const body = new FormData();
        body.append('call', 'plugin_dokullm_models');
        body.append('provider', provider);
        return fetch(AJAX_URL, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) throw new Error(provider + ': ' + data.error);
                return data.models ? data.models.length : 0;
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Find the dokullm config section heading to anchor the button near it
        var heading = document.querySelector('fieldset legend, h2, h3');
        var allFieldsets = document.querySelectorAll('fieldset');
        var targetFieldset = null;

        // Locate the fieldset that contains a dokullm config input
        allFieldsets.forEach(function (fs) {
            if (fs.querySelector('input[name*="dokullm"], select[name*="dokullm"]')) {
                targetFieldset = fs;
            }
        });

        if (!targetFieldset) return;   // not on the config page

        // Build the refresh button
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = '↻ Refresh model lists';
        btn.className = 'button';
        btn.style.cssText = 'margin:8px 0 4px 0;display:block;';
        btn.title = 'Re-fetch available models from all configured providers and reload the page';

        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = '… Fetching models';

            var providers = ['openai', 'anthropic', 'ollama', 'ollama_embeddings'];
            Promise.allSettled(providers.map(refreshProvider))
                .then(function (results) {
                    var errors = results
                        .filter(function (r) { return r.status === 'rejected'; })
                        .map(function (r) { return r.reason.message; });

                    if (errors.length > 0) {
                        console.warn('[DokuLLM] Some providers failed:', errors.join('; '));
                    }
                    // Reload so PHP re-reads the fresh cache files
                    window.location.reload();
                });
        });

        // Insert the button at the top of the dokullm fieldset
        var legend = targetFieldset.querySelector('legend');
        if (legend) {
            legend.after(btn);
        } else {
            targetFieldset.prepend(btn);
        }
    });
}());
