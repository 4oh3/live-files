<?php

namespace Drupal\live_files\Controller;

use Drupal\live_files\LiveFileProxy;
use Drupal\live_files\LiveFileUrlResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Delivers a public file fetched from the live site.
 */
class FileProxyController {

  public function __construct(
    protected LiveFileUrlResolver $resolver,
    protected LiveFileProxy $proxy,
  ) {}

  /**
   * Streams a live file requested from /live-files/{filepath}.
   */
  public function deliver(Request $request): Response {
    $file = (string) $request->query->get('file', '');
    $uri = $file !== '' ? $this->resolver->pathToUri($file) : NULL;
    if (!$uri || !$this->resolver->hasBasicAuth() || !$this->resolver->getLiveUrl($uri)) {
      throw new NotFoundHttpException();
    }
    $request->query->remove('file');
    return $this->proxy->fetch($uri, $request);
  }

}
