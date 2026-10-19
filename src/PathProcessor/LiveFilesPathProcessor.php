<?php

namespace Drupal\live_files\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Maps /live-files/{filepath} onto the file proxy route.
 */
class LiveFilesPathProcessor implements InboundPathProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    $prefix = '/live-files/';
    if (!str_starts_with($path, $prefix) || $request->query->has('file')) {
      return $path;
    }
    $file_path = substr($path, strlen($prefix));
    if ($file_path === '' || str_contains($file_path, "\0")) {
      return $path;
    }
    $request->query->set('file', $file_path);
    return '/live-files';
  }

}
