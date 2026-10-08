<?php

namespace Drupal\live_files;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Maps local public file URIs to their URLs on the live site.
 */
class LiveFileUrlResolver {

  /**
   * File extensions treated as images.
   */
  const IMAGE_EXTENSIONS = [
    'avif',
    'bmp',
    'gif',
    'ico',
    'jpeg',
    'jpg',
    'png',
    'svg',
    'tif',
    'tiff',
    'webp',
  ];

  /**
   * Public file directories that are generated locally, never fetched.
   */
  const EXCLUDED_DIRECTORIES = ['css/', 'js/', 'php/'];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Gets the module configuration.
   */
  public function config(): ImmutableConfig {
    return $this->configFactory->get('live_files.settings');
  }

  /**
   * Gets the live site URL for a local file URI.
   *
   * @param string $uri
   *   A file URI, which may be an image style derivative URI.
   *
   * @return string|null
   *   The live URL, or NULL if the file should be served locally.
   */
  public function getLiveUrl(string $uri): ?string {
    $config = $this->config();
    $base_url = rtrim(trim((string) $config->get('base_url')), '/');
    if ($base_url === '' || StreamWrapperManager::getScheme($uri) !== 'public') {
      return NULL;
    }
    // Pointing at the current host would redirect missing files to themselves.
    $host = $this->requestStack->getCurrentRequest()?->getHost();
    if ($host && strcasecmp((string) parse_url($base_url, PHP_URL_HOST), $host) === 0) {
      return NULL;
    }

    $target = strtok((string) StreamWrapperManager::getTarget($uri), '?');
    if ($target === '' || $target === FALSE) {
      return NULL;
    }
    foreach (self::EXCLUDED_DIRECTORIES as $directory) {
      if (str_starts_with($target, $directory)) {
        return NULL;
      }
    }

    if (!$config->get('all_files') && !$this->isImage($target)) {
      return NULL;
    }
    if ($config->get('mode') === 'missing' && file_exists($this->getSourceUri($uri))) {
      return NULL;
    }

    return $base_url . '/' . $this->getFilesPath() . '/' . UrlHelper::encodePath($target);
  }

  /**
   * Converts a request path (relative to the base path) into a public URI.
   */
  public function pathToUri(string $path): ?string {
    $path = rawurldecode(ltrim($path, '/'));
    $prefix = $this->getFilesPath() . '/';
    return str_starts_with($path, $prefix) ? 'public://' . substr($path, strlen($prefix)) : NULL;
  }

  /**
   * Gets the original file URI for a possible image style derivative URI.
   */
  public function getSourceUri(string $uri): string {
    if (!preg_match('#^[a-z0-9.+-]+://styles/[^/]+/([a-z0-9.+-]+)/(.+)$#i', strtok($uri, '?'), $matches)) {
      return $uri;
    }
    $source = $matches[1] . '://' . $matches[2];

    // Styles that convert formats (e.g. to WebP) append the new extension to
    // the original filename.
    $extensions = implode('|', self::IMAGE_EXTENSIONS);
    if (preg_match('#^(.+\.(?:' . $extensions . '))\.(?:' . $extensions . ')$#i', $source, $converted)) {
      $source = $converted[1];
    }
    return $source;
  }

  /**
   * Determines whether a path has an image file extension.
   */
  protected function isImage(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, TRUE);
  }

  /**
   * Gets the public files path, e.g. "sites/default/files".
   */
  protected function getFilesPath(): string {
    /** @var \Drupal\Core\StreamWrapper\LocalStream $wrapper */
    $wrapper = $this->streamWrapperManager->getViaScheme('public');
    return trim($wrapper->getDirectoryPath(), '/');
  }

}
