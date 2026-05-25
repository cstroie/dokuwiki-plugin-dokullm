# TODO

## Done

- [x] Let's have separate API key and model config entries for anthropic, to make the switch from openai to anthropic and back seamlessly.
  Renamed to openai_api_url, openai_api_key, openai_model; added anthropic_api_key and anthropic_model.
- [x] Add a new provider: ollama (besides the existing embeddings provider)
- [x] Add a new placeholder in SYSTEM prompt to always provide the current date and time (`{current_date}`, `{current_time}`)
- [x] For all providers, try to get the models and use a dropdown to let the user choose the model instead of typing the model name
  (model list cached in `data/tmp/`, auto-refreshed on admin page load, rendered as `multichoice` dropdown)
- [x] In PHP rename callApi to callOpenAiApi. The same for all other functions interacting with OpenAI-compatible API.
- [x] Fix the cli.php: options for query; add collection option to get

## Pending

- [ ] Some buttons can be shown in view mode also. See PageButtons
- [ ] Stream the response
- [ ] Copy page is not working on mobile and other themes. Try to use PHP for this.
- [ ] Create the documentation
- [ ] Try to get the cost report (for Anthropic) to keep the user up to date
- [ ] Study if we can use workspaces (projects) in Anthropic
- [ ] Investigate how to use the cache in Anthropic
