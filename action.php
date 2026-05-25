<?php
/**
 * DokuWiki Plugin DokuLLM (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Costin Stroie <costinstroie@eridu.eu.org>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

/**
 * Main action component for the dokullm plugin
 *
 * This class handles:
 * - Registering event handlers for page rendering and AJAX calls
 * - Adding JavaScript to edit pages
 * - Processing AJAX requests from the frontend
 * - Handling page template loading with metadata support
 * - Adding copy page button to page tools
 *
 * The plugin provides integration with LLM APIs for text processing
 * operations directly within the DokuWiki editor.
 *
 * Configuration options:
 * - openai_api_url: OpenAI-compatible API endpoint URL
 * - openai_api_key: Bearer token (optional)
 * - openai_model: Model identifier for the OpenAI path
 * - anthropic_api_key: x-api-key for the Anthropic path
 * - anthropic_model: Model identifier for the Anthropic path
 * - model: The model identifier to use for requests
 * - timeout: Request timeout in seconds
 * - profile: Profile for prompt templates
 * - temperature: Temperature setting for response randomness (0.0-1.0)
 * - top_p: Top-p (nucleus sampling) setting (0.0-1.0)
 * - top_k: Top-k setting (integer >= 1)
 * - min_p: Minimum probability threshold (0.0-1.0)
 * - think: Whether to enable thinking in LLM responses (boolean)
 * - show_copy_button: Whether to show the copy page button (boolean)
 * - replace_id: Whether to replace template ID when copying (boolean)
 */
class action_plugin_dokullm extends DokuWiki_Action_Plugin
{
    /**
     * Register the event handlers for this plugin
     *
     * Hooks into:
     * - TPL_METAHEADER_OUTPUT: To add JavaScript to edit pages
     * - AJAX_CALL_UNKNOWN: To handle plugin-specific AJAX requests
     *
     * @param Doku_Event_Handler $controller The event handler controller
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'handleDokuwikiStarted');
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handleMetaHeaders');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjaxModels');
        $controller->register_hook('COMMON_PAGETPL_LOAD', 'BEFORE', $this, 'handleTemplate');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'addCopyPageButton', array());
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'handlePageWrite');
        $controller->register_hook('INDEXER_TASKS_RUN', 'AFTER', $this, 'handleIndexerTasks');
        //$controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'handleToolbar', array ());
    }

    /**                                                                                                                
     * Inserts the DokuLLM actions as toolbar buttons                                                                  
     */                                                                                                                
    private function handleToolbar(Doku_Event $event, $param) {                                                        
        // Get the LLM actions from the profile                                                                        
        $actions = $this->getActions();                                                                                
                                                                                                                       
        // Build the toolbar list                                                                                      
        $toolbarList = [];                                                                                             
        foreach ($actions as $action) {                                                                                
            $toolbarList[] = [                                                                                         
                'type' => 'format',                                                                                    
                'title' => $action['label'],                                                                           
                'icon' => $action['icon'] ? '../../plugins/dokullm/images/' . $action['icon'] :                        
'../../plugins/dokullm/images/copy.svg',                                                                               
                'open' => 'dokullm:' . $action['id']                                                                   
            ];                                                                                                         
        }                                                                                                              
                                                                                                                       
        // Add the picker with all actions                                                                             
        $event->data[] = array (                                                                                       
            'type' => 'picker',                                                                                        
            'title' => $this->getLang('DokuLLM actions'),                                                              
            'icon' => '../../plugins/dokullm/images/copy.svg',                                                         
            'list' => $toolbarList                                                                                     
        );                                                                                                             
    }                                                                                                                  

    /**
     * Insert metadata line after the first title in DokuWiki format
     *
     * If the first line starts with '=', insert the metadata after it.
     * Otherwise, insert at the very beginning.
     *
     * @param string $text The text content
     * @param string $metadataLine The metadata line to insert
     * @return string The text with metadata inserted
     */
    private function insertMetadataAfterTitle($text, $metadataLine) {
        // Check if the first line is a title (starts with = in DokuWiki)
        $lines = explode("\n", $text);
        if (count($lines) > 0 && trim($lines[0]) !== '' && trim($lines[0])[0] === '=') {
            // Insert after the first line (the title)
            array_splice($lines, 1, 0, $metadataLine);
            return implode("\n", $lines);
        } else {
            // Insert at the very beginning
            return $metadataLine . "\n" . $text;
        }
    }


    /**
     * Add JavaScript to the page header for edit pages
     *
     * This method checks if we're on an edit or preview page and adds
     * the plugin's JavaScript file to the page header.
     *
     * @param Doku_Event $event The event object
     * @param mixed $param Additional parameters
     */
    public function handleMetaHeaders(Doku_Event $event, $param)
    {
        global $INFO, $ACT;
        // Add editor JS on edit/preview pages
        if ($INFO['act'] == 'edit' || $INFO['act'] == 'preview') {
            $event->data['script'][] = array(
                'type' => 'text/javascript',
                'src' => DOKU_BASE . 'lib/plugins/dokullm/script.js',
                '_data' => 'dokullm'
            );
        }
        // Add admin JS on the admin config page
        if ($ACT === 'admin') {
            $event->data['script'][] = array(
                'type' => 'text/javascript',
                'src' => DOKU_BASE . 'lib/plugins/dokullm/admin.js',
                '_data' => 'dokullm-admin'
            );
        }
    }


    /**
     * Add dokullm configuration to JSINFO
     *
     * @param Doku_Event $event The event object
     * @param mixed $param Additional parameters
     */
    public function handleDokuwikiStarted(Doku_Event $event, $param)
    {
        global $JSINFO, $ACT;

        if (!isset($JSINFO['plugins'])) {
            $JSINFO['plugins'] = [];
        }

        $JSINFO['plugins']['dokullm'] = [
            'enable_chromadb' => $this->getConf('enable_chromadb')
        ];

        // Add language strings
        $l10n = array();
        foreach ($this->getLang('js') as $key => $value) {
            $l10n[$key] = $value;
        }
        $JSINFO['plugins']['dokullm']['lang'] = $l10n;

        // On admin pages, auto-refresh model caches that are stale (> 24 h)
        if ($ACT === 'admin' && auth_isadmin()) {
            $ttl = 86400;
            foreach (['openai', 'anthropic', 'ollama', 'ollama_embeddings'] as $provider) {
                $cache = $this->loadModelCache($provider);
                $age   = $cache ? (time() - ($cache['fetched_at'] ?? 0)) : PHP_INT_MAX;
                if ($age > $ttl) {
                    $this->refreshModelCache($provider);
                }
            }
        }
    }


    /**
     * Handle AJAX requests for the plugin
     *
     * Processes AJAX calls with the identifier 'plugin_dokullm' and
     * routes them to the appropriate text processing method.
     *
     * @param Doku_Event $event The event object
     * @param mixed $param Additional parameters
     */
    public function handleAjax(Doku_Event $event, $param)
    {
        if ($event->data !== 'plugin_dokullm') {
            return;
        }

        $event->stopPropagation();
        $event->preventDefault();

        header('Content-Type: application/json; charset=utf-8');
        $this->processRequest();
    }


    /**
     * Process the AJAX request and return JSON response
     *
     * Extracts action, text, prompt, metadata, and template parameters from the request,
     * validates the input, and calls the appropriate processing method.
     * Returns JSON encoded result or error.
     *
     * @return void
     */
    private function processRequest()
    {
        global $INPUT, $ID;
        // In AJAX context DokuWiki sets $ID from GET only; read POST 'id' explicitly
        $pageId = cleanID($INPUT->str('id', '')) ?: $ID;
        // Get form data
        $action = $INPUT->str('action');
        $text = $INPUT->str('text');
        $prompt = $INPUT->str('prompt', '');
        $template = $INPUT->str('template', '');
        $examples = $INPUT->str('examples', '');
        $previous = $INPUT->str('previous', '');
        // Parse examples - split by newline and filter out empty lines
        $examplesList = array_filter(array_map('trim', explode("\n", $examples)));
        // Create metadata object with prompt, template, examples, and previous
        $metadata = [
            'prompt' => $prompt,
            'template' => $template,
            'examples' => $examplesList,
            'previous' => $previous
        ];
        // Handle the special case of get_actions action
        if ($action === 'get_actions') {
            try {
                $actions = $this->getActions();
                echo json_encode(['result' => $actions]);
            } catch (\Throwable $e) {
                http_status(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }
        // Handle the special case of get_template action
        if ($action === 'get_template') {
            try {
                $templateId = $template;
                $templateContent = $this->getPageContent($templateId);
                if ($templateContent === false) {
                    throw new Exception($this->getLang('template_not_found') . $templateId);
                }
                echo json_encode(['result' => ['content' => $templateContent]]);
            } catch (\Throwable $e) {
                http_status(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }
        // Handle the special case of find_template action
        if ($action === 'find_template') {
            try {
                if (!$this->getConf('enable_chromadb')) {
                    echo json_encode(['result' => ['template' => null, 'message' => 'ChromaDB is not enabled. Enable it in the plugin settings to use template search.']]);
                    return;
                }
                $searchText = $INPUT->str('text');
                $template = $this->findTemplate($searchText, $pageId);
                if (!empty($template)) {
                    echo json_encode(['result' => ['template' => $template[0]]]);
                } else {
                    echo json_encode(['result' => ['template' => null, 'message' => 'No matching template found in ChromaDB. Make sure templates are indexed with type=template metadata.']]);
                }
            } catch (\Throwable $e) {
                \dokuwiki\Logger::error('DokuLLM find_template: ' . $e->getMessage());
                http_status(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }
        // Validate input
        if (empty($text)) {
            http_status(400);
            echo json_encode(['error' => $this->getLang('no_text_provided')]);
            return;
        }
        // Create ChromaDB client only if enabled
        $chromaClient = null;
        if ($this->getConf('enable_chromadb')) {
            $chromaClient = new \dokuwiki\plugin\dokullm\ChromaDBClient(
                $this->getConf('chroma_host'),
                $this->getConf('chroma_port'),
                $this->getConf('chroma_tenant'),
                $this->getConf('chroma_database'),
                $this->getConf('chroma_default_collection'),
                $this->getConf('ollama_host'),
                $this->getConf('ollama_port'),
                $this->getConf('ollama_embeddings_model')
            );
        }
        $client = new \dokuwiki\plugin\dokullm\LlmClient(
            $this->getConf('openai_api_url'),
            $this->getConf('openai_api_key'),
            $this->getConf('openai_model'),
            $this->getConf('anthropic_api_key'),
            $this->getConf('anthropic_model'),
            $this->getConf('ollama_host'),
            $this->getConf('ollama_port'),
            $this->getConf('ollama_model'),
            $this->getConf('timeout'),
            $this->getConf('temperature'),
            $this->getConf('top_p'),
            $this->getConf('top_k'),
            $this->getConf('min_p'),
            $this->getConf('think', false),
            $this->getConf('tools', false),
            $this->getConf('provider', 'openai'),
            $this->getConf('profile', 'default'),
            $chromaClient,
            $pageId,
            $this->getConf('chroma_default_collection')
        );
        try {
            $result = $client->process($action, $text, $metadata);
            echo json_encode([
                'result' => $result,
                'debug'  => [
                    'system' => $client->getLastSystemPrompt(),
                    'prompt' => $client->getLastPrompt(),
                ]
            ]);
        } catch (\Throwable $e) {
            \dokuwiki\Logger::error('DokuLLM processRequest: ' . $e->getMessage());
            http_status(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }


    /**
     * Get action definitions from the DokuWiki table at dokullm:profiles:PROFILE
     *
     * Parses the table containing action definitions with the following columns:
     *
     * - ID: The action identifier, which corresponds to the prompt name
     * - Label: The text displayed on the button
     * - Description: A detailed description of the action, used as a tooltip
     * - Icon: The icon displayed on the button (can be empty)
     * - Result: The action to perform with the LLM result:
     *   - show: Display the result in a modal dialog
     *   - append: Add the result to the end of the current content
     *   - replace: Replace the selected content with the result
     *   - insert: Insert the result at the cursor position
     *
     * The parsing stops after the first table ends to avoid processing
     * additional tables that might contain disabled or work-in-progress commands.
     *
     * The ID can be either:
     * - A simple word (e.g., "summary")
     * - A link to a page in the profile namespace (e.g., "[[.:default:summarize]]")
     *
     * For page links, the actual ID is extracted as the last part after the final ':'
     *
     * @return array Array of action definitions, each containing:
     *               - id: string, the action identifier
     *               - label: string, the button label
     *               - description: string, the action description
     *               - icon: string, the icon name
     *               - result: string, the result handling method
     */
    private function getActions()
    {
        // Get the content of the profile page
        $profile = $this->getConf('profile', 'default');
        try {
            $content = $this->getPageContent('dokullm:profiles:' . $profile);
        } catch (Exception $e) {
            // If access is denied or page doesn't exist, return empty list
            \dokuwiki\Logger::error('DokuLLM: Profile page not accessible: dokullm:profiles:' . $profile);
            return [];
        }
        // Return empty list if page doesn't exist
        if ($content === false) {
            \dokuwiki\Logger::error('DokuLLM: Profile page not found: dokullm:profiles:' . $profile);
            return [];
        }
        // Parse the table from the page content
        $actions = [];
        $lines = explode("\n", $content);
        $inTable = false;
        foreach ($lines as $line) {
            // Check if this is a table row
            if (preg_match('/^\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|$/', $line, $matches)) {
                $inTable = true;
                // Skip header row
                if (trim($matches[1]) === 'ID' || trim($matches[1]) === 'id') {
                    continue;
                }
                // Extract ID from either simple text or page link
                $rawId = trim($matches[1]);
                $id = $rawId;
                // Check if ID is a page link in format [[namespace:page]] or [[.:namespace:page]]
                if (preg_match('/\[\[\.?:?([^\]]+)\]\]/', $rawId, $linkMatches)) {
                    // Extract the actual page path
                    $pagePath = $linkMatches[1];
                    // Get the last part after the final ':' as the ID
                    $pathParts = explode(':', $pagePath);
                    $id = end($pathParts);
                }
                // Append the action definition
                $actions[] = [
                    'id' => $id,
                    'label' => trim($matches[2]),
                    'description' => trim($matches[3]),
                    'icon' => trim($matches[4]),
                    'result' => trim($matches[5])
                ];
            } else if ($inTable) {
                // We've exited the table, so stop parsing
                break;
            }
        }
        // Return the actions definitions
        return $actions;
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
    private function getPageContent($pageId)
    {
        // Clean the ID and check ACL
        $cleanId = cleanID($pageId);
        if (auth_quickaclcheck($cleanId) < AUTH_READ) {
            throw new Exception($this->getLang('unauthorized') . $pageId);
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
     * Find an appropriate template based on the provided text
     *
     * Uses ChromaDB to search for the most relevant template based on the content.
     *
     * @param string $text The text to use for finding a template
     * @return array The template ID array or empty array if none found
     * @throws Exception If an error occurs during the search
     */
    private function findTemplate($text, $pageId = '') {
        try {
            // Create ChromaDB client only if enabled
            $chromaClient = null;
            if ($this->getConf('enable_chromadb')) {
                $chromaClient = new \dokuwiki\plugin\dokullm\ChromaDBClient(
                    $this->getConf('chroma_host'),
                    $this->getConf('chroma_port'),
                    $this->getConf('chroma_tenant'),
                    $this->getConf('chroma_database'),
                    $this->getConf('chroma_default_collection'),
                    $this->getConf('ollama_host'),
                    $this->getConf('ollama_port'),
                    $this->getConf('ollama_embeddings_model')
                );
            }
            $client = new \dokuwiki\plugin\dokullm\LlmClient(
                $this->getConf('openai_api_url'),
                $this->getConf('openai_api_key'),
                $this->getConf('openai_model'),
                $this->getConf('anthropic_api_key'),
                $this->getConf('anthropic_model'),
                $this->getConf('ollama_host'),
                $this->getConf('ollama_port'),
                $this->getConf('ollama_model'),
                $this->getConf('timeout'),
                $this->getConf('temperature'),
                $this->getConf('top_p'),
                $this->getConf('top_k'),
                $this->getConf('min_p'),
                $this->getConf('think', false),
                $this->getConf('tools', false),
                $this->getConf('provider', 'openai'),
                $this->getConf('profile', 'default'),
                $chromaClient,
                $pageId,
                $this->getConf('chroma_default_collection')
            );
            // Query ChromaDB for the most relevant template
            $template = $client->queryChromaDBTemplate($text);
            return $template;
        } catch (Exception $e) {
            throw new Exception($this->getLang('error_finding_template') . $e->getMessage());
        }
    }


    /**
     * Enqueue a page for ChromaDB indexing when it is written to disk.
     *
     * Fires on IO_WIKIPAGE_WRITE (AFTER), which is triggered for every page
     * save. The actual ChromaDB call happens later in handleIndexerTasks() so
     * the save request is never delayed.
     *
     * @param Doku_Event $event
     * @param mixed $param
     */
    public function handlePageWrite(Doku_Event $event, $param)
    {
        if (!$this->getConf('enable_chromadb')) return;

        // event->data['id'] is available in recent DokuWiki; fall back to $ID
        $id = $event->data['id'] ?? null;
        if (empty($id)) {
            global $ID;
            $id = $ID;
        }
        if (empty($id)) return;

        // Skip deletions (empty content written to disk)
        if (empty(trim(rawWiki($id)))) return;

        $this->enqueueForChroma($id);
    }

    /**
     * Process one queued page per indexer tick.
     *
     * Fires on INDEXER_TASKS_RUN (AFTER). Pops the oldest page from the queue,
     * sends it to ChromaDB, and sets $event->data['next'] = true so DokuWiki
     * schedules another tick if more pages are waiting.
     *
     * @param Doku_Event $event
     * @param mixed $param
     */
    public function handleIndexerTasks(Doku_Event $event, $param)
    {
        if (!$this->getConf('enable_chromadb')) return;

        $file  = $this->chromaQueueFile();
        if (!file_exists($file)) return;

        $queue = json_decode(file_get_contents($file), true) ?? [];
        if (empty($queue)) {
            @unlink($file);
            return;
        }

        $id = array_shift($queue);

        // Persist remaining queue (or remove file if empty)
        if (empty($queue)) {
            @unlink($file);
        } else {
            file_put_contents($file, json_encode($queue), LOCK_EX);
            $event->data['next'] = true; // ask DokuWiki to run the indexer again soon
        }

        // Skip deleted pages
        $content = rawWiki($id);
        if (empty($content)) return;

        if (auth_quickaclcheck($id) < AUTH_READ) {
            \dokuwiki\Logger::error('DokuLLM: Access denied for queued page: ' . $id);
            return;
        }

        try {
            $this->sendPageToChromaDB($id, $content);
        } catch (\Throwable $e) {
            \dokuwiki\Logger::error('DokuLLM: Error indexing queued page ' . $id . ': ' . $e->getMessage());
        }
    }

    /**
     * Return the path to the ChromaDB indexing queue file.
     */
    private function chromaQueueFile()
    {
        global $conf;
        return $conf['savedir'] . '/tmp/plugin_dokullm_queue.json';
    }

    /**
     * Add a page ID to the ChromaDB indexing queue (deduplicates).
     */
    private function enqueueForChroma($id)
    {
        $file = $this->chromaQueueFile();
        $dir  = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $queue = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
        if (!in_array($id, $queue, true)) {
            $queue[] = $id;
            file_put_contents($file, json_encode($queue), LOCK_EX);
        }
    }


    /**
     * Send page content to ChromaDB
     *
     * @param string $pageId The page ID
     * @param string $content The page content
     * @return void
     */
    private function sendPageToChromaDB($pageId, $content)
    {
        // Skip if ChromaDB is disabled
        if (!$this->getConf('enable_chromadb')) {
            return;
        }
        // Convert page ID to file path format for ChromaDB
        $filePath = wikiFN($pageId);
        try {
            // Get configuration values
            $chromaHost = $this->getConf('chroma_host');
            $chromaPort = $this->getConf('chroma_port');
            $chromaTenant = $this->getConf('chroma_tenant');
            $chromaDatabase = $this->getConf('chroma_database');
            $ollamaHost = $this->getConf('ollama_host');
            $ollamaPort = $this->getConf('ollama_port');
            $ollamaModel = $this->getConf('ollama_embeddings_model');
            // Use the existing ChromaDB client to process the file
            $chroma = new \dokuwiki\plugin\dokullm\ChromaDBClient(
                $chromaHost,
                $chromaPort,
                $chromaTenant,
                $chromaDatabase,
                $this->getConf('chroma_default_collection'),
                $ollamaHost,
                $ollamaPort,
                $ollamaModel
            );
            // Use the first part of the document ID as collection name, fallback to configured default
            $idParts = explode(':', $pageId);
            $collectionName = isset($idParts[0]) && !empty($idParts[0]) ? $idParts[0] : $this->getConf('chroma_default_collection');
            // Process the file directly
            $result = $chroma->processSingleFile($filePath, $collectionName, false);
            // Log success or failure
            if ($result['status'] === 'success') {
                \dokuwiki\Logger::debug('DokuLLM: Successfully sent page to ChromaDB: ' . $pageId);
            } else if ($result['status'] === 'skipped') {
                \dokuwiki\Logger::debug('DokuLLM: Skipped sending page to ChromaDB: ' . $pageId . ' - ' . $result['message']);
            } else {
                \dokuwiki\Logger::error('DokuLLM: Error sending page to ChromaDB: ' . $pageId . ' - ' . $result['message']);
            }
        } catch (Exception $e) {
            throw $e;
        }
    }


   /**
     * Handler to load page template.
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */
    public function handleTemplate(Doku_Event &$event, $param) {
        if (strlen($_REQUEST['copyfrom']) > 0) {
            $template_id = $_REQUEST['copyfrom'];
            if (auth_quickaclcheck($template_id) >= AUTH_READ) {
                $tpl = io_readFile(wikiFN($template_id));
                if ($tpl === false || $tpl === '') {
                    \dokuwiki\Logger::error('DokuLLM: Template file could not be read: ' . $template_id);
                    return;
                }
                if ($this->getConf('replace_id')) {
                    $id = $event->data['id'];
                    $tpl = str_replace($template_id, $id, $tpl);
                }
                // Add LLM_TEMPLATE metadata if the original page ID contains 'template'
                if (strpos($template_id, 'template') !== false) {
                    $tpl = $this->insertMetadataAfterTitle($tpl, '~~LLM_TEMPLATE:' . $template_id . '~~');
                }
                $event->data['tpl'] = $tpl;
                $event->preventDefault();
            } else {
                \dokuwiki\Logger::warn('DokuLLM: Access denied to template page: ' . $template_id);
            }
        }
    }



    /**
     * Handle AJAX requests for fetching available models from a provider
     *
     * Responds to the 'plugin_dokullm_models' call.
     * Only accessible to admin users.
     *
     * @param Doku_Event $event The event object
     * @param mixed $param Additional parameters
     */
    public function handleAjaxModels(Doku_Event $event, $param)
    {
        if ($event->data !== 'plugin_dokullm_models') {
            return;
        }

        $event->stopPropagation();
        $event->preventDefault();

        if (!auth_isadmin()) {
            http_status(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        global $INPUT;
        $provider = $INPUT->str('provider', 'openai');

        try {
            $models = $this->fetchModels($provider);
            $this->saveModelCache($provider, $models);
            echo json_encode(['models' => $models]);
        } catch (Exception $e) {
            http_status(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Return the path to the JSON cache file for a provider's model list
     *
     * @param string $provider Provider key: 'openai', 'anthropic', 'ollama', 'ollama_embeddings'
     * @return string Absolute path to the cache file
     */
    private function modelCacheFile($provider)
    {
        global $conf;
        return $conf['savedir'] . '/tmp/plugin_dokullm_models_' . $provider . '.json';
    }

    /**
     * Load the cached model list for a provider
     *
     * @param string $provider Provider key
     * @return array|null Cache data array with 'models' and 'fetched_at', or null if missing/empty
     */
    private function loadModelCache($provider)
    {
        $file = $this->modelCacheFile($provider);
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        return (is_array($data['models'] ?? null) && !empty($data['models'])) ? $data : null;
    }

    /**
     * Write a model list to the provider's cache file
     *
     * @param string $provider Provider key
     * @param array  $models   List of model ID strings
     */
    private function saveModelCache($provider, array $models)
    {
        $file = $this->modelCacheFile($provider);
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode(['models' => $models, 'fetched_at' => time()]));
    }

    /**
     * Fetch models from a provider and save to cache; log and swallow errors
     *
     * Uses a short 5-second timeout so a slow/unreachable provider does not
     * block the admin page load.
     *
     * @param string $provider Provider key
     */
    private function refreshModelCache($provider)
    {
        try {
            $models = $this->fetchModels($provider);
            if (!empty($models)) {
                $this->saveModelCache($provider, $models);
            }
        } catch (Exception $e) {
            \dokuwiki\Logger::error('DokuLLM: model cache refresh failed (' . $provider . '): ' . $e->getMessage());
        }
    }

    /**
     * Dispatch model list fetching to the appropriate provider method
     *
     * @param string $provider One of 'openai', 'anthropic', 'ollama'
     * @return array Sorted list of model ID strings
     * @throws Exception On unknown provider or fetch failure
     */
    private function fetchModels($provider)
    {
        switch ($provider) {
            case 'openai':             return $this->fetchOpenAIModels();
            case 'anthropic':          return $this->fetchAnthropicModels();
            case 'ollama':
            case 'ollama_embeddings':  return $this->fetchOllamaModels();
            default:                   throw new Exception('Unknown provider: ' . $provider);
        }
    }

    /**
     * Fetch model list from an OpenAI-compatible /v1/models endpoint
     *
     * Derives the models URL from the configured openai_api_url by replacing
     * everything after /v1/ with 'models'.
     *
     * @return array Sorted list of model ID strings
     * @throws Exception On cURL or HTTP error
     */
    private function fetchOpenAIModels()
    {
        $apiUrl = $this->getConf('openai_api_url');
        $apiKey = $this->getConf('openai_api_key');

        // Replace path after /v1/ with models; fall back to appending /models
        $modelsUrl = preg_replace('/\/v1\/.*$/', '/v1/models', $apiUrl);
        if ($modelsUrl === $apiUrl) {
            $modelsUrl = rtrim($apiUrl, '/') . '/models';
        }

        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $modelsUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error)         throw new Exception('cURL error: ' . $error);
        if ($httpCode !== 200) throw new Exception('HTTP ' . $httpCode . ': ' . $response);

        $data   = json_decode($response, true);
        $models = [];
        foreach ($data['data'] ?? [] as $model) {
            if (isset($model['id'])) {
                $models[] = $model['id'];
            }
        }
        sort($models);
        return $models;
    }

    /**
     * Fetch model list from Anthropic's /v1/models endpoint
     *
     * @return array List of model ID strings (as returned by Anthropic, newest first)
     * @throws Exception On cURL or HTTP error
     */
    private function fetchAnthropicModels()
    {
        $apiKey = $this->getConf('anthropic_api_key');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/models');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error)         throw new Exception('cURL error: ' . $error);
        if ($httpCode !== 200) throw new Exception('HTTP ' . $httpCode . ': ' . $response);

        $data   = json_decode($response, true);
        $models = [];
        foreach ($data['data'] ?? [] as $model) {
            if (isset($model['id'])) {
                $models[] = $model['id'];
            }
        }
        return $models;
    }

    /**
     * Fetch model list from Ollama's /api/tags endpoint
     *
     * @return array List of model name strings
     * @throws Exception On cURL or HTTP error
     */
    private function fetchOllamaModels()
    {
        $host = $this->getConf('ollama_host');
        $port = $this->getConf('ollama_port');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://' . $host . ':' . $port . '/api/tags');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error)         throw new Exception('cURL error: ' . $error);
        if ($httpCode !== 200) throw new Exception('HTTP ' . $httpCode . ': ' . $response);

        $data   = json_decode($response, true);
        $models = [];
        foreach ($data['models'] ?? [] as $model) {
            if (isset($model['name'])) {
                $models[] = $model['name'];
            }
        }
        return $models;
    }


   /**
     * Add 'Copy page' button to page tools, SVG based
     *
     * @param Doku_Event $event
     */
    public function addCopyPageButton(Doku_Event $event)
    {
        global $INFO;
        if ($event->data['view'] != 'page' || !$this->getConf('show_copy_button')) {
            return;
        }
        if (! $INFO['exists']) {
            return;
        }
        array_splice($event->data['items'], -1, 0, [new \dokuwiki\plugin\dokullm\MenuItem()]);
    }
}
