# TODO

- let's have separate  API key and model config entries for anthropic, to make the switch from openai to anthropic and back seamlessly.
  let's rename the current api_url, api_key and model to openai_api_url, openai_api_key and openai_model, then add anthropic_api_key and anthropic_model.
  do all the changes in config files, documentation, backend, frontend, etc.  how do you plan this?
- add a new provider: ollama (besides the existing embedings provider)
- Some buttons can be shown in view mode also. See PageButtons
- Stream the response 
- Copy page is not working on mobile and other themes. Try to use PHP for this. 
- Create the documentation 
- fix the cli.php: ACL, options for query 
- add a new placeholder in SYSTEM prompt to always provide the current date and time
- for all provides, try to get the models and use a dropdown to let the user choose the model instead of typing the model name
- try to get the cost report (for anthtopic) to keep the user up to date
- study if we can use workspaces (projects) in anthropic
- investigate how to use the cache in anthropic
- in php rename callApi to callOpenAiApi. the same for all othe functions interacting with OpenAI compatibile API. this will make clear differentiations to Anthropic and Ollama