/**
 * DokuLLM admin config page enhancements
 *
 * Adds a refresh button and datalist to each model text input on the
 * DokuWiki config manager page so the admin can pick a model from a
 * live-fetched dropdown instead of typing it manually.
 *
 * DokuWiki generates config field names as:
 *   config[plugin____PLUGINNAME____KEY]   (KEYMARKER = '____', 4 underscores)
 * and IDs as:
 *   config___plugin____PLUGINNAME____KEY  (3-underscore prefix, then the key)
 *
 * Rather than hardcoding separators, we locate inputs via partial name
 * attribute matching so this works regardless of DokuWiki version.
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
                    button.title = data.models.length + ' models loaded — click to refresh';
                    console.log('[DokuLLM] ' + provider + ': loaded ' + data.models.length + ' models');
                } else if (data.error) {
                    button.textContent = '✗';
                    button.title = data.error;
                    console.warn('[DokuLLM] ' + provider + ' fetch error: ' + data.error);
                } else {
                    button.textContent = '✗';
                    button.title = 'No models returned';
                }
            })
            .catch(function (err) {
                button.textContent = '✗';
                button.title = String(err);
                console.warn('[DokuLLM] ' + provider + ' fetch failed: ' + err);
            })
            .finally(function () {
                button.disabled = false;
                setTimeout(function () {
                    if (button.textContent !== '✓') {
                        button.textContent = '↻';
                        button.title = 'Fetch available models from the provider';
                    }
                }, 4000);
            });
    }

    /**
     * Find a plugin config input by partial name matching.
     * DokuWiki generates names as config[plugin____dokullm____KEY].
     * Using [name*="..."] avoids hardcoding the exact separator.
     *
     * @param {string} key  The config key suffix, e.g. 'openai_model'
     * @returns {HTMLElement|null}
     */
    function findInput(key) {
        return document.querySelector('input[name*="dokullm"][name*="' + key + '"]');
    }

    /**
     * Attach a datalist + refresh button to a model text input.
     *
     * @param {string} key       Config key suffix ('openai_model', etc.)
     * @param {string} provider  Provider name for the AJAX call
     */
    function setupModelField(key, provider) {
        var input = findInput(key);
        if (!input) {
            console.log('[DokuLLM] config input not found for key: ' + key);
            return;
        }
        console.log('[DokuLLM] setting up model picker for ' + provider + ' (id=' + input.id + ')');

        // Datalist for browser-native dropdown suggestions
        var datalist = document.createElement('datalist');
        datalist.id = input.id + '__datalist';
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
        console.log('[DokuLLM] admin.js loaded');
        setupModelField('openai_model',    'openai');
        setupModelField('anthropic_model', 'anthropic');
        setupModelField('ollama_model',    'ollama');
    });
}());
