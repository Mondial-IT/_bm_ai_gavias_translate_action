---
title: Admin & Editor Guide
---

## Prereqs
- Content Translation enabled; node types translatable.
- Gavias JSON in the `gva_pagebuilder_content` base field (or configure a different field).
- Optional: **Drupal AI** configured with a provider/model.

## Configure
- Go to `/admin/config/content/bm-ai-gavias-translate-action`.
- Set the **field name** (default: `gva_pagebuilder_content`).
- Define **whitelist/patterns** for keys to translate.
- Provide **per-language prompts**.
- Optionally set **provider** and **model** overrides.

## Use
- Open a node, switch to the target translation.
- (Optional) Tick **Current page** to use the current translation's JSON as the source.
- Click **Translate Gavias (AI)**.
- Review the updated content, then **Save** to persist.

## Messages & Logs
- Messenger shows counts sent/applied and the source used.
- Watchdog channel: `bm_ai_gavias_translate_action` for debug entries and AI call outcomes.
