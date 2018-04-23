<?php

namespace Drupal\feeds_migrate\Plugin\feeds_migrate\data_fetcher;

use Drupal\Core\Form\FormStateInterface;
use Drupal\feeds_migrate\DataFetcherFormPluginBase;
use Drupal\feeds_migrate\FeedsMigrateImporterInterface;
use Drupal\migrate\Plugin\Migration;

/**
 * Provides basic authentication for the HTTP resource.
 *
 * @DataFetcherForm(
 *   id = "http",
 *   title = @Translation("Http"),
 *   parent = "http"
 * )
 */
class Http extends DataFetcherFormPluginBase {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\feeds_migrate\Entity\FeedsMigrateImporter $entity */
    $entity = $form_state->getBuildInfo()['callback_object']->getEntity();
    return [
      'url' => [
        '#type' => 'textfield',
        '#title' => $this->t('URL'),
        '#default_value' => $entity->dataFetcherSettings['http'] ?? '',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function alterMigration(FeedsMigrateImporterInterface $importer, Migration $migration) {
    if (!empty($importer->dataFetcherSettings['http'])) {
      $source_config = $migration->getSourceConfiguration();
      $source_config['urls'] = $importer->dataFetcherSettings['http'];
      $migration->set('source', $source_config);
    }
  }

}