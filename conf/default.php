<?php
/**
 * Default settings for the dokullm plugin
 * 
 * This file defines the default configuration values for the LLM integration plugin.
 * These values can be overridden by the user in the plugin configuration.
 */

/**
 * The LLM provider to use
 *
 * Selects the API provider. 'openai' works with any OpenAI-compatible endpoint
 * (OpenAI, Ollama, LM Studio, etc.). 'anthropic' uses Anthropic's native Messages
 * API directly; in this case openai_api_url is ignored.
 *
 * @var string
 */
$conf['provider'] = 'openai';

/**
 * OpenAI API endpoint URL
 *
 * Full URL to the chat completions endpoint. Works with any OpenAI-compatible
 * API (OpenAI, Ollama, LM Studio, LocalAI, etc.).
 * Not used when provider is 'anthropic'.
 *
 * @var string
 */
$conf['openai_api_url'] = 'https://api.openai.com/v1/chat/completions';

/**
 * OpenAI API key
 *
 * Bearer token for authenticating with OpenAI or compatible APIs.
 * Leave empty for local endpoints that require no authentication.
 *
 * @var string
 */
$conf['openai_api_key'] = '';

/**
 * OpenAI model identifier
 *
 * Model name sent to the OpenAI-compatible API.
 *
 * @var string
 */
$conf['openai_model'] = 'gpt-4o-mini';

/**
 * Anthropic API key
 *
 * API key for authenticating with Anthropic's Messages API.
 * Sent as the x-api-key header. Only used when provider is 'anthropic'.
 *
 * @var string
 */
$conf['anthropic_api_key'] = '';

/**
 * Anthropic model identifier
 *
 * Model name sent to the Anthropic Messages API.
 * Only used when provider is 'anthropic'.
 *
 * @var string
 */
$conf['anthropic_model'] = 'claude-sonnet-4-6';

/**
 * Ollama Host
 * 
 * The hostname or IP address of your Ollama server.
 * Used for generating embeddings for document search.
 * 
 * @var string
 */
$conf['ollama_host'] = '127.0.0.1';

/**
 * Ollama Port
 * 
 * The port number on which Ollama is running.
 * Default Ollama port is 11434.
 * 
 * @var int
 */
$conf['ollama_port'] = 11434;

/**
 * Ollama LLM model identifier
 *
 * Model used for text generation when provider is 'ollama'.
 * Uses the same ollama_host / ollama_port as the embeddings service.
 *
 * @var string
 */
$conf['ollama_model'] = 'llama3.2';

/**
 * Ollama Embeddings Model
 *
 * The model name used for generating text embeddings.
 * Embeddings are used for semantic search in ChromaDB.
 *
 * @var string
 */
$conf['ollama_embeddings_model'] = 'nomic-embed-text';

/**
 * The request timeout in seconds
 * 
 * Maximum time to wait for a response from the LLM API before timing out.
 * Set to 30 seconds by default, which should be sufficient for most requests.
 * 
 * @var int
 */
$conf['timeout'] = 30;

/**
 * The profile for prompts
 * 
 * Specifies which profile to use for the prompts.
 * User prompts can be classified in multiple profiles. By default, 'default'.
 * 
 * @var string
 */
$conf['profile'] = 'default';

/**
 * The temperature setting for the LLM
 * 
 * Controls the randomness of the LLM output. Lower values (0.0-0.5) make the output
 * more deterministic and focused, while higher values (0.5-1.0) make it more random
 * and creative. Default is 0.3 for consistent, high-quality responses.
 * 
 * @var float
 */
$conf['temperature'] = 0.3;

/**
 * The top-p (nucleus sampling) setting for the LLM
 * 
 * Controls the cumulative probability of token selection. Lower values (0.1-0.5) make
 * the output more focused, while higher values (0.5-1.0) allow for more diverse outputs.
 * Default is 0.8 for a good balance between creativity and coherence.
 * 
 * @var float
 */
$conf['top_p'] = 0.8;

/**
 * The top-k setting for the LLM
 * 
 * Limits the number of highest probability tokens considered for each step.
 * Lower values (1-10) make the output more focused, while higher values (10-50)
 * allow for more diverse outputs. Default is 20 for balanced diversity.
 * 
 * @var int
 */
$conf['top_k'] = 20;

/**
 * The min-p setting for the LLM
 * 
 * Sets a minimum probability threshold for token selection. Tokens with probabilities
 * below this threshold are filtered out. Default is 0.0 (no filtering).
 * 
 * @var float
 */
$conf['min_p'] = 0.0;

/**
 * Show copy button in the toolbar
 * 
 * Controls whether the copy page button is displayed in the LLM toolbar.
 * When true, the copy button will be visible; when false, it will be hidden.
 * 
 * @var bool
 */
$conf['show_copy_button'] = true;

/**
 * Replace ID in template content
 * 
 * Controls whether the template page ID should be replaced with the new page ID
 * when copying a page with a template. When true, the template ID will be replaced;
 * when false, it will be left as is.
 * 
 * @var bool
 */
$conf['replace_id'] = true;

/**
 * Enable thinking in LLM responses
 * 
 * Controls whether the LLM should engage in deeper thinking processes before responding.
 * When true, the LLM will use thinking capabilities and may take longer to respond;
 * when false, it will provide direct responses without extended thinking.
 * 
 * @var bool
 */
$conf['think'] = false;

/**
 * Thinking budget in tokens (Anthropic extended thinking only)
 *
 * Maximum number of tokens the model may use for its internal reasoning
 * before producing a visible response. Only used when provider=anthropic
 * and think=true. Ignored by other providers.
 *
 * @var int
 */
$conf['think_budget'] = 5000;

/**
 * Enable tool usage in LLM responses
 * 
 * Controls whether the LLM can use tools to enhance its responses.
 * When true, the LLM can call tools like get_document, get_template, and get_examples;
 * when false, these tools will not be available to the LLM.
 * 
 * @var bool
 */
$conf['tools'] = false;

/**
 * Enable ChromaDB integration
 * 
 * Controls whether ChromaDB integration is enabled for document storage and retrieval.
 * When true, ChromaDB features will be available; when false, they will be disabled.
 * 
 * @var bool
 */
$conf['enable_chromadb'] = 0;

/**
 * ChromaDB Host
 * 
 * The hostname or IP address of your ChromaDB server.
 * This is used for document storage and retrieval.
 * 
 * @var string
 */
$conf['chroma_host'] = '127.0.0.1';

/**
 * ChromaDB Port
 * 
 * The port number on which ChromaDB is running.
 * Default ChromaDB port is 8000, but can be customized.
 * 
 * @var int
 */
$conf['chroma_port'] = 8000;

/**
 * ChromaDB Tenant
 * 
 * The tenant name for ChromaDB organization.
 * Used to isolate data between different organizations or projects.
 * 
 * @var string
 */
$conf['chroma_tenant'] = 'dokullm';

/**
 * ChromaDB Database
 * 
 * The database name within the ChromaDB tenant.
 * Used to organize collections within a tenant.
 * 
 * @var string
 */
$conf['chroma_database'] = 'dokullm';

/**
 * ChromaDB Default Collection
 *
 * Fallback collection name used when the page ID does not provide one.
 * Collections are used to group related documents.
 *
 * @var string
 */
$conf['chroma_default_collection'] = 'documents';
