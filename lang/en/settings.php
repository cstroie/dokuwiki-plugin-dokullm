<?php
/**
 * English language file for config of DokuLLM plugin
 *
 */

$lang['provider']          = 'LLM Provider';
$lang['openai_api_url']    = 'OpenAI API Endpoint URL (any OpenAI-compatible API)';
$lang['openai_api_key']    = 'OpenAI API Key';
$lang['openai_model']      = 'OpenAI Model Name';
$lang['anthropic_api_key'] = 'Anthropic API Key';
$lang['anthropic_model']   = 'Anthropic Model Name';
$lang['ollama_host']             = 'Ollama Host - The hostname or IP address of your Ollama server';
$lang['ollama_port']             = 'Ollama Port - The port number on which Ollama is running';
$lang['ollama_model']            = 'Ollama LLM Model - Model used for text generation (provider = ollama)';
$lang['ollama_embeddings_model'] = 'Ollama Embeddings Model - The model name used for generating text embeddings';
$lang['timeout'] = 'Request Timeout (seconds)';
$lang['profile'] = 'Prompt Profile';
$lang['temperature'] = 'Temperature (0.0-1.0) - Lower values make output more focused';
$lang['top_p'] = 'Top-P (Nucleus Sampling) - Controls diversity of responses';
$lang['top_k'] = 'Top-K - Limits token selection to top K options';
$lang['min_p'] = 'Min-P - Minimum probability threshold for token selection';
$lang['show_copy_button'] = 'Show Copy Page Button in the toolbar';
$lang['replace_id'] = 'Replace Template ID When Copying';
$lang['think'] = 'Enable Thinking in LLM Responses for deeper processing';
$lang['tools'] = 'Enable Tool Usage in LLM Responses for enhanced capabilities';
$lang['enable_chromadb'] = 'Enable ChromaDB Integration - When enabled, ChromaDB features will be available for document storage and retrieval';
$lang['chroma_host'] = 'ChromaDB Host - The hostname or IP address of your ChromaDB server';
$lang['chroma_port'] = 'ChromaDB Port - The port number on which ChromaDB is running';
$lang['chroma_tenant'] = 'ChromaDB Tenant - The tenant name for ChromaDB organization';
$lang['chroma_database'] = 'ChromaDB Database - The database name within the ChromaDB tenant';
$lang['chroma_collection'] = 'ChromaDB Collection - The default collection name for document storage';
