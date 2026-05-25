<?php

namespace classicframework\sitemap;

use classicframework\core\App;
use classicframework\core\Config;
use classicframework\core\BridgeInterface;

class Bridge implements BridgeInterface
{
  public static function register(App $app)
  {
    $config = Config::extract('sitemap');

    $sitemap = new Sitemap($config);

    $app->set_service('sitemap', $sitemap);
    $app->add_request_handler(array($sitemap, 'handle'));
  }
}