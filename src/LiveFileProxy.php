<?php

namespace Drupal\live_files;

use Drupal\Component\Utility\UrlHelper;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * Fetches a public file from the live site using HTTP basic authentication.
 */
class LiveFileProxy {

  /**
   * Response headers copied from the live site.
   */
  const FORWARDED_HEADERS = [
    'Content-Length',
    'Content-Range',
    'Accept-Ranges',
    'Content-Encoding',
    'Content-Disposition',
    'ETag',
    'Last-Modified',
  ];

  public function __construct(
    protected LiveFileUrlResolver $resolver,
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Fetches a public file and returns it as a response.
   */
  public function fetch(string $uri, Request $request): Response {
    $url = $this->resolver->getLiveUrl($uri);
    $username = trim((string) ($this->resolver->config()->get('auth_username') ?? ''));
    $password = (string) ($this->resolver->config()->get('auth_password') ?? '');
    $host = parse_url((string) $this->resolver->config()->get('base_url'), PHP_URL_HOST);
    if (!$url || $username === '' || !is_string($host) || strcasecmp((string) parse_url($url, PHP_URL_HOST), $host) !== 0) {
      return new Response('Not found', 404);
    }

    $query = $request->query->all();
    unset($query['live_files']);
    if ($query) {
      $url .= (str_contains($url, '?') ? '&' : '?') . UrlHelper::buildQuery($query);
    }

    $method = $request->getMethod() === 'HEAD' ? 'HEAD' : 'GET';
    $headers = [];
    foreach (['Range', 'If-Range', 'If-None-Match', 'If-Modified-Since'] as $name) {
      if ($request->headers->has($name)) {
        $headers[$name] = $request->headers->get($name);
      }
    }

    try {
      $upstream = $this->request($method, $url, $username, $password, $host, $headers);
    }
    catch (GuzzleException | \RuntimeException $e) {
      $message = $e->getMessage();
      if ($password !== '') {
        $message = str_replace([$password, rawurlencode($password)], '***', $message);
      }
      $this->logger->error('Could not fetch @url from the live site: @message', [
        '@url' => $url,
        '@message' => $message,
      ]);
      return new Response('The live site could not be reached.', 502);
    }

    $status = $upstream->getStatusCode();
    if ($status === 401 || $status === 403) {
      $this->logger->warning('The live site refused access to @url. Check the HTTP authentication username and password.', ['@url' => $url]);
    }

    $response_headers = [];
    $content_type = $this->contentType($upstream->getHeaderLine('Content-Type'), $url);
    if ($content_type !== NULL) {
      $response_headers['Content-Type'] = $content_type;
    }
    foreach (self::FORWARDED_HEADERS as $name) {
      if ($upstream->hasHeader($name)) {
        $response_headers[$name] = $upstream->getHeaderLine($name);
      }
    }
    $response_headers['Cache-Control'] = $this->browserCacheControl($upstream->getHeaderLine('Cache-Control'));

    $body = $upstream->getBody();
    return new StreamedResponse(function () use ($body, $method) {
      if ($method === 'HEAD') {
        $body->close();
        return;
      }
      while (ob_get_level() > 0) {
        ob_end_clean();
      }
      while (!$body->eof() && !connection_aborted()) {
        echo $body->read(8192);
        flush();
      }
      $body->close();
    }, $status, $response_headers);
  }

  /**
   * Requests a URL, following redirects that stay on the live site.
   *
   * Credentials are sent again after a redirect. Guzzle would drop them when
   * the scheme changes, such as an HTTP to HTTPS redirect.
   */
  protected function request(string $method, string $url, string $username, string $password, string $host, array $headers): ResponseInterface {
    $options = [
      'auth' => [$username, $password],
      'headers' => $headers,
      'http_errors' => FALSE,
      'stream' => TRUE,
      'decode_content' => FALSE,
      'connect_timeout' => 10,
      'timeout' => 300,
      'allow_redirects' => FALSE,
    ];

    for ($attempt = 0; $attempt < 5; $attempt++) {
      $upstream = $this->httpClient->request($method, $url, $options);
      $status = $upstream->getStatusCode();
      if ($status < 300 || $status >= 400 || !$upstream->hasHeader('Location')) {
        return $upstream;
      }
      $next = $this->redirectUrl($url, $upstream->getHeaderLine('Location'), $host);
      $upstream->getBody()->close();
      if ($next === NULL) {
        throw new \RuntimeException('Redirect left the configured live site.');
      }
      $url = $next;
      if (in_array($status, [301, 302, 303], TRUE) && $method !== 'HEAD') {
        $method = 'GET';
      }
    }
    throw new \RuntimeException('The live site redirected too many times.');
  }

  /**
   * Resolves a redirect that stays on the configured live site.
   */
  protected function redirectUrl(string $current, string $location, string $host): ?string {
    if (str_starts_with($location, '//')) {
      $scheme = parse_url($current, PHP_URL_SCHEME);
      $location = (is_string($scheme) ? $scheme : 'https') . ':' . $location;
    }
    elseif (!preg_match('#^https?://#i', $location)) {
      $parts = parse_url($current);
      if ($parts === FALSE || empty($parts['scheme']) || empty($parts['host'])) {
        return NULL;
      }
      $origin = $parts['scheme'] . '://' . $parts['host'];
      if (isset($parts['port'])) {
        $origin .= ':' . $parts['port'];
      }
      if (str_starts_with($location, '/')) {
        $location = $origin . $location;
      }
      else {
        $path = $parts['path'] ?? '/';
        $directory = strrpos($path, '/') !== FALSE ? substr($path, 0, (int) strrpos($path, '/')) : '';
        $location = $origin . $directory . '/' . $location;
      }
    }

    $parts = parse_url($location);
    if ($parts === FALSE || empty($parts['scheme']) || empty($parts['host'])) {
      return NULL;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], TRUE) || strcasecmp($parts['host'], $host) !== 0) {
      return NULL;
    }
    $url = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
      $url .= ':' . $parts['port'];
    }
    $url .= $parts['path'] ?? '/';
    if (isset($parts['query'])) {
      $url .= '?' . $parts['query'];
    }
    return $url;
  }

  /**
   * Uses the upstream content type, or guesses one from the file extension.
   */
  protected function contentType(string $header, string $url): ?string {
    if ($header !== '') {
      return $header;
    }
    $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if ($extension === '') {
      return NULL;
    }
    $types = (new MimeTypes())->getMimeTypes($extension);
    return $types[0] ?? NULL;
  }

  /**
   * Keeps browser caching but stops shared caches from storing the response.
   */
  protected function browserCacheControl(string $value): string {
    $parts = [];
    foreach (explode(',', $value) as $part) {
      $part = trim($part);
      if ($part === '' || str_starts_with(strtolower($part), 's-maxage=')) {
        continue;
      }
      $parts[] = strcasecmp($part, 'public') === 0 ? 'private' : $part;
    }
    if (!$parts) {
      return 'private, max-age=3600';
    }
    $directives = array_map(fn ($part) => strtolower(trim(explode('=', $part, 2)[0])), $parts);
    if (!array_intersect($directives, ['private', 'no-store', 'no-cache'])) {
      array_unshift($parts, 'private');
    }
    $value = implode(', ', $parts);
    // Drupal treats these exact values as an unset Cache-Control header.
    if ($value === 'private, must-revalidate' || $value === 'no-cache, private') {
      return $value . ', max-age=0';
    }
    return $value;
  }

}
