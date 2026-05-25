<?php
/**
 * Options for the dokullm plugin
 *
 * This file defines the configuration metadata for the LLM integration plugin.
 * It specifies the type and validation rules for each configuration option.
 */

/**
 * Load cached model list for a provider.
 * Returns array of model ID strings, or null if the cache file is absent/empty.
 * Always prepends $current so the saved value stays valid even if it is no
 * longer returned by the provider (avoids multichoice validation failures).
 */
function _dokullm_model_choices($provider, $current) {
    global $conf;
    if (empty($conf['savedir'])) return null;
    $file = $conf['savedir'] . '/tmp/plugin_dokullm_models_' . $provider . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    $models = $data['models'] ?? [];
    if (!is_array($models) || empty($models)) return null;
    if ($current !== '' && !in_array($current, $models, true)) {
        array_unshift($models, $current);
    }
    return $models;
}

/**
 * Metadata for the provider configuration option
 *
 * Selects the LLM provider: 'openai' for any OpenAI-compatible API,
 * 'anthropic' for Anthropic's native Messages API.
 *
 * @var array
 */
$meta['provider'] = array('multichoice', '_choices' => array('openai', 'anthropic', 'ollama'));

/**
 * Metadata for the OpenAI API URL configuration option
 *
 * @var array
 */
$meta['openai_api_url'] = array('string');

/**
 * Metadata for the OpenAI API key configuration option
 *
 * @var array
 */
$meta['openai_api_key'] = array('password');

/**
 * Metadata for the OpenAI model configuration option
 * Rendered as a dropdown when a cached model list is available.
 *
 * @var array
 */
$_om = _dokullm_model_choices('openai', $conf['plugin']['dokullm']['openai_model'] ?? '');
$meta['openai_model'] = $_om ? array('multichoice', '_choices' => $_om) : array('string');

/**
 * Metadata for the Anthropic API key configuration option
 *
 * @var array
 */
$meta['anthropic_api_key'] = array('password');

/**
 * Metadata for the Anthropic model configuration option
 * Rendered as a dropdown when a cached model list is available.
 *
 * @var array
 */
$_am = _dokullm_model_choices('anthropic', $conf['plugin']['dokullm']['anthropic_model'] ?? '');
$meta['anthropic_model'] = $_am ? array('multichoice', '_choices' => $_am) : array('string');

/**
 * Metadata for the timeout configuration option
 * 
 * Defines the timeout value as a numeric input field with a minimum value of 5 seconds.
 * This prevents users from setting an unreasonably low timeout value.
 * 
 * @var array
 */
$meta['timeout'] = array('numeric', '_min' => 5);

/**
 * Metadata for the profile configuration option
 * 
 * Defines the profile as a string input field in the configuration interface.
 * User prompts can be classified in multiple profiles. By default, 'default'.
 * 
 * @var array
 */
$meta['profile'] = array('string');

/**
 * Metadata for the temperature configuration option
 * 
 * Defines the temperature as a numeric field with a range from 0.0 to 1.0,
 * with a default of 0.3. This controls the randomness of the LLM responses.
 * 
 * @var array
 */
$meta['temperature'] = array('numeric', '_min' => 0.0, '_max' => 1.0, '_pattern' => '/^\d+(\.\d+)?$/');

/**
 * Metadata for the top-p configuration option
 * 
 * Defines the top-p (nucleus sampling) as a numeric field with a range from 0.0 to 1.0,
 * with a default of 0.8. This controls the cumulative probability of token selection.
 * 
 * @var array
 */
$meta['top_p'] = array('numeric', '_min' => 0.0, '_max' => 1.0, '_pattern' => '/^\d+(\.\d+)?$/');

/**
 * Metadata for the top-k configuration option
 * 
 * Defines the top-k as a numeric field with a minimum value of 1,
 * with a default of 20. This controls the number of highest probability tokens considered.
 * 
 * @var array
 */
$meta['top_k'] = array('numeric', '_min' => 1);

/**
 * Metadata for the min-p configuration option
 * 
 * Defines the min-p as a numeric field with a range from 0.0 to 1.0,
 * with a default of 0.0. This controls the minimum probability threshold for token selection.
 * 
 * @var array
 */
$meta['min_p'] = array('numeric', '_min' => 0.0, '_max' => 1.0, '_pattern' => '/^\d+(\.\d+)?$/');

/**
 * Metadata for the show_copy_button configuration option
 * 
 * Defines whether the copy button should be shown as a boolean field.
 * 
 * @var array
 */
$meta['show_copy_button'] = array('onoff');

/**
 * Metadata for the replace_id configuration option
 * 
 * Defines whether the template ID should be replaced with the new page ID
 * when copying a page with a template.
 * 
 * @var array
 */
$meta['replace_id'] = array('onoff');

/**
 * Metadata for the think configuration option
 * 
 * Defines whether the LLM should engage in deeper thinking processes before responding.
 * When enabled, the LLM will use thinking capabilities; when disabled, it will provide direct responses.
 * 
 * @var array
 */
$meta['think'] = array('onoff');

/**
 * Metadata for the think_budget configuration option
 */
$meta['think_budget'] = array('numeric', '_min' => 1024);

/**
 * Metadata for the tools configuration option
 *
 * Defines whether the LLM can use tools to enhance its responses.
 *
 * @var array
 */
$meta['tools'] = array('onoff');

/**
 * Metadata for the enable_chromadb configuration option
 * 
 * Defines whether ChromaDB integration is enabled.
 * When enabled, ChromaDB features will be available; when disabled, they will be hidden.
 * 
 * @var array
 */
$meta['enable_chromadb'] = array('onoff');

/**
 * Metadata for the ChromaDB host configuration option
 * 
 * Defines the ChromaDB host as a string input field in the configuration interface.
 * 
 * @var array
 */
$meta['chroma_host'] = array('string');

/**
 * Metadata for the ChromaDB port configuration option
 * 
 * Defines the ChromaDB port as a numeric input field.
 * 
 * @var array
 */
$meta['chroma_port'] = array('numeric');

/**
 * Metadata for the ChromaDB tenant configuration option
 * 
 * Defines the ChromaDB tenant as a string input field in the configuration interface.
 * 
 * @var array
 */
$meta['chroma_tenant'] = array('string');

/**
 * Metadata for the ChromaDB database configuration option
 * 
 * Defines the ChromaDB database as a string input field in the configuration interface.
 * 
 * @var array
 */
$meta['chroma_database'] = array('string');

/**
 * Metadata for the ChromaDB default collection configuration option
 *
 * Fallback collection used when the page ID provides no collection segment.
 *
 * @var array
 */
$meta['chroma_default_collection'] = array('string');

/**
 * Metadata for the Ollama host configuration option
 * 
 * Defines the Ollama host as a string input field in the configuration interface.
 * 
 * @var array
 */
$meta['ollama_host'] = array('string');

/**
 * Metadata for the Ollama port configuration option
 * 
 * Defines the Ollama port as a numeric input field.
 * 
 * @var array
 */
$meta['ollama_port'] = array('numeric');

/**
 * Metadata for the Ollama LLM model configuration option
 * Rendered as a dropdown when a cached model list is available.
 *
 * @var array
 */
$_lm = _dokullm_model_choices('ollama', $conf['plugin']['dokullm']['ollama_model'] ?? '');
$meta['ollama_model'] = $_lm ? array('multichoice', '_choices' => $_lm) : array('string');

/**
 * Metadata for the Ollama embeddings model configuration option
 * Rendered as a dropdown when a cached model list is available.
 *
 * @var array
 */
$_em = _dokullm_model_choices('ollama_embeddings', $conf['plugin']['dokullm']['ollama_embeddings_model'] ?? '');
$meta['ollama_embeddings_model'] = $_em ? array('multichoice', '_choices' => $_em) : array('string');
