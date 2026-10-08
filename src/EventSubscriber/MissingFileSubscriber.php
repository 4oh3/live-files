<?php

namespace Drupal\live_files\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\live_files\LiveFileUrlResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects requests for missing local files to the live site.
 *
 * Most file URLs are rewritten before they reach the browser, but paths that
 * are hard-coded (images in formatted text, CSS backgrounds) still point at
 * the local site. The web server only hands a file request to Drupal when the
 * file does not exist on disk.
 */
class MissingFileSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected LiveFileUrlResolver $resolver,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Runs before routing so image style derivatives of missing source files
    // never reach the image style controller.
    return [KernelEvents::REQUEST => ['onRequest', 300]];
  }

  /**
   * Responds with a redirect to the live copy of a missing file.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $uri = $this->resolver->pathToUri($request->getPathInfo());
    if (!$uri || !$live_url = $this->resolver->getLiveUrl($uri)) {
      return;
    }

    if ($query = $request->getQueryString()) {
      $live_url .= '?' . $query;
    }
    $response = new TrustedRedirectResponse($live_url);
    $response->addCacheableDependency($this->configFactory->get('live_files.settings'));
    $event->setResponse($response);
  }

}
