<?php

namespace Drupal\bm_ai_gavias_translate_action\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class SettingsForm extends ConfigFormBase {

  protected LanguageManagerInterface $languageManager;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->languageManager = $container->get('language_manager');
    return $instance;
  }

  public function getFormId(): string {
    return 'bm_ai_gavias_translate_action_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['bm_ai_gavias_translate_action.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('bm_ai_gavias_translate_action.settings');

    $form['field_machine_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gavias field name'),
      '#default_value' => $config->get('field_machine_name') ?? 'gva_pagebuilder_content',
      '#required' => TRUE,
      '#description' => $this->t('Base field (e.g., gva_pagebuilder_content) or configurable field machine name.'),
    ];

    $form['keys'] = [
      '#type' => 'details',
      '#title' => $this->t('Key selection'),
      '#open' => FALSE,
    ];
    $form['keys']['key_whitelist'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Exact keys to translate (one per line)'),
      '#default_value' => implode("\n", (array) $config->get('key_whitelist')),
    ];
    $form['keys']['key_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Regex patterns to translate (one per line)'),
      '#default_value' => implode("\n", (array) $config->get('key_patterns')),
      '#description' => $this->t('PCRE like /^title_\\d+$/'),
    ];
    $form['keys']['preserve_placeholders'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Preserve placeholders like {{ token }} and [shortcode]'),
      '#default_value' => (bool) $config->get('preserve_placeholders'),
    ];

    $form['ai'] = [
      '#type' => 'details',
      '#title' => $this->t('AI provider/model (optional)'),
      '#open' => FALSE,
    ];
    $form['ai']['provider'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Provider ID'),
      '#default_value' => $config->get('provider') ?? '',
      '#description' => $this->t('If left empty, uses the default AI provider configured in /admin/config/ai/ai-translate or global AI settings.'),
    ];
    $form['ai']['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model ID'),
      '#default_value' => $config->get('model') ?? '',
      '#description' => $this->t('Optional model override (e.g., gpt-4o-mini, claude-3-haiku, etc.).'),
    ];

    $form['prompts'] = [
      '#type' => 'details',
      '#title' => $this->t('Per-language prompts'),
      '#open' => TRUE,
    ];

    $existing = (array) $config->get('prompts') ?: [];
    $by_lang = [];
    foreach ($existing as $row) {
      if (isset($row['langcode'])) {
        $by_lang[$row['langcode']] = $row['prompt'] ?? '';
      }
    }
    foreach ($this->languageManager->getLanguages() as $langcode => $lang) {
      $form['prompts']["prompt__{$langcode}"] = [
        '#type' => 'textarea',
        '#title' => $this->t('Prompt for %lang (%code)', ['%lang' => $lang->getName(), '%code' => $langcode]),
        '#default_value' => $by_lang[$langcode] ?? "Translate the provided values into {$langcode}. Translate only the values; do not change keys, JSON structure, placeholders ({{ }}, [shortcode]), HTML, or URLs. Return JSON mapping the same keys to translated values.",
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('bm_ai_gavias_translate_action.settings');

    $config->set('field_machine_name', $form_state->getValue('field_machine_name'));

    $wl = array_values(array_filter(array_map('trim',
      preg_split('/\r?\n/', (string) $form_state->getValue('key_whitelist') ?? '')
    )));
    $config->set('key_whitelist', $wl);

    $pat = array_values(array_filter(array_map('trim',
      preg_split('/\r?\n/', (string) $form_state->getValue('key_patterns') ?? '')
    )));
    $config->set('key_patterns', $pat);

    $config->set('preserve_placeholders', (bool) $form_state->getValue('preserve_placeholders'));
    $config->set('provider', (string) $form_state->getValue('provider'));
    $config->set('model', (string) $form_state->getValue('model'));

    $rows = [];
    foreach ($this->languageManager->getLanguages() as $langcode => $lang) {
      $rows[] = [
        'langcode' => $langcode,
        'prompt' => (string) $form_state->getValue("prompt__{$langcode}"),
      ];
    }
    $config->set('prompts', $rows);

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
