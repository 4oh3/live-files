<?php

namespace Drupal\live_files\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures where live files are fetched from.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'live_files_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['live_files.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('live_files.settings');

    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Live site URL'),
      '#description' => $this->t('For example <code>https://www.example.com</code>. Leave empty to serve all files locally. Enter the site address only. A username and password in the URL will not work in the browser; use HTTP authentication below.'),
      '#default_value' => $config->get('base_url'),
    ];

    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Files to serve from the live site'),
      '#options' => [
        'all' => $this->t('All files, even ones that exist locally'),
        'missing' => $this->t('Only files missing from the local files directory'),
      ],
      '#default_value' => $config->get('mode'),
    ];

    $form['all_files'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also serve non-image files (PDFs, documents, video) from the live site'),
      '#default_value' => $config->get('all_files'),
    ];

    $username = (string) ($config->get('auth_username') ?? '');
    $form['auth'] = [
      '#type' => 'details',
      '#title' => $this->t('HTTP authentication'),
      '#description' => $this->t('For a live site that asks for a username and password (htaccess). This site fetches those files and sends the credentials, so the password never appears in an image URL. The password is stored in plain text in the module configuration; keep the module out of config sync.'),
      '#open' => $username !== '',
    ];
    $form['auth']['auth_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $username,
      '#description' => $this->t('Leave empty when the live site is public. Clear the username and save to remove a stored password.'),
    ];
    $form['auth']['auth_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => (string) ($config->get('auth_password') ?? '') !== ''
        ? $this->t('A password is saved. Leave blank to keep it.')
        : $this->t('Required when a username is set.'),
      '#attributes' => ['autocomplete' => 'new-password'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $base_url = $this->normalizeBaseUrl(trim($form_state->getValue('base_url')), $form_state);
    $form_state->setValue('base_url', $base_url);
    if ($base_url !== '' && !preg_match('#^https?://[^/]+#i', $base_url)) {
      $form_state->setErrorByName('base_url', $this->t('Enter an absolute URL starting with http:// or https://.'));
    }
    elseif ($base_url !== '' && strcasecmp((string) parse_url($base_url, PHP_URL_HOST), $this->getRequest()->getHost()) === 0) {
      $form_state->setErrorByName('base_url', $this->t('The live site URL cannot be this site (%host). Enter the URL of the live site.', ['%host' => $this->getRequest()->getHost()]));
    }

    $username = trim((string) $form_state->getValue('auth_username'));
    $password = (string) $form_state->getValue('auth_password');
    $form_state->setValue('auth_username', $username);
    if (strpbrk($username, "\r\n") !== FALSE || strpbrk($password, "\r\n") !== FALSE) {
      $form_state->setErrorByName('auth_username', $this->t('The username and password cannot contain line breaks.'));
    }
    elseif ($username !== '' && $password === '' && (string) ($this->config('live_files.settings')->get('auth_password') ?? '') === '') {
      $form_state->setErrorByName('auth_password', $this->t('Enter the password for the live site.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * Removes credentials from the live site URL and saves them as auth settings.
   */
  protected function normalizeBaseUrl(string $base_url, FormStateInterface $form_state): string {
    if ($base_url === '') {
      return '';
    }
    $parts = parse_url($base_url);
    if ($parts === FALSE || empty($parts['host']) || (empty($parts['user']) && !isset($parts['pass']))) {
      return $base_url;
    }
    if (!empty($parts['user']) && trim((string) $form_state->getValue('auth_username')) === '') {
      $form_state->setValue('auth_username', rawurldecode($parts['user']));
    }
    if (isset($parts['pass']) && $parts['pass'] !== '' && (string) $form_state->getValue('auth_password') === '') {
      $form_state->setValue('auth_password', rawurldecode($parts['pass']));
    }
    if (empty($parts['scheme'])) {
      return $base_url;
    }
    $url = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
      $url .= ':' . $parts['port'];
    }
    $url .= $parts['path'] ?? '';
    if (isset($parts['query'])) {
      $url .= '?' . $parts['query'];
    }
    return $url;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('live_files.settings');
    $username = trim((string) $form_state->getValue('auth_username'));
    $password = (string) $form_state->getValue('auth_password');
    if ($username === '') {
      $password = '';
    }
    elseif ($password === '') {
      $password = (string) ($config->get('auth_password') ?? '');
    }

    $config
      ->set('base_url', rtrim(trim($form_state->getValue('base_url')), '/'))
      ->set('mode', $form_state->getValue('mode'))
      ->set('all_files', (bool) $form_state->getValue('all_files'))
      ->set('auth_username', $username)
      ->set('auth_password', $password)
      ->save();

    Cache::invalidateTags(['rendered']);

    parent::submitForm($form, $form_state);
  }

}
