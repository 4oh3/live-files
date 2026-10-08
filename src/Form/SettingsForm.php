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
      '#description' => $this->t('For example <code>https://www.example.com</code>. Leave empty to serve all files locally.'),
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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $base_url = trim($form_state->getValue('base_url'));
    if ($base_url !== '' && !preg_match('#^https?://[^/]+#i', $base_url)) {
      $form_state->setErrorByName('base_url', $this->t('Enter an absolute URL starting with http:// or https://.'));
    }
    elseif ($base_url !== '' && strcasecmp((string) parse_url($base_url, PHP_URL_HOST), $this->getRequest()->getHost()) === 0) {
      $form_state->setErrorByName('base_url', $this->t('The live site URL cannot be this site (%host). Enter the URL of the live site.', ['%host' => $this->getRequest()->getHost()]));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('live_files.settings')
      ->set('base_url', rtrim(trim($form_state->getValue('base_url')), '/'))
      ->set('mode', $form_state->getValue('mode'))
      ->set('all_files', (bool) $form_state->getValue('all_files'))
      ->save();

    Cache::invalidateTags(['rendered']);

    parent::submitForm($form, $form_state);
  }

}
