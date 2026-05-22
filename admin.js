/**
 * DokuLLM admin config page enhancements
 *
 * Adds a refresh button and datalist to each model text input on the
 * DokuWiki config manager page so the admin can pick a model from a
 * live-fetched dropdown instead of typing it manually.
 */
(function () {
    'use strict';

    const AJAX_URL = DOKU_BASE + 'lib/exe/ajax.php';

    /**
     * Fetch available models for a provider via AJAX and populate a datalist.
     *
     * @param {string}      provider   'openai' | 'anthropic' | 'ollama'
     * @param {HTMLElement} datalist   <datalist> element to populate
     * @param {HTMLElement} button     Trigger button (shows status feedback)
     */
    function fetchModels(provider, datalist, button) {
        button.disabled = true;
        button.textContent = '…';

        const body = new FormData();
        body.append('call', 'plugin_dokullm_models');
        body.append('provider', provider);

        fetch(AJAX_URL, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.models && data.models.length > 0) {
                    datalist.innerHTML = '';
                    data.models.forEach(function (id) {
                        var opt = document.createElement('option');
                        opt.value = id;
                        datalist.appendChild(opt);
                    });
                    button.textContent = '✓';
                    button.title = data.models.length + ' models loaded';
                } else if (data.error) {
                    button.textContent = '✗';
                    button.title = data.error;
                } else {
                    button.textContent = '✗';
                    button.title = 'No models returned';
                }
            })
            .catch(function (err) {
                button.textContent = '✗';
                button.title = String(err);
            })
            .finally(function () {
                button.disabled = false;
                // Reset icon after a moment so the user can refresh again
                setTimeout(function () {
                    button.textContent = '↻';
                }, 3000);
            });
    }

    /**
     * Attach datalist + refresh button to a single config text input.
     *
     * @param {string} inputId    Element ID of the <input> in the config form
     * @param {string} provider   Provider name passed to the AJAX endpoint
     */
    function setupModelField(inputId, provider) {
        var input = document.getElementById(inputId);
        if (!input) return;

        // Datalist for browser-native dropdown suggestions
        var datalist = document.createElement('datalist');
        datalist.id = inputId + '__datalist';
        input.setAttribute('list', datalist.id);
        input.after(datalist);

        // Refresh button
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = '↻';
        button.title = 'Fetch available models from the provider';
        button.className = 'dokullm-fetch-models button';
        button.style.cssText = 'margin-left:6px;';
        button.addEventListener('click', function () {
            fetchModels(provider, datalist, button);
        });
        datalist.after(button);
    }

    document.addEventListener('DOMContentLoaded', function () {
        // DokuWiki config manager generates IDs as config___plugin___PLUGIN___KEY
        setupModelField('config___plugin___dokullm___openai_model',    'openai');
        setupModelField('config___plugin___dokullm___anthropic_model', 'anthropic');
        setupModelField('config___plugin___dokullm___ollama_model',    'ollama');
    });
}());
