---
title: BM AI Gavias Translate Action — Overview
---

Adds a **Translate Gavias (AI)** button to the node edit form. It translates the Gavias Page Builder JSON from either:
- the **site default language**, or
- the **Current page** JSON (if you tick the checkbox),
into the language of the form you are editing, then writes the translated values back **without changing JSON structure**.

- Settings: `/admin/config/content/bm-ai-gavias-translate-action`
- Uses **Drupal AI** (drupal/ai) if available; otherwise simulates (for testing) and logs warnings.

### Option: Current page
- Tick **Current page** to use the JSON from the page you are editing as the source.
- Leave it unticked to translate from the **default-language** JSON.
