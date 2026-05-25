<?php
namespace dokuwiki\plugin\dokullm;

use Exception;

/**
 * LLM Client for the DokuLLM plugin
 *
 * This class provides methods to interact with an LLM API for various
 * text processing tasks such as completion, rewriting, grammar correction,
 * summarization, conclusion creation, text analysis, and custom prompts.
 *
 * The client handles:
 * - API configuration and authentication
 * - Prompt template loading and processing
 * - Context-aware requests with metadata
 * - DokuWiki page content retrieval
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

/**
 * LLM Client class for handling API communications
 *
 * Manages configuration settings and provides methods for various
 * text processing operations through an LLM API.
 * Implements caching for tool calls to avoid duplicate processing.
 */
class LlmClient
{
    /** @var string OpenAI-compatible API endpoint URL */
    private $openai_api_url;

    /** @var string OpenAI API key (Bearer token) */
    private $openai_api_key;

    /** @var string OpenAI model identifier */
    private $openai_model;

    /** @var string Anthropic API key (x-api-key header) */
    private $anthropic_api_key;

    /** @var string Anthropic model identifier */
    private $anthropic_model;

    /** @var string Ollama server host */
    private $ollama_host;

    /** @var int Ollama server port */
    private $ollama_port;

    /** @var string Ollama LLM model identifier */
    private $ollama_model;

    /** @var array Cache for tool call results */
    private $toolCallCache = [];

    /** @var string Current text for tool usage */
    private $currentText = '';

    /** @var array Track tool call counts to prevent infinite loops */
    private $toolCallCounts = [];

    /** @var int The request timeout in seconds */
    private $timeout;

    /** @var float The temperature setting for response randomness */
    private $temperature;

    /** @var float The top-p setting for nucleus sampling */
    private $top_p;

    /** @var int The top-k setting for token selection */
    private $top_k;

    /** @var float The min-p setting for minimum probability threshold */
    private $min_p;

    /** @var bool Whether to enable thinking in LLM responses */
    private $think;

    /** @var string The API provider: 'openai', 'anthropic', or 'ollama' */
    private $provider;

    /** @var object|null ChromaDB client instance */
    private $chromaClient;

    /** @var string|null Page ID */
    private $pageId;

    /** @var string Default ChromaDB collection when page ID provides no segment */
    private $defaultCollection;

    /** @var string Last user prompt sent to the LLM */
    private $lastPrompt = '';

    /** @var string Last system prompt sent to the LLM */
    private $lastSystemPrompt = '';

    /**
     * Initialize the LLM client with configuration settings
     *
     * Configuration values:
     * - openai_api_url: OpenAI-compatible API endpoint URL
     * - openai_api_key: Bearer token for the OpenAI path (optional)
     * - openai_model: Model identifier for the OpenAI path
     * - anthropic_api_key: x-api-key for the Anthropic path
     * - anthropic_model: Model identifier for the Anthropic path
     * - timeout: Request timeout in seconds
     * - profile: Profile for prompt templates
     * - temperature: Temperature setting for response randomness (0.0-1.0)
     * - top_p: Top-p (nucleus sampling) setting (0.0-1.0)
     * - top_k: Top-k setting (integer >= 1)
     * - min_p: Minimum probability threshold (0.0-1.0)
     * - think: Whether to enable thinking in LLM responses (boolean)
     * - chromaClient: ChromaDB client instance (optional)
     * - pageId: Page ID (optional)
     */
    public function __construct($openai_api_url = null, $openai_api_key = null, $openai_model = null, $anthropic_api_key = null, $anthropic_model = null, $ollama_host = null, $ollama_port = null, $ollama_model = null, $timeout = null, $temperature = null, $top_p = null, $top_k = null, $min_p = null, $think = null, $tools = null, $provider = 'openai', $profile = null, $chromaClient = null, $pageId = null, $defaultCollection = 'documents')
    {
        $this->openai_api_url      = $openai_api_url;
        $this->openai_api_key      = $openai_api_key;
        $this->openai_model        = $openai_model;
        $this->anthropic_api_key   = $anthropic_api_key;
        $this->anthropic_model     = $anthropic_model;
        $this->ollama_host         = $ollama_host;
        $this->ollama_port         = $ollama_port;
        $this->ollama_model        = $ollama_model;
        $this->timeout             = $timeout;
        $this->temperature         = $temperature;
        $this->top_p               = $top_p;
        $this->top_k               = $top_k;
        $this->min_p               = $min_p;
        $this->think               = $think;
        $this->tools               = $tools;
        $this->provider            = $provider ?? 'openai';
        $this->profile             = $profile;
        $this->chromaClient        = $chromaClient;
        $this->pageId              = $pageId;
        $this->defaultCollection   = $defaultCollection ?: 'documents';
    }

    public function getLastPrompt(): string { return $this->lastPrompt; }
    public function getLastSystemPrompt(): string { return $this->lastSystemPrompt; }

    public function process($action, $text, $metadata = [])
    {
        // Store the current text for tool usage
        $this->currentText = $text;

        // Add text, think and action to metadata
        $metadata['text'] = $text;
        $metadata['think'] = $this->think ? '/think' : '/no_think';
        $metadata['action'] = $action;

        // If we have 'template' in metadata, move it to 'page_template'
        if (isset($metadata['template'])) {
            $metadata['page_template'] = $metadata['template'];
            unset($metadata['template']);
        }

        // If we have 'examples' in metadata, move it to 'page_examples'
        if (isset($metadata['examples'])) {
            $metadata['page_examples'] = $metadata['examples'];
            unset($metadata['examples']);
        }

        // If we have 'previous' in metadata, move it to 'page_previous'
        if (isset($metadata['previous'])) {
            $metadata['page_previous'] = $metadata['previous'];
            unset($metadata['previous']);
        }

        // Load the prompt
        $prompt = $this->loadPrompt($action, $metadata);
        $this->lastPrompt = $prompt;

        // Call the API
        return $this->callAPI($action, $prompt, $metadata, $this->tools);
    }

    /**
     * Route the request to the appropriate provider
     *
     * Loads the system prompt then dispatches to callOpenAIAPI(),
     * callAnthropicAPI(), or callOllamaAPI() based on the configured provider.
     *
     * @param string $command The command name for loading command-specific system prompts
     * @param string $prompt The user message
     * @param array $metadata Optional metadata (unused after prompt loading)
     * @param bool $useTools Whether to enable tool calling
     * @return string The response content from the LLM
     */
    private function callAPI($command, $prompt, $metadata = [], $useTools = false)
    {
        // Load system prompt which provides general instructions to the LLM
        $systemPrompt = $this->loadSystemPrompt($command, []);
        $this->lastSystemPrompt = $systemPrompt;

        // Dispatch to the appropriate provider
        if ($this->provider === 'anthropic') {
            return $this->callAnthropicAPI($systemPrompt, $prompt, $useTools);
        }
        if ($this->provider === 'ollama') {
            return $this->callOllamaAPI($systemPrompt, $prompt, $useTools);
        }
        return $this->callOpenAIAPI($systemPrompt, $prompt, $useTools);
    }

    // =========================================================================
    // OpenAI provider
    // =========================================================================

    /**
     * Build and send a request to an OpenAI-compatible API
     *
     * Constructs the request body with model parameters and delegates to
     * callOpenAIAPIWithTools() for the actual HTTP call and tool-call handling.
     *
     * Note: keep_alive and think are sent at the top level for compatibility
     * with Ollama's OpenAI-emulation endpoint; real OpenAI ignores them.
     *
     * @param string $systemPrompt The system prompt
     * @param string $prompt The user message
     * @param bool $useTools Whether to enable tool calling
     * @return string The response text
     */
    private function callOpenAIAPI($systemPrompt, $prompt, $useTools = false)
    {
        // Prepare API request data with model parameters
        $data = [
            'model' => $this->openai_model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 6144,
            'stream' => false,
            'keep_alive' => '30m',
            'think' => true
        ];

        // Add tools to the request only if useTools is true
        if ($useTools) {
            $data['tools'] = $this->getOpenAITools();
            $data['tool_choice'] = 'auto';
            $data['parallel_tool_calls'] = false;
        }

        // Only add parameters if they are defined and not null
        if ($this->temperature !== null) {
            $data['temperature'] = $this->temperature;
        }
        if ($this->top_p !== null) {
            $data['top_p'] = $this->top_p;
        }
        if ($this->top_k !== null) {
            $data['top_k'] = $this->top_k;
        }
        if ($this->min_p !== null) {
            $data['min_p'] = $this->min_p;
        }

        return $this->callOpenAIAPIWithTools($data, false);
    }

    /**
     * Execute an OpenAI API call, handling tool use recursively
     *
     * Sends the request, parses choices[0].message, and recurses when
     * tool_calls are present until a final text response is received.
     *
     * Loop protection:
     * - max 3 calls per individual tool
     * - max 10 total tool calls across all tools
     *
     * @param array $data The API request body
     * @param bool $toolsCalled Whether the loop limit has been hit (strips tools on true)
     * @param bool $useTools Whether to process tool_calls in the response
     * @return string The final text content
     * @throws Exception On HTTP or format errors
     */
    private function callOpenAIAPIWithTools($data, $toolsCalled = false, $useTools = true)
    {
        // Set up HTTP headers, including authentication if API key is configured
        $headers = [
            'Content-Type: application/json'
        ];

        if (!empty($this->openai_api_key)) {
            $headers[] = 'Authorization: Bearer ' . $this->openai_api_key;
        }

        // If tools have already been called, remove tools and tool_choice from data to prevent infinite loops
        if ($toolsCalled) {
            unset($data['tools']);
            unset($data['tool_choice']);
        }

        // Initialize and configure cURL for the API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->openai_api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        // Execute the API request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Handle cURL errors
        if ($error) {
            throw new Exception('API request failed: ' . $error);
        }

        // Handle HTTP errors
        if ($httpCode !== 200) {
            throw new Exception('API request failed with HTTP code: ' . $httpCode);
        }

        // Parse and validate the JSON response
        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new Exception('OpenAI API returned invalid JSON: ' . substr($response, 0, 200));
        }

        // Extract the content from the response if available
        if (isset($result['choices'][0]['message']['content'])) {
            $content = trim($result['choices'][0]['message']['content']);
            // Reset tool call counts when we get final content
            $this->toolCallCounts = [];
            return $content;
        }

        // Handle tool calls if present
        if ($useTools && isset($result['choices'][0]['message']['tool_calls'])) {
            $toolCalls = $result['choices'][0]['message']['tool_calls'];
            // Start with original messages
            $messages = $data['messages'];
            // Add assistant's message with tool calls, keeping all original fields except for content (which is null)
            $assistantMessage = [];
            foreach ($result['choices'][0]['message'] as $key => $value) {
                if ($key !== 'content') {
                    $assistantMessage[$key] = $value;
                }
            }
            // Add assistant's message with tool calls
            $messages[] = $assistantMessage;

            // Process each tool call and track counts to prevent infinite loops
            foreach ($toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'];
                // Increment tool call count
                if (!isset($this->toolCallCounts[$toolName])) {
                    $this->toolCallCounts[$toolName] = 0;
                }
                $this->toolCallCounts[$toolName]++;

                $toolResponse = $this->handleToolCallOpenAI($toolCall);
                $messages[] = $toolResponse;
            }

            // Check if any tool has been called more than 3 times
            $toolsCalledCount = 0;
            foreach ($this->toolCallCounts as $count) {
                if ($count > 3) {
                    // If any tool called more than 3 times, disable tools to break loop
                    $toolsCalled = true;
                    break;
                }
                $toolsCalledCount += $count;
            }

            // If total tool calls exceed 10, also disable tools
            if ($toolsCalledCount > 10) {
                $toolsCalled = true;
            }

            // Make another API call with tool responses
            $data['messages'] = $messages;
            return $this->callOpenAIAPIWithTools($data, $toolsCalled, $useTools);
        }

        // Throw exception for unexpected response format
        throw new Exception('Unexpected API response format');
    }

    /**
     * Get the list of available tools in OpenAI function-calling format
     *
     * Returns tool definitions that are also used by the Ollama path (same format)
     * and converted to Anthropic format by getAnthropicTools().
     *
     * @return array List of tool definitions
     */
    private function getOpenAITools()
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_document',
                    'description' => 'Retrieve the full content of a specific document by providing its unique document ID. Use this when you need to access the complete text of a particular document for reference or analysis.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                                'description' => 'The unique identifier of the document to retrieve. This should be a valid document ID that exists in the system.'
                            ]
                        ],
                        'required' => ['id']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_template',
                    'description' => 'Retrieve a relevant template document that matches the current context and content. Use this when you need a structural template or format example to base your response on, particularly for creating consistent reports or documents.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'description' => 'The type of the template (e.g., "mri" for MRI reports, "daily" for daily reports).',
                                'default' => ''
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_examples',
                    'description' => 'Retrieve relevant example snippets from previous reports that are similar to the current context. Use this when you need to see how similar content was previously handled, to maintain consistency in style, terminology, and structure.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'count' => [
                                'type' => 'integer',
                                'description' => 'The number of examples to retrieve (1-20). Use more examples when you need comprehensive reference material, fewer when you need just a quick reminder of the style.',
                                'default' => 5
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Handle a tool call from the OpenAI path
     *
     * Processes tool calls made by the LLM and returns appropriate responses.
     * Arguments arrive as a JSON string (OpenAI format) and are decoded here.
     * Implements caching to avoid duplicate calls with identical parameters.
     *
     * @param array $toolCall The tool call data from the LLM response
     * @return array The role:tool message with tool_call_id
     */
    private function handleToolCallOpenAI($toolCall)
    {
        $toolName = $toolCall['function']['name'];
        $arguments = json_decode($toolCall['function']['arguments'], true);

        // Create a cache key from the tool name and arguments
        $cacheKey = md5($toolName . serialize($arguments));

        // Check if we have a cached result for this tool call
        if (isset($this->toolCallCache[$cacheKey])) {
            // Return cached result and indicate it was found in cache
            $toolResponse = $this->toolCallCache[$cacheKey];
            // Update with current tool call ID
            $toolResponse['tool_call_id'] = $toolCall['id'];
            $toolResponse['cached'] = true;
            return $toolResponse;
        }

        $toolResponse = [
            'role' => 'tool',
            'tool_call_id' => $toolCall['id'],
            'cached' => false
        ];

        switch ($toolName) {
            case 'get_document':
                $documentId = $arguments['id'];
                $content = $this->getPageContent($documentId);
                if ($content === false) {
                    $toolResponse['content'] = 'Document not found: ' . $documentId;
                } else {
                    $toolResponse['content'] = $content;
                }
                break;

            case 'get_template':
                try {
                    $toolResponse['content'] = $this->getTemplateContent();
                } catch (Exception $e) {
                    $toolResponse['content'] = 'ChromaDB error retrieving template: ' . $e->getMessage();
                }
                break;

            case 'get_examples':
                try {
                    $count = isset($arguments['count']) ? (int)$arguments['count'] : 5;
                    $toolResponse['content'] = '<examples>\n' . $this->getSnippets($count) . '\n</examples>';
                } catch (Exception $e) {
                    $toolResponse['content'] = 'ChromaDB error retrieving examples: ' . $e->getMessage();
                }
                break;

            default:
                $toolResponse['content'] = 'Unknown tool: ' . $toolName;
        }

        // Cache the result for future calls with the same parameters
        $cacheEntry = $toolResponse;
        // Remove tool_call_id and cached flag from cache as they change per call
        unset($cacheEntry['tool_call_id']);
        unset($cacheEntry['cached']);
        $this->toolCallCache[$cacheKey] = $cacheEntry;

        return $toolResponse;
    }

    // =========================================================================
    // Anthropic provider
    // =========================================================================

    /**
     * Build and send a request to Anthropic's native Messages API
     *
     * Entry point for the Anthropic provider path. Constructs the request body
     * in Anthropic format and delegates to callAnthropicAPIWithTools().
     *
     * When think is enabled, forces temperature to 1.0 (Anthropic requirement)
     * and omits top_p, top_k, and min_p which are incompatible with thinking mode.
     * min_p is always omitted as Anthropic does not support it.
     *
     * @param string $systemPrompt The system prompt
     * @param string $prompt The user message
     * @param bool $useTools Whether to enable tool calling
     * @return string The response text
     */
    private function callAnthropicAPI($systemPrompt, $prompt, $useTools = false)
    {
        $data = [
            'model'      => $this->anthropic_model,
            'max_tokens' => 6144,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt]
            ],
        ];

        if ($this->think) {
            // Anthropic requires temperature=1.0 when extended thinking is enabled;
            // top_p, top_k, and min_p must not be sent in this mode.
            $data['thinking'] = [
                'type'          => 'enabled',
                'budget_tokens' => 5000,
            ];
            $data['temperature'] = 1.0;
        } else {
            if ($this->temperature !== null) {
                $data['temperature'] = $this->temperature;
            }
            if ($this->top_p !== null) {
                $data['top_p'] = $this->top_p;
            }
            if ($this->top_k !== null) {
                $data['top_k'] = $this->top_k;
            }
            // min_p is not a valid Anthropic parameter; always omitted
        }

        if ($useTools) {
            $data['tools'] = $this->getAnthropicTools();
        }

        return $this->callAnthropicAPIWithTools($data, false, $useTools);
    }

    /**
     * Execute an Anthropic API call, handling tool use recursively
     *
     * Sends the request to https://api.anthropic.com/v1/messages, parses the
     * typed content blocks in the response (text, thinking, tool_use), and
     * recurses when tool calls are present until a final text response is received.
     *
     * Applies the same loop-protection limits as the OpenAI path:
     * - max 3 calls per individual tool
     * - max 10 total tool calls across all tools
     *
     * @param array $data The full request body
     * @param bool $toolsCalled Whether the loop limit has been hit (strips tools on true)
     * @param bool $useTools Whether to process tool_use blocks
     * @return string The final text content
     * @throws Exception On HTTP or format errors
     */
    private function callAnthropicAPIWithTools($data, $toolsCalled = false, $useTools = true)
    {
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->anthropic_api_key,
            'anthropic-version: 2023-06-01',
        ];

        // Remove tools from the request when loop limit is reached
        if ($toolsCalled) {
            unset($data['tools']);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('Anthropic API request failed: ' . $error);
        }
        if ($httpCode !== 200) {
            throw new Exception('Anthropic API request failed with HTTP code: ' . $httpCode . ' - ' . $response);
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new Exception('Anthropic API returned invalid JSON: ' . substr($response, 0, 200));
        }

        // Parse typed content blocks
        $textContent   = '';
        $toolUseBlocks = [];
        foreach ($result['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $textContent .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolUseBlocks[] = $block;
            }
            // 'thinking' blocks are intentionally ignored; Anthropic thinking is internal.
        }

        // Final text response
        if (!empty($textContent) && empty($toolUseBlocks)) {
            $this->toolCallCounts = [];
            return $textContent;
        }

        // Tool use response — bundle all results into one user message
        if ($useTools && !empty($toolUseBlocks) && ($result['stop_reason'] ?? '') === 'tool_use') {
            $messages = $data['messages'];

            // Append the full assistant turn (contains tool_use blocks)
            $messages[] = [
                'role'    => 'assistant',
                'content' => $result['content'],
            ];

            // Build one user message containing all tool_result content blocks
            $toolResultBlocks = [];
            foreach ($toolUseBlocks as $toolUseBlock) {
                $toolName = $toolUseBlock['name'];

                if (!isset($this->toolCallCounts[$toolName])) {
                    $this->toolCallCounts[$toolName] = 0;
                }
                $this->toolCallCounts[$toolName]++;

                $toolResultBlocks[] = $this->handleToolCallAnthropic($toolUseBlock);
            }

            $messages[] = [
                'role'    => 'user',
                'content' => $toolResultBlocks,
            ];

            // Check loop-protection limits
            $toolsCalledCount = 0;
            foreach ($this->toolCallCounts as $count) {
                if ($count > 3) {
                    $toolsCalled = true;
                    break;
                }
                $toolsCalledCount += $count;
            }
            if ($toolsCalledCount > 10) {
                $toolsCalled = true;
            }

            $data['messages'] = $messages;
            return $this->callAnthropicAPIWithTools($data, $toolsCalled, $useTools);
        }

        throw new Exception('Unexpected Anthropic API response format');
    }

    /**
     * Convert OpenAI-format tool definitions to Anthropic format
     *
     * Anthropic tools are flat objects with name, description, and input_schema,
     * whereas OpenAI wraps them in a type:'function' envelope with a parameters key.
     *
     * @return array Anthropic-format tool definitions
     */
    private function getAnthropicTools()
    {
        $anthropicTools = [];
        foreach ($this->getOpenAITools() as $tool) {
            $fn = $tool['function'];
            $anthropicTools[] = [
                'name'         => $fn['name'],
                'description'  => $fn['description'],
                'input_schema' => $fn['parameters'],
            ];
        }
        return $anthropicTools;
    }

    /**
     * Dispatch an Anthropic tool_use block and return a tool_result content block
     *
     * Unlike the OpenAI path, Anthropic's input is already a decoded array
     * (not a JSON string), and the result format is a tool_result content block
     * rather than a role:tool message. Reuses the same $toolCallCache store
     * with an identical MD5 key so results are shared across provider paths.
     *
     * @param array $toolUseBlock A tool_use content block from the Anthropic response
     * @return array A tool_result content block ready to include in a user message
     */
    private function handleToolCallAnthropic($toolUseBlock)
    {
        $toolName  = $toolUseBlock['name'];
        $arguments = $toolUseBlock['input'];   // already decoded array, not a JSON string

        $cacheKey = md5($toolName . serialize($arguments));

        if (isset($this->toolCallCache[$cacheKey])) {
            $content = $this->toolCallCache[$cacheKey]['content'];
        } else {
            switch ($toolName) {
                case 'get_document':
                    $pageContent = $this->getPageContent($arguments['id']);
                    $content = ($pageContent === false)
                        ? 'Document not found: ' . $arguments['id']
                        : $pageContent;
                    break;

                case 'get_template':
                    try {
                        $content = $this->getTemplateContent();
                    } catch (Exception $e) {
                        $content = 'ChromaDB error retrieving template: ' . $e->getMessage();
                    }
                    break;

                case 'get_examples':
                    try {
                        $count = isset($arguments['count']) ? (int)$arguments['count'] : 5;
                        $content = '<examples>\n' . $this->getSnippets($count) . '\n</examples>';
                    } catch (Exception $e) {
                        $content = 'ChromaDB error retrieving examples: ' . $e->getMessage();
                    }
                    break;

                default:
                    $content = 'Unknown tool: ' . $toolName;
            }

            $this->toolCallCache[$cacheKey] = ['content' => $content];
        }

        return [
            'type'        => 'tool_result',
            'tool_use_id' => $toolUseBlock['id'],
            'content'     => $content,
        ];
    }

    // =========================================================================
    // Ollama provider
    // =========================================================================

    /**
     * Build and send a request to Ollama's native /api/chat endpoint
     *
     * Sampling parameters are placed inside an 'options' object as required
     * by the Ollama API. The think flag is sent at the top level (supported by
     * thinking-capable models such as Qwen3). No authentication header is sent
     * because Ollama runs as a local service. Tools use the same definition
     * format as OpenAI so getOpenAITools() is reused without conversion.
     *
     * @param string $systemPrompt The system prompt
     * @param string $prompt The user message
     * @param bool $useTools Whether to enable tool calling
     * @return string The response text
     */
    private function callOllamaAPI($systemPrompt, $prompt, $useTools = false)
    {
        $data = [
            'model'      => $this->ollama_model,
            'messages'   => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $prompt],
            ],
            'stream'     => false,
            'keep_alive' => '30m',
        ];

        if ($this->think) {
            $data['think'] = true;
        }

        // Ollama sampling parameters live inside 'options', not at the top level
        $options = [];
        if ($this->temperature !== null) $options['temperature'] = $this->temperature;
        if ($this->top_p       !== null) $options['top_p']       = $this->top_p;
        if ($this->top_k       !== null) $options['top_k']       = $this->top_k;
        if ($this->min_p       !== null) $options['min_p']       = $this->min_p;
        if (!empty($options)) {
            $data['options'] = $options;
        }

        if ($useTools) {
            $data['tools'] = $this->getOpenAITools();
        }

        $url = 'http://' . $this->ollama_host . ':' . $this->ollama_port . '/api/chat';
        return $this->callOllamaAPIWithTools($data, $url, false, $useTools);
    }

    /**
     * Execute an Ollama API call, handling tool use recursively
     *
     * Parses the native Ollama response shape (message.content / message.tool_calls).
     * Tool result messages use role:'tool' with no tool_call_id — each call gets
     * its own separate message, mirroring OpenAI's per-message style.
     * Arguments arrive as a decoded array (not a JSON string), so no json_decode
     * is needed; a string fallback is included for older Ollama versions.
     *
     * @param array  $data        Full request body
     * @param string $url         Ollama /api/chat endpoint URL
     * @param bool   $toolsCalled Whether the loop limit has been hit
     * @param bool   $useTools    Whether to process tool_calls in the response
     * @return string The final text content
     * @throws Exception On HTTP or format errors
     */
    private function callOllamaAPIWithTools($data, $url, $toolsCalled = false, $useTools = true)
    {
        if ($toolsCalled) {
            unset($data['tools']);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('Ollama API request failed: ' . $error);
        }
        if ($httpCode !== 200) {
            throw new Exception('Ollama API request failed with HTTP code: ' . $httpCode . ' - ' . $response);
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new Exception('Ollama API returned invalid JSON: ' . substr($response, 0, 200));
        }
        $message    = $result['message'] ?? [];
        $content    = $message['content']    ?? '';
        $toolCalls  = $message['tool_calls'] ?? [];

        // Final text response
        if (!empty($content) && empty($toolCalls)) {
            $this->toolCallCounts = [];
            return $content;
        }

        // Tool use response
        if ($useTools && !empty($toolCalls)) {
            $messages = $data['messages'];

            // Add the assistant turn with its tool_calls intact
            $messages[] = [
                'role'       => 'assistant',
                'content'    => $content,
                'tool_calls' => $toolCalls,
            ];

            // Each tool call gets its own role:tool message (no tool_call_id in Ollama)
            foreach ($toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'] ?? '';

                if (!isset($this->toolCallCounts[$toolName])) {
                    $this->toolCallCounts[$toolName] = 0;
                }
                $this->toolCallCounts[$toolName]++;

                $messages[] = $this->handleToolCallOllama($toolCall);
            }

            // Loop protection: same limits as other providers
            $toolsCalledCount = 0;
            foreach ($this->toolCallCounts as $count) {
                if ($count > 3) {
                    $toolsCalled = true;
                    break;
                }
                $toolsCalledCount += $count;
            }
            if ($toolsCalledCount > 10) {
                $toolsCalled = true;
            }

            $data['messages'] = $messages;
            return $this->callOllamaAPIWithTools($data, $url, $toolsCalled, $useTools);
        }

        throw new Exception('Unexpected Ollama API response format');
    }

    /**
     * Dispatch an Ollama tool_call entry and return a role:tool message
     *
     * Ollama delivers arguments as a decoded array; older builds may send a
     * JSON string, so both forms are handled. Results share $toolCallCache
     * with the other provider paths using the same MD5 key formula.
     *
     * @param array $toolCall A tool_calls entry from the Ollama response message
     * @return array A role:tool message ready to append to the conversation
     */
    private function handleToolCallOllama($toolCall)
    {
        $toolName  = $toolCall['function']['name'] ?? '';
        $arguments = $toolCall['function']['arguments'] ?? [];

        // Older Ollama versions may send arguments as a JSON string
        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true) ?? [];
        }

        $cacheKey = md5($toolName . serialize($arguments));

        if (isset($this->toolCallCache[$cacheKey])) {
            $content = $this->toolCallCache[$cacheKey]['content'];
        } else {
            switch ($toolName) {
                case 'get_document':
                    $pageContent = $this->getPageContent($arguments['id'] ?? '');
                    $content = ($pageContent === false)
                        ? 'Document not found: ' . ($arguments['id'] ?? '')
                        : $pageContent;
                    break;

                case 'get_template':
                    try {
                        $content = $this->getTemplateContent();
                    } catch (Exception $e) {
                        $content = 'ChromaDB error retrieving template: ' . $e->getMessage();
                    }
                    break;

                case 'get_examples':
                    try {
                        $count   = isset($arguments['count']) ? (int)$arguments['count'] : 5;
                        $content = '<examples>\n' . $this->getSnippets($count) . '\n</examples>';
                    } catch (Exception $e) {
                        $content = 'ChromaDB error retrieving examples: ' . $e->getMessage();
                    }
                    break;

                default:
                    $content = 'Unknown tool: ' . $toolName;
            }

            $this->toolCallCache[$cacheKey] = ['content' => $content];
        }

        return [
            'role'    => 'tool',
            'content' => $content,
        ];
    }

    // =========================================================================
    // Shared helpers
    // =========================================================================

    /**
     * Load a prompt template from a DokuWiki page and replace placeholders
     *
     * Loads prompt templates from DokuWiki pages with IDs in the format
     * dokullm:profiles:PROFILE:PROMPT_NAME
     *
     * The method implements a profile fallback mechanism:
     * 1. First tries to load the prompt from the configured profile
     * 2. If not found, falls back to default prompts
     * 3. Throws an exception if neither is available
     *
     * After loading the prompt, it scans for placeholders and automatically
     * adds missing ones with appropriate values before replacing all placeholders.
     *
     * @param string $promptName The name of the prompt (e.g., 'create', 'rewrite')
     * @param array $variables Associative array of placeholder => value pairs
     * @return string The processed prompt with placeholders replaced
     * @throws Exception If the prompt page cannot be loaded from any profile
     */
    private function loadPrompt($promptName, $variables = [])
    {
        // Default to 'default' if profile is not set
        if (empty($this->profile)) {
            $this->profile = 'default';
        }

        // Construct the page ID for the prompt in the configured profile
        $promptPageId = 'dokullm:profiles:' . $this->profile . ':' . $promptName;

        // Try to get the content of the prompt page in the configured profile
        $prompt = $this->getPageContent($promptPageId);

        // If the profile-specific prompt doesn't exist, try default as fallback
        if ($prompt === false && $this->profile !== 'default') {
            $promptPageId = 'dokullm:profiles:default:' . $promptName;
            $prompt = $this->getPageContent($promptPageId);
        }

        // If still no prompt found, throw an exception
        if ($prompt === false) {
            throw new Exception('Prompt page not found: ' . $promptPageId);
        }

        // Find placeholders in the prompt
        $placeholders = $this->findPlaceholders($prompt);

        // Add missing placeholders with appropriate values
        foreach ($placeholders as $placeholder) {
            // Skip if already provided in variables
            if (isset($variables[$placeholder])) {
                continue;
            }

            // Add appropriate values for specific placeholders
            switch ($placeholder) {
                case 'template':
                    // If we have a page_template in variables, use it
                    $variables[$placeholder] = $this->tools ? '' : $this->getTemplateContent($variables['page_template']);
                    break;

                case 'snippets':
                    $variables[$placeholder] = $this->tools ? '' : ($this->chromaClient !== null ? $this->getSnippets(10) : '( no examples )');
                    break;

                case 'examples':
                    // If we have example page IDs in metadata, add examples content
                    $variables[$placeholder] = $this->tools ? '' : $this->getExamplesContent($variables['page_examples']);
                    break;

                case 'previous':
                    // If we have a previous report page ID in metadata, add previous content
                    $variables[$placeholder] = $this->getPreviousContent($variables['page_previous']);

                    // Add current and previous dates to metadata
                    $variables['current_date'] = $this->getPageDate($this->pageId);
                    $variables['previous_date'] = !empty($variables['page_previous']) ?
                                                $this->getPageDate($variables['page_previous']) :
                                                '';
                    break;

                case 'current_date':
                    $variables[$placeholder] = date('Y-m-d');
                    break;

                case 'current_time':
                    $variables[$placeholder] = date('H:i');
                    break;

                case 'prompt':
                    // Add the custom prompt value
                    $variables[$placeholder] = isset($variables['prompt']) ? $variables['prompt'] : '';
                    break;

                default:
                    // For other placeholders, leave them empty or set a default value
                    $variables[$placeholder] = '';
                    break;
            }
        }

        // Replace placeholders with actual values
        // Placeholders are in the format {placeholder_name}
        foreach ($variables as $placeholder => $value) {
            $prompt = str_replace('{' . $placeholder . '}', $value, $prompt);
        }

        // Return the processed prompt
        return $prompt;
    }

    /**
     * Load system prompt with optional command-specific appendage
     *
     * Loads the main system prompt and appends any command-specific system prompt
     * if available.
     *
     * @param string $action The action/command name
     * @param array $variables Associative array of placeholder => value pairs
     * @return string The combined system prompt
     */
    private function loadSystemPrompt($action, $variables = [])
    {
        // Load system prompt which provides general instructions to the LLM
        $systemPrompt = $this->loadPrompt('system', $variables);

        // Check if there's a command-specific system prompt appendage
        if (!empty($action)) {
            try {
                $commandSystemPrompt = $this->loadPrompt('system:' . $action, $variables);
                if ($commandSystemPrompt !== false) {
                    $systemPrompt .= "\n" . $commandSystemPrompt;
                }
            } catch (Exception $e) {
                // Command-specific system prompt is optional; log but continue with base prompt
                \dokuwiki\Logger::debug('DokuLLM: No command-specific system prompt for action "' . $action . '": ' . $e->getMessage());
            }
        }

        return $systemPrompt;
    }

    /**
     * Get the content of a DokuWiki page
     *
     * Retrieves the raw content of a DokuWiki page by its ID.
     * Used for loading template and example page content for context.
     *
     * @param string $pageId The page ID to retrieve
     * @return string|false The page content or false if not found/readable
     * @throws Exception If access is denied
     */
    public function getPageContent($pageId)
    {
        // Clean the ID and check ACL
        $cleanId = cleanID($pageId);
        if (auth_quickaclcheck($cleanId) < AUTH_READ) {
            throw new Exception('You are not allowed to read this file');
        }

        // Convert page ID to file path
        $pageFile = wikiFN($cleanId);

        // Check if file exists and is readable
        if (file_exists($pageFile) && is_readable($pageFile)) {
            return file_get_contents($pageFile);
        }

        return false;
    }

    /**
     * Extract date from page ID or file timestamp
     *
     * Attempts to extract a date in YYmmdd format from the page ID.
     * If not found, uses the file's last modification timestamp.
     *
     * @param string $pageId Optional page ID to extract date from (defaults to current page)
     * @return string Formatted date string (YYYY-MM-DD)
     */
    private function getPageDate($pageId = null)
    {
        // Use provided page ID or current page ID
        $targetPageId = $pageId ?: $this->pageId;

        // Try to extract date from page ID (looking for YYmmdd pattern)
        if (preg_match('/(\d{2})(\d{2})(\d{2})/', $targetPageId, $matches)) {
            // Convert YYmmdd to YYYY-MM-DD
            $year = $matches[1];
            $month = $matches[2];
            $day = $matches[3];

            // Assume 20xx for years 00-69, 19xx for years 70-99
            $fullYear = intval($year) <= 69 ? '20' . $year : '19' . $year;

            return $fullYear . '-' . $month . '-' . $day;
        }

        // Fallback to file timestamp
        $pageFile = wikiFN($targetPageId);
        if (file_exists($pageFile)) {
            $timestamp = filemtime($pageFile);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        }

        // Return empty string if no date can be determined
        return '';
    }

    /**
     * Get current text
     *
     * Retrieves the current text stored from the process function.
     *
     * @return string The current text
     */
    private function getCurrentText()
    {
        return $this->currentText;
    }

    /**
     * Scan text for placeholders
     *
     * Finds all placeholders in the format {placeholder_name} in the provided text
     * and returns an array of unique placeholder names.
     *
     * @param string $text The text to scan for placeholders
     * @return array List of unique placeholder names found in the text
     */
    public function findPlaceholders($text)
    {
        $placeholders = [];
        $pattern = '/\{([^}]+)\}/';

        if (preg_match_all($pattern, $text, $matches)) {
            // Get unique placeholder names
            $placeholders = array_unique($matches[1]);
        }

        return $placeholders;
    }

    /**
     * Get template content for the current text
     *
     * Convenience function to retrieve template content. If a pageId is provided,
     * retrieves content directly from that page. Otherwise, queries ChromaDB for
     * a relevant template based on the current text.
     *
     * @param string|null $pageId Optional page ID to retrieve template from directly
     * @return string The template content or empty string if not found
     */
    private function getTemplateContent($pageId = null)
    {
        // If pageId is provided, use it directly
        if ($pageId !== null) {
            $templateContent = $this->getPageContent($pageId);
            if ($templateContent !== false) {
                return $templateContent;
            }
        }

        // If ChromaDB is disabled, return empty template
        if ($this->chromaClient === null) {
            return '( no template )';
        }

        // Otherwise, get template suggestion for the current text
        $pageId = $this->queryChromaDBTemplate($this->getCurrentText());
        if (!empty($pageId)) {
            $templateContent = $this->getPageContent($pageId[0]);
            if ($templateContent !== false) {
                return $templateContent;
            }
        }
        return '( no template )';
    }

    /**
     * Get snippets content for the current text
     *
     * Convenience function to retrieve relevant snippets for the current text.
     * Queries ChromaDB for relevant snippets and returns them formatted.
     *
     * @param int $count Number of snippets to retrieve (default: 10)
     * @return string Formatted snippets content or empty string if not found
     */
    private function getSnippets($count = 10)
    {
        // If ChromaDB is disabled, return empty snippets
        if ($this->chromaClient === null) {
            return '( no examples )';
        }

        // Extract modality from current page ID
        $modality = '';
        if (!empty($this->pageId)) {
            $parts = explode(':', $this->pageId);
            if (isset($parts[1]) && !empty($parts[1])) {
                $modality = $parts[1];
            }
        }

        // Build where clause with both type and modality
        $where = ['type' => 'report'];
        if (!empty($modality)) {
            $where['modality'] = $modality;
        }

        // Get example snippets for the current text
        $snippets = $this->queryChromaDBSnippets($this->getCurrentText(), $count, $where);
        if (!empty($snippets)) {
            $formattedSnippets = [];
            foreach ($snippets as $index => $snippet) {
                $formattedSnippets[] = '<example id="' . ($index + 1) . '">\n' . $snippet . '\n</example>';
            }
            return implode("\n", $formattedSnippets);
        }
        return '( no examples )';
    }

    /**
     * Get examples content from example page IDs
     *
     * Convenience function to retrieve content from example pages.
     * Returns the content of each page packed in XML elements.
     *
     * @param array $exampleIds List of example page IDs
     * @return string Formatted examples content or empty string if not found
     */
    private function getExamplesContent($exampleIds = [])
    {
        if (empty($exampleIds) || !is_array($exampleIds)) {
            return '( no examples )';
        }

        $examplesContent = [];
        foreach ($exampleIds as $index => $exampleId) {
            $content = $this->getPageContent($exampleId);
            if ($content !== false) {
                $examplesContent[] = '<example_page source="' . $exampleId . '">\n' . $content . '\n</example_page>';
            }
        }

        return implode("\n", $examplesContent);
    }

    /**
     * Get previous report content from previous page ID
     *
     * Convenience function to retrieve content from a previous report page.
     * Returns the content of the previous page or a default message if not found.
     *
     * @param string $previousId Previous page ID
     * @return string Previous report content or default message if not found
     */
    private function getPreviousContent($previousId = '')
    {
        if (empty($previousId)) {
            return '( no previous report )';
        }

        $content = $this->getPageContent($previousId);
        if ($content !== false) {
            return $content;
        }

        return '( previous report not found )';
    }

    /**
     * Get ChromaDB client with configuration
     *
     * Returns the ChromaDB client and collection name.
     * If a client was passed in the constructor, use it. Otherwise, this method
     * should not be called as it depends on getConf() which is not available.
     *
     * @return array Array containing the ChromaDB client and collection name
     * @throws Exception If no ChromaDB client is available
     */
    private function getChromaDBClient()
    {
        // If we have a ChromaDB client passed in constructor, use it
        if ($this->chromaClient !== null) {
            $chromaCollection = $this->defaultCollection;

            if (!empty($this->pageId)) {
                // Split the page ID by ':' and take the first part as collection name
                $parts = explode(':', $this->pageId);
                if (isset($parts[0]) && !empty($parts[0])) {
                    // If the first part is 'playground', use the default collection
                    // Otherwise, use the first part as the collection name
                    if ($parts[0] === 'playground') {
                        $chromaCollection = $this->defaultCollection;
                    } else {
                        $chromaCollection = $parts[0];
                    }
                }
            }

            return [$this->chromaClient, $chromaCollection];
        }

        // If we don't have a ChromaDB client, we can't create one here
        // because getConf() is not available in this context
        throw new Exception('No ChromaDB client available');
    }

    /**
     * Query ChromaDB for relevant documents
     *
     * Generates embeddings for the input text and queries ChromaDB for similar documents.
     * Extracts the first part of the current page ID to use as the collection name.
     *
     * @param string $text The text to find similar documents for
     * @param int $limit Maximum number of documents to retrieve (default: 5)
     * @param array|null $where Optional filter conditions for metadata
     * @return array List of document IDs
     */
    private function queryChromaDB($text, $limit = 5, $where = null)
    {
        list($chromaClient, $chromaCollection) = $this->getChromaDBClient();
        $results = $chromaClient->queryCollection($chromaCollection, [$text], $limit, $where);

        $documentIds = [];
        if (isset($results['ids'][0]) && is_array($results['ids'][0])) {
            foreach ($results['ids'][0] as $id) {
                $documentIds[] = $id;
            }
        }
        return $documentIds;
    }

    /**
     * Query ChromaDB for relevant documents and return text snippets
     *
     * Generates embeddings for the input text and queries ChromaDB for similar documents.
     * Returns the actual text snippets instead of document IDs.
     *
     * @param string $text The text to find similar documents for
     * @param int $limit Maximum number of documents to retrieve (default: 10)
     * @param array|null $where Optional filter conditions for metadata
     * @return array List of text snippets
     */
    private function queryChromaDBSnippets($text, $limit = 10, $where = null)
    {
        list($chromaClient, $chromaCollection) = $this->getChromaDBClient();
        $results = $chromaClient->queryCollection($chromaCollection, [$text], $limit, $where);

        $snippets = [];
        if (isset($results['documents'][0]) && is_array($results['documents'][0])) {
            foreach ($results['documents'][0] as $document) {
                $snippets[] = $document;
            }
        }
        return $snippets;
    }

    /**
     * Query ChromaDB for a template document
     *
     * Generates embeddings for the input text and queries ChromaDB for a template document
     * by filtering with metadata 'template=true' and the current modality.
     *
     * @param string $text The text to find a template for
     * @return array List of template document IDs (maximum 1)
     */
    public function queryChromaDBTemplate($text)
    {
        // Extract modality from current page ID
        $modality = '';
        if (!empty($this->pageId)) {
            $parts = explode(':', $this->pageId);
            if (isset($parts[1]) && !empty($parts[1])) {
                $modality = $parts[1];
            }
        }

        // Build where clause with both type and modality
        $where = ['type' => 'template'];
        if (!empty($modality)) {
            $where['modality'] = $modality;
        }

        $templateIds = $this->queryChromaDB($text, 1, $where);

        // Remove chunk number (e.g., "@2") from the ID to get the base document ID
        if (!empty($templateIds)) {
            $templateIds[0] = preg_replace('/@\\d+$/', '', $templateIds[0]);
        }

        return $templateIds;
    }

}
