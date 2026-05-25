<?php

namespace classicframework\sitemap;

class Sitemap
{
  protected $config = array();
  protected $routes = array();
  protected $db = null;

  public function __construct($config = array(), $routes = array(), $db = null)
  {
    $this->config = is_array($config) ? $config : array();
    $this->routes = is_array($routes) ? $routes : array();
    $this->db = $db;
  }

  public function handle($request, $app = null)
  {
    if ($request->path() !== $this->path()) {
      return null;
    }

    if (!$this->enabled()) {
      return null;
    }

    if (!is_object($request) || !method_exists($request, 'path')) {
      return null;
    }

    if ($request->path() !== $this->path()) {
      return null;
    }

    if (!headers_sent()) {
      header('Content-Type: application/xml; charset=UTF-8');
      header('X-Robots-Tag: noindex', false);
    }

    return $this->xml($request, $app);
  }

  public function xml($request = null, $app = null)
  {
    $base_url = $this->base_url($request);
    $urls = $this->urls($base_url, $app);

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($urls as $url) {
      $xml .= '  <url>' . "\n";
      $xml .= '    <loc>' . $this->e($url['loc']) . '</loc>' . "\n";

      if ($url['lastmod'] !== '') {
        $xml .= '    <lastmod>' . $this->e($this->format_date($url['lastmod'])) . '</lastmod>' . "\n";
      }

      if ($url['changefreq'] !== '') {
        $xml .= '    <changefreq>' . $this->e($url['changefreq']) . '</changefreq>' . "\n";
      }

      if ($url['priority'] !== '') {
        $xml .= '    <priority>' . $this->e($url['priority']) . '</priority>' . "\n";
      }

      $xml .= '  </url>' . "\n";
    }

    $xml .= '</urlset>' . "\n";

    return $xml;
  }

  public function urls($base_url = '', $app = null)
  {
    $base_url = rtrim((string) $base_url, '/');
    $routes = $this->routes;
    $urls = array();
    $seen = array();

    foreach ($routes as $route) {
      if (!isset($route['route']) || !isset($route['target']) || !is_array($route['target'])) {
        continue;
      }

      $path = (string) $route['route'];
      $target = $route['target'];

      if (!array_key_exists('sitemap', $target)) {
        continue;
      }

      if ($target['sitemap'] === false) {
        continue;
      }

      if (!is_array($target['sitemap'])) {
        continue;
      }

      $sitemap = $target['sitemap'];

      if ($this->has_dynamic_params($path)) {
        $items = $this->dynamic_urls($path, $sitemap, $base_url, $app);

        foreach ($items as $item) {
          if (isset($seen[$item['loc']])) {
            continue;
          }

          $seen[$item['loc']] = true;
          $urls[] = $item;
        }

        continue;
      }

      $loc = $base_url . $path;

      if (isset($seen[$loc])) {
        continue;
      }

      $seen[$loc] = true;

      $urls[] = array(
        'loc' => $loc,
        'lastmod' => $this->option($sitemap, 'lastmod_value', ''),
        'changefreq' => $this->option($sitemap, 'changefreq', $this->default_changefreq()),
        'priority' => $this->option($sitemap, 'priority', $this->default_priority()),
      );
    }

    return $urls;
  }

  protected function dynamic_urls($path, $sitemap, $base_url, $app)
  {
    $urls = array();

    if (!isset($sitemap['items']) || !isset($sitemap['table'])) {
      return $urls;
    }

    $db = $this->db;

    if (!is_object($db) || !method_exists($db, 'rows') || !method_exists($db, 'table')) {
      return $urls;
    }

    $item_column = (string) $sitemap['items'];
    $table = (string) $sitemap['table'];
    $lastmod_column = isset($sitemap['lastmod']) ? (string) $sitemap['lastmod'] : '';

    if (!$this->valid_identifier($item_column) || !$this->valid_identifier($table)) {
      return $urls;
    }

    if ($lastmod_column !== '' && !$this->valid_identifier($lastmod_column)) {
      return $urls;
    }

    $columns = array($item_column);

    if ($lastmod_column !== '' && $lastmod_column !== $item_column) {
      $columns[] = $lastmod_column;
    }

    $sql = 'SELECT ' . implode(', ', $columns)
      . ' FROM ' . $db->table($table);

    if (isset($sitemap['where']) && trim((string) $sitemap['where']) !== '') {
      $sql .= ' WHERE ' . (string) $sitemap['where'];
    }

    $sql .= ' ORDER BY ' . $item_column . ' ASC';

    $rows = $db->rows($sql);

    foreach ($rows as $row) {
      if (!isset($row[$item_column])) {
        continue;
      }

      $params = array(
        $item_column => $row[$item_column],
      );

      $item_path = $this->build_path($path, $params);

      if ($item_path === '') {
        continue;
      }

      $lastmod = '';

      if ($lastmod_column !== '' && isset($row[$lastmod_column])) {
        $lastmod = $row[$lastmod_column];
      }

      $urls[] = array(
        'loc' => rtrim($base_url, '/') . $item_path,
        'lastmod' => $lastmod,
        'changefreq' => $this->option($sitemap, 'changefreq', $this->default_changefreq()),
        'priority' => $this->option($sitemap, 'priority', $this->default_priority()),
      );
    }

    return $urls;
  }

  protected function build_path($path, $params)
  {
    $path = (string) $path;

    foreach ($params as $key => $value) {
      $path = str_replace(':' . $key, rawurlencode((string) $value), $path);
    }

    if ($this->has_dynamic_params($path)) {
      return '';
    }

    if (strpos($path, '*') !== false) {
      return '';
    }

    return $path;
  }

  protected function has_dynamic_params($path)
  {
    return preg_match('/(^|\/):[a-zA-Z0-9_]+/', (string) $path) ? true : false;
  }

  protected function valid_identifier($value)
  {
    return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $value) ? true : false;
  }

  protected function base_url($request)
  {
    if (isset($this->config['base_url']) && trim((string) $this->config['base_url']) !== '') {
      return rtrim((string) $this->config['base_url'], '/');
    }

    if (is_object($request) && method_exists($request, 'scheme') && method_exists($request, 'host')) {
      return $request->scheme() . '://' . $request->host();
    }

    return '';
  }

  protected function format_date($value)
  {
    $time = strtotime((string) $value);

    if ($time === false) {
      return (string) $value;
    }

    return date('Y-m-d', $time);
  }

  protected function option($array, $key, $default = '')
  {
    return isset($array[$key]) ? (string) $array[$key] : (string) $default;
  }

  protected function default_changefreq()
  {
    return isset($this->config['default_changefreq']) ? (string) $this->config['default_changefreq'] : '';
  }

  protected function default_priority()
  {
    return isset($this->config['default_priority']) ? (string) $this->config['default_priority'] : '';
  }

  protected function enabled()
  {
    return isset($this->config['enabled']) ? (bool) $this->config['enabled'] : true;
  }

  protected function path()
  {
    return isset($this->config['path']) ? (string) $this->config['path'] : '/sitemap.xml';
  }

  protected function e($value)
  {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }
}