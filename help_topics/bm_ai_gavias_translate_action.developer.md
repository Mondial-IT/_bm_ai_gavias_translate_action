---
title: Developer Guide
---

## How it works
- `hook_form_node_form_alter()` injects a **Translate Gavias (AI)** submit button and a **Current page** checkbox.
- On submit:
  - Chooses source JSON (default-language or current page based on checkbox),
  - Extracts a **dot‑path → text** map (whitelist/patterns),
  - Calls **Drupal AI** (`ai.client`) with a per‑language prompt,
  - Injects translated values back into the current entity translation (no auto‑save).

## Service
- `Drupal\bm_ai_gavias_translate_action\Service\GaviasTranslator`
  - `extractMap(string $json): array`
  - `translateMap(array $map, string $target_lang, string $prompt): array`
  - `injectMap(string $json, array $translations): string`

## Extending
- Swap provider/model in settings or adjust `translateMap()` for your AI backend.
- Extend whitelist/patterns for your Gavias blocks.
