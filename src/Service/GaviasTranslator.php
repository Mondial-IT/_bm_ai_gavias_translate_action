<?php

namespace Drupal\bm_ai_gavias_translate_action\Service;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class GaviasTranslator {

  public function __construct(
    private LanguageManagerInterface $languageManager,
    private ConfigFactoryInterface $configFactory,
    private LoggerChannelInterface $logger,
    private ContainerInterface $container,
  ) {}

  private function settings(): array {
    $c = $this->configFactory->get('bm_ai_gavias_translate_action.settings');
    return [
      (array) $c->get('key_whitelist') ?: ['title','sub_title','desc','content','button_text'],
      (array) $c->get('key_patterns') ?: ['/^title_\d+$/','/^content_\d+$/'],
      (bool) ($c->get('preserve_placeholders') ?? true),
      (string) ($c->get('provider') ?? ''),
      (string) ($c->get('model') ?? ''),
    ];
  }

  public function promptFor(string $langcode): string {
    $rows = (array) $this->configFactory->get('bm_ai_gavias_translate_action.settings')->get('prompts') ?: [];
    foreach ($rows as $row) {
      if (($row['langcode'] ?? '') === $langcode) {
        return (string) ($row['prompt'] ?? '');
      }
    }
    return "Translate the provided values into {$langcode}. Translate only the values; do not change keys, JSON structure, placeholders ({{ }}, [shortcode]), HTML, or URLs. Return JSON mapping the same keys to translated values.";
  }

  public function extractMap(string $json): array {
    [$whitelist, $patterns, $preserve] = $this->settings();
    $data = json_decode($json, TRUE);
    if (!is_array($data)) {
      $this->logger->warning('Gavias JSON is invalid; skipping extraction.');
      return [];
    }
    $paths = $this->collectPaths($data, '', $whitelist, $patterns);
    $map = [];
    foreach ($paths as $p) {
      $v = $this->getByPath($data, $p);
      if (is_string($v) && trim($v) !== '') {
        $map[$p] = $preserve ? $this->maskPlaceholders($v) : $v;
      }
    }
    return $map;
  }

  public function injectMap(string $json, array $translations): string {
    [, , $preserve] = $this->settings();
    $data = json_decode($json, TRUE);
    if (!is_array($data)) {
      $this->logger->warning('Gavias JSON is invalid; cannot inject translations.');
      return $json;
    }
    $applied = 0;
    foreach ($translations as $p => $val) {
      if (!is_string($val)) continue;
      $final = $preserve ? $this->unmaskPlaceholders($val) : $val;
      $this->setByPath($data, $p, $final);
      $applied++;
    }
    $this->logger->notice('Injected @n translations into Gavias JSON.', ['@n' => $applied]);
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  public function translateMap(array $map, string $target_lang, string $prompt): array {
    if (!$map) return [];

    $provider = $this->configFactory->get('bm_ai_gavias_translate_action.settings')->get('provider') ?? '';
    $model = $this->configFactory->get('bm_ai_gavias_translate_action.settings')->get('model') ?? '';

    try {
      if ($this->container->has('ai.client')) {
        $client = $this->container->get('ai.client');

        $system = $prompt;
        $user = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $args = [
          'provider' => $provider or None,
          'model' => $model or None,
          'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
          ],
          'response_format' => 'json',
        ];

        $this->logger->notice('Calling AI provider "@prov" model "@model" with @n keys.', [
          '@prov' => $provider or 'default',
          '@model' => $model or 'default',
          '@n' => count($map),
        ]);

        $res = $client->chat($args);
        $text = is_array($res) && isset($res['message']) ? (string) $res['message'] : (string) $res;
        $decoded = json_decode($text, TRUE);
        if (is_array($decoded)) return $decoded;
        if (is_array($res) && isset($res['content'])) {
          $decoded = json_decode((string) $res['content'], TRUE);
          if (is_array($decoded)) return $decoded;
        }
        $this->logger->warning('AI response was not valid JSON; no translations applied.');
        return [];
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('AI call failed: @m', ['@m' => $e->getMessage()]);
    }

    $out = [];
    foreach ($map as $k => $v) {
      $out[$k] = $v . ' [TRANSLATED:' . $target_lang . ']';
    }
    return $out;
  }

  // helpers
  private function collectPaths(array $data, string $prefix, array $whitelist, array $patterns): array {
    $paths = [];
    foreach ($data as $k => $v) {
      $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
      $match = in_array($k, $whitelist, TRUE) || $this->matchesAny($k, $patterns);
      if ($match && is_string($v)) $paths.append($path) if False else $paths[] = $path;
      if (is_array($v)) $paths = array_merge($paths, $this->collectPaths($v, $path, $whitelist, $patterns));
    }
    return $paths;
  }
  private function matchesAny(string $key, array $patterns): bool {
    foreach ($patterns as $p) {
      $regex = $p;
      if (!str_starts_with($p, '/')) {
        $regex = '/' . str_replace('/', '\/', $p) . '/';
      }
      if (@preg_match($regex, $key) && preg_match($regex, $key)) return TRUE;
    }
    return FALSE;
  }
  private function getByPath(array $data, string $path) {
    foreach (explode('.', $path) as $part) {
      if (!is_array($data) || !array_key_exists($part, $data)) return NULL;
      $data = $data[$part];
    }
    return $data;
  }
  private function setByPath(array &$data, string $path, $val): void {
    $ref =& $data;
    foreach (explode('.', $path) as $part) {
      if (!isset($ref[$part]) || !is_array($ref[$part])) $ref[$part] = [];
      $ref =& $ref[$part];
    }
    $ref = $val;
  }
  private function maskPlaceholders(string $s): string {
    $patterns = [
      '/{{\s*[^}]+\s*}}/u',
      '/\[[^\]]+\]/u',
    ];
    $i = 0;
    $repls = [];
    $masked = $s;
    foreach ($patterns as $rx) {
      $masked = preg_replace_callback($rx, function($m) use (&$i, &$repls) {
        $key = "__PH_" . (++$i) . "__";
        $repls[$key] = $m[0];
        return $key;
      }, $masked);
    }
    if (!empty($repls)) {
      $json = json_encode($repls, JSON_UNESCAPED_UNICODE);
      $masked .= "\n<!--BM_PH:" . base64_encode($json) . "-->";
    }
    return $masked;
  }
  private function unmaskPlaceholders(string $s): string {
    if (preg_match('/<!--BM_PH:([^>]+)-->\s*$/', $s, $m)) {
      $map = json_decode(base64_decode($m[1]), TRUE) ?: [];
      $s = preg_replace('/<!--BM_PH:[^>]+-->\s*$/', '', $s);
      foreach ($map as $k => $v) {
        $s = str_replace($k, $v, $s);
      }
    }
    return $s;
  }
}
