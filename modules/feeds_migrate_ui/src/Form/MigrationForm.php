<?php

namespace Drupal\feeds_migrate_ui\Form;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\feeds_migrate\AuthenticationFormPluginManager;
use Drupal\feeds_migrate\DataFetcherFormPluginManager;
use Drupal\feeds_migrate\DataParserPluginManager;
use Drupal\feeds_migrate\Plugin\PluginFormFactory;
use Drupal\feeds_migrate_ui\FeedsMigrateUiFieldManager;
use Drupal\feeds_migrate_ui\FeedsMigrateUiParserSuggestion;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_plus\Entity\MigrationGroup;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MigrationForm.
 *
 * @package Drupal\feeds_migrate_ui\Form
 */
class MigrationForm extends EntityForm {

  /**
   * Form steps.
   */
  const STEP_ONE = 1;

  const STEP_TWO = 2;

  const STEP_THREE = 3;

  const STEP_FOUR = 4;

  const STEP_FINALIZE = 4;

  /**
   * Current step for the form.
   *
   * @var int
   */
  protected $currentStep = 1;

  /**
   * Plugin manager for migration plugins.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected $migrationPluginManager;

  /**
   * The form factory.
   *
   * @var \Drupal\feeds_migrate\Plugin\PluginFormFactory
   */
  protected $formFactory;

  /**
   * Fill This.
   *
   * @var \Drupal\feeds_migrate_ui\FeedsMigrateUiParserSuggestion
   */
  protected $parserSuggestion;

  /**
   * Fill This.
   *
   * @var \Drupal\migrate_plus\AuthenticationPluginManager
   */
  protected $authPlugins;

  /**
   * Fill This.
   *
   * @var \Drupal\migrate_plus\DataFetcherPluginManager
   */
  protected $fetcherPlugins;

  /**
   * Fill This.
   *
   * @var \Drupal\feeds_migrate_ui\FeedsMigrateUiFieldProcessorManager
   */
  protected $fieldProcessorManager;

  /**
   * Fill This.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Fill This.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $fieldManager;

  /**
   * Fill This.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleManager;

  /**
   * Fill This.
   *
   * @var \Drupal\feeds_migrate\DataParserPluginManager
   */
  protected $parserManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.migration'),
      $container->get('feeds_migrate.plugin_form_factory'),
      $container->get('plugin.manager.feeds_migrate.data_parser_form'),
      $container->get('feeds_migrate_ui.parser_suggestion'),
      $container->get('plugin.manager.feeds_migrate.authentication_form'),
      $container->get('plugin.manager.feeds_migrate.data_fetcher_form'),
      $container->get('plugin.manager.feeds_migrate_ui.field'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * @todo: document.
   */
  public function __construct(MigrationPluginManagerInterface $migration_plugin_manager, PluginFormFactory $form_factory, DataParserPluginManager $parser_manager, FeedsMigrateUiParserSuggestion $parser_suggestion, AuthenticationFormPluginManager $authentication_plugins, DataFetcherFormPluginManager $fetcher_plugins, FeedsMigrateUiFieldManager $field_processor, EntityFieldManager $field_manager) {
    $this->migrationPluginManager = $migration_plugin_manager;
    $this->formFactory = $form_factory;
    $this->parserManager = $parser_manager;
    $this->parserSuggestion = $parser_suggestion;
    $this->authPlugins = $authentication_plugins;
    $this->fetcherPlugins = $fetcher_plugins;
    $this->fieldProcessorManager = $field_processor;
    $this->fieldManager = $field_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareEntity() {
    // Ensure some values are set on the entity in order to comply to the config
    // schema.
    $defaults = [
      'source' => [],
      'process' => [],
      'destination' => [],
      'migration_tags' => [],
      'migration_dependencies' => [],
    ];

    foreach ($defaults as $key => $value) {
      if (is_null($this->entity->get($key))) {
        $this->entity->set($key, $value);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Save');

    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;
    $values = $form_state->getValues();

    $form['basics'] = [
      '#title' => $this->t('Basic settings'),
      '#type' => 'details',
      '#open' => $this->entity->isNew(),
      '#tree' => FALSE,
    ];

    $form['basics']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->label(),
      '#description' => $this->t('Label for the @type.', [
        '@type' => $this->entity->getEntityType()->getLabel(),
      ]),
      '#required' => TRUE,
    ];

    $entity_class = $this->entity->getEntityType()->getClass();
    $form['basics']['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#disabled' => !$this->entity->isNew(),
      '#maxlength' => EntityTypeInterface::BUNDLE_MAX_LENGTH,
      '#machine_name' => [
        'exists' => '\\' . $entity_class . '::load',
        'replace_pattern' => '[^a-z0-9_]+',
        'replace' => '_',
        'source' => ['basics', 'label'],
      ],
    ];

    $form['plugin_settings'] = [
      '#type' => 'vertical_tabs',
      '#weight' => 99,
    ];

    $form['plugin_settings']['#prefix'] = '<div id="feeds-ajax-form-wrapper" class="feeds-feed-type-secondary-settings">';
    $form['plugin_settings']['#suffix'] = '</div>';

    $form['feed_type_settings'] = [
      '#type' => 'details',
      '#group' => 'plugin_settings',
      '#title' => $this->t('Settings'),
      '#tree' => FALSE,
    ];

    // Settings.
    $groups = MigrationGroup::loadMultiple();
    $group_options = [];
    foreach ($groups as $group) {
      $group_options[$group->id()] = $group->label();
    }
    if (!$this->entity->get('migration_group') && isset($group_options['default'])) {
      $this->entity->set('migration_group', 'default');
    }

    $form['feed_type_settings']['migration_group'] = [
      '#type' => 'select',
      '#title' => $this->t('Migration Group'),
      '#empty_value' => '',
      '#default_value' => $this->entity->get('migration_group'),
      '#options' => $group_options,
      '#description' => $this->t('Assign this migration to an existing group.'),
    ];

    // Plugin forms.
    foreach ($this->getPlugins() as $type => $plugin) {
      $options = $this->getPluginOptionsList($type);
      natcasesort($options);

      $form[$type . '_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['feeds-plugin-inline']],
      ];

      if (count($options) === 1) {
        $form[$type . '_wrapper']['id'] = [
          '#type' => 'value',
          '#value' => $plugin ? $plugin->getPluginId() : NULL,
          '#plugin_type' => $type,
          '#parents' => [$type],
        ];
      }
      else {
        $form[$type . '_wrapper']['id'] = [
          '#type' => 'select',
          '#title' => $this->t('@type', ['@type' => ucfirst($type)]),
          '#options' => $options,
          '#default_value' => $plugin ? $plugin->getPluginId() : NULL,
          '#ajax' => [
            'callback' => '::ajaxCallback',
            'wrapper' => 'feeds-ajax-form-wrapper',
          ],
          '#plugin_type' => $type,
          '#parents' => [$type],
        ];
      }

      // We can't instantiate the data parser plugin without causing issues with
      // migrate trying to read from a real source.  So we create a workaround.
      if ($type == 'parser') {
        $source = $this->entity->get('source');
        $form[$type . '_wrapper']['id']['#default_value'] = isset($source['data_parser_plugin']) ? $source['data_parser_plugin'] : NULL;
      }

      $plugin_state = $this->createSubFormState($type . '_configuration', $form_state);

      // This is the small form that appears under the select box.
      if ($plugin && $this->formFactory->hasForm($plugin, 'option')) {
        $option_form = $this->formFactory->createInstance($plugin, 'option');
        $form[$type . '_wrapper']['advanced'] = $option_form->buildConfigurationForm([], $plugin_state);
      }

      $form[$type . '_wrapper']['advanced']['#prefix'] = '<div id="feeds-plugin-' . $type . '-advanced">';
      $form[$type . '_wrapper']['advanced']['#suffix'] = '</div>';

      if ($plugin && $this->formFactory->hasForm($plugin, 'configuration')) {
        $form_builder = $this->formFactory->createInstance($plugin, 'configuration');

        $plugin_form = $form_builder->buildConfigurationForm([], $plugin_state);
        $form[$type . '_configuration'] = [
          '#type' => 'details',
          '#group' => 'plugin_settings',
          '#title' => $this->t('@type settings', ['@type' => ucfirst($type)]),
        ];
        $form[$type . '_configuration'] += $plugin_form;
      }
    }

    return parent::form($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getErrors()) {
      return;
    }
    $values =& $form_state->getValues();

    // Validate option form for each plugin.
    foreach ($this->getPlugins() as $type => $plugin) {
      $plugin_state = $this->createSubFormState($type . '_configuration', $form_state);
      if ($plugin && isset($form[$type . '_configuration']) && $this->formFactory->hasForm($plugin, 'option')) {
        $option_form = $this->formFactory->createInstance($plugin, 'option');
        $option_form->validateConfigurationForm($form[$type . '_configuration'], $plugin_state);
        $form_state->setValue($type . '_configuration', $plugin_state->getValues());
      }
    }

    // Validate settings form for each plugin.
    foreach ($this->getPluginForms() as $type => $plugin_form) {
      if (!isset($form[$type . '_configuration'])) {
        // When switching from a non-configurable plugin to a configurable
        // plugin, no form is yet available. So skip validating it to avoid
        // fatal errors.
        continue;
      }

      $plugin_state = $this->createSubFormState($type . '_configuration', $form_state);
      $plugin_form->validateConfigurationForm($form[$type . '_configuration'], $plugin_state);
      $form_state->setValue($type . '_configuration', $plugin_state->getValues());

      $this->moveFormStateErrors($plugin_state, $form_state);
    }

    // Build the feed type object from the submitted values.
    parent::validateForm($form, $form_state);
  }

  /**
   * Returns a list of plugins on the migration, listed per type.
   *
   * Would be nice to instantiate data parser plugin here but this will cause
   * issues with us needing a real readable source.
   *
   * @return array
   *   A list of plugins, listed per type.
   *
   * @todo move to a service class.
   */
  protected function getPlugins() {
    $plugins = array_fill_keys(['fetcher', 'parser', 'destination'], NULL);

    // Convert migration entity to array in order to create a dummy migration
    // plugin instance. This dummy is needed in order to instantiate a
    // destination plugin. We cannot call toArray() on the migration entity,
    // because that may only be called on saved entities. And we really need an
    // array representation for unsaved entities too.
    $keys = [
      'source',
      'process',
      'destination',
      'migration_tags',
      'migration_dependencies',
    ];
    $migration_data = [];
    foreach ($keys as $key) {
      $migration_data[$key] = $this->entity->get($key);
    }

    // And instantiate the migration plugin.
    $migration_plugin = $this->migrationPluginManager->createStubMigration($migration_data);

    // Fetcher.
    $source = $this->entity->get('source');
    if (isset($source['data_fetcher_plugin'])) {
      $plugins['fetcher'] = $this->fetcherPlugins->createInstance($source['data_fetcher_plugin']);
    }

    // Destination.
    $destination = $this->entity->get('destination');
    if (isset($destination['plugin'])) {
      $plugins['destination'] = $migration_plugin->getDestinationPlugin();
    }

    return $plugins;
  }

  /**
   * Returns the plugin forms for this feed type.
   *
   * @return \Drupal\feeds_migrate\Plugin\Type\ExternalPluginFormInterface[]
   *   A list of form objects, keyed by plugin id.
   */
  protected function getPluginForms() {
    $plugins = [];
    foreach ($this->getPlugins() as $type => $plugin) {
      if ($plugin && $this->formFactory->hasForm($plugin, 'configuration')) {
        $plugins[$type] = $this->formFactory->createInstance($plugin, 'configuration');
      }
    }

    return $plugins;
  }

  /**
   * Returns list of possible options for a certain plugin type.
   *
   * @param string $plugin_type
   *   The plugin type to return possible values for.
   *
   * @return array
   *   A list of choosable plugins.
   *
   * @todo move to a service class.
   */
  protected function getPluginOptionsList($plugin_type) {
    switch ($plugin_type) {
      case 'fetcher':
        $manager = \Drupal::service("plugin.manager.feeds_migrate.data_fetcher_form");
        break;

      case 'parser':
        $manager = \Drupal::service("plugin.manager.feeds_migrate.data_parser_form");
        break;

      case 'destination':
        $manager = \Drupal::service("plugin.manager.migrate.$plugin_type");
        break;

      default:
        return [];
    }

    $options = [];
    foreach ($manager->getDefinitions() as $id => $definition) {
      $options[$id] = isset($definition['label']) ? $definition['label'] : $id;
    }

    return $options;
  }

  /**
   * Sends an ajax response.
   */
  public function ajaxCallback(array $form, FormStateInterface $form_state) {
    $renderer = \Drupal::service('renderer');
    $type = $form_state->getTriggeringElement()['#plugin_type'];

    $response = new AjaxResponse();

    // Set URL hash so that the correct settings tab is open.
    // @todo activate once plugin configuration form is implemented.
    /*
    if (isset($form[$type . '_configuration']['#id'])) {
      $hash = ltrim($form[$type . '_configuration']['#id'], '#');
      $response->addCommand(new SetHashCommand($hash));
    }
    */

    // Update the forms.
    $plugin_settings = $renderer->renderRoot($form['plugin_settings']);
    $advanced_settings = $renderer->renderRoot($form[$type . '_wrapper']['advanced']);
    $response->addCommand(new ReplaceCommand('#feeds-ajax-form-wrapper', $plugin_settings));
    $response->addCommand(new ReplaceCommand('#feeds-plugin-' . $type . '-advanced', $advanced_settings));

    // Add attachments.
    $attachments = NestedArray::mergeDeep($form['plugin_settings']['#attached'], $form[$type . '_wrapper']['advanced']['#attached']);
    $response->setAttachments($attachments);

    // Display status messages.
    $status_messages = ['#type' => 'status_messages'];
    $output = $renderer->renderRoot($status_messages);
    if (!empty($output)) {
      $response->addCommand(new HtmlCommand('.region-messages', $output));
    }

    return $response;
  }

  /**
   * Get the data fetcher.
   *
   * @param array $form
   *   Complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  protected function getDataFetcherStep(array &$form, FormStateInterface $form_state) {
    // :TODO: Decide whether this old code is handy for later.
    /*
    $form['data'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Start with some data'),
      '#tree' => TRUE,
    ];

    foreach ($this->fetcherPlugins->getDefinitions() as $plugin_definition) {
      $form['data'][$plugin_definition['id']] = [
        '#type' => 'submit',
        '#value' => $plugin_definition['title'],
        '#name' => $plugin_definition['id'],
      ];
    }
    */
  }

  /**
   * Start with some data.
   *
   * @param array $form
   *   Complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  protected function inputDataStep(array &$form, FormStateInterface $form_state) {
    $plugin_id = $form_state->getTriggeringElement()['#name'];

    try {
      /** @var \Drupal\feeds_migrate\DataFetcherFormInterface $fether_plugin */
      $fether_plugin = $this->fetcherPlugins->createInstance($plugin_id);
    }
    catch (\Exception $e) {
      $form_state->setRebuild();
      return;
    }
    $element = $fether_plugin->buildForm($form, $form_state);
    $form[$plugin_id] = $element;
    $form['fetcher_plugin'] = [
      '#type' => 'hidden',
      '#value' => $plugin_id,
    ];

    $form['actions']['_skip'] = [
      '#type' => 'submit',
      '#value' => $this->t('Skip'),
      '#name' => '_skip',
    ];
  }

  /**
   * Build the form for the user to choose the entity type to import into.
   *
   * @param array $form
   *   Complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  protected function chooseEntityTypeStep(array &$form, FormStateInterface $form_state) {
    $parser_plugin_id = $this->entity->source['data_parser_plugin'] ?: NULL;
    if ($parser_plugin_id) {
      /** @var \Drupal\feeds_migrate\DataParserFormBase $parser_plugin */
      $parser_plugin = $this->parserManager->createInstance($parser_plugin_id);
      $form['parser'][$parser_plugin_id] = $parser_plugin->buildConfigurationForm($form, $form_state);
    }

    $form['ids'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unique Selector Path'),
      '#default_value' => $this->getUniqueSelector($this->entity),
      '#required' => TRUE,
    ];

    $entity_types = [];
    /** @var \Drupal\Core\Entity\EntityTypeInterface $definition */
    foreach ($this->entityTypeManager->getDefinitions() as $entity_id => $definition) {
      if ($definition->entityClassImplements('Drupal\Core\Entity\FieldableEntityInterface')) {
        $entity_types[$entity_id] = $definition->getLabel();
      }
    }

    $chosen_type = $form_state->getValue('entity_type') ?: $this->getEntityTypeFromMigration();

    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity Type'),
      '#options' => $entity_types,
      '#default_value' => $chosen_type,
      '#empty_option' => $this->t('- Choose -'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::entityTypeChosenAjax',
        'wrapper' => 'entity-bundle',
      ],
    ];

    $form['entity_bundle'] = [
      '#prefix' => '<div id="entity-bundle">',
      '#suffix' => '</div>',
    ];
    $bundle = $this->getEntityBunddleFromMigration();

    if ($chosen_type) {
      $form['entity_bundle']['#type'] = 'select';
      $form['entity_bundle']['#title'] = $this->t('Entity Bundle');
      $form['entity_bundle']['#required'] = TRUE;
      $form['entity_bundle']['#default_value'] = $bundle;
      foreach ($this->bundleManager->getBundleInfo($chosen_type) as $id => $bundle) {
        $form['entity_bundle']['#options'][$id] = $bundle['label'];
      }
    }
  }

  /**
   * Get the unique value selector path.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Migration entity.
   *
   * @return string
   *   The selector path.
   */
  protected function getUniqueSelector(EntityInterface $entity) {
    $source = $entity->get('source');
    if (empty($source['ids'])) {
      return NULL;
    }
    $field_name = key($source['ids']);
    foreach ($source['fields'] as $field_selector) {
      if ($field_selector['name'] == $field_name) {
        return $field_selector['selector'];
      }
    }
  }

  /**
   * Ajax callback for entity type selection.
   *
   * @param array $form
   *   Complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   *
   * @return mixed
   *   The entity bundle field.
   */
  public function entityTypeChosenAjax(array $form, FormStateInterface $form_state) {
    return $form['entity_bundle'];
  }

  /**
   * Build mapping form.
   *
   * @param array $form
   *   Complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  protected function mapEntityFieldsStep(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $entity_storage */
    $entity_storage = $this->entityTypeManager->getStorage($this->getEntityTypeFromMigration());
    /** @var \Drupal\Core\Entity\ContentEntityType $entity_type */
    $entity_type = $entity_storage->getEntityType();

    $bundle_fields = $this->fieldManager->getFieldDefinitions($entity_type->id(), $this->getEntityBunddleFromMigration());
    $good_keys = ['published', 'label', 'uid'];
    foreach ($entity_type->get('entity_keys') as $key => $field_name) {
      if (in_array($key, $good_keys)) {
        continue;
      }
      unset($bundle_fields[$field_name]);
    }

    $table = [
      '#type' => 'table',
      '#header' => [
        $this->t('Field'),
        $this->t('Selectors'),
        $this->t('Processing Settings'),
      ],
    ];

    /** @var \Drupal\field\Entity\FieldConfig $field */
    foreach ($bundle_fields as $field_name => $field) {
      $table[$field_name] = $this->buildFieldRow($field, $form, $form_state);
    }

    $form['mapping'] = $table;
  }

  /**
   * Build the table field row.
   *
   * @param FieldDefinitionInterface $field
   *   Field definitino.
   * @param array $form
   *   Current form.
   * @param FormStateInterface $form_state
   *   Current form state.
   *
   * @return array
   *   The built field row.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  protected function buildFieldRow(FieldDefinitionInterface $field, array $form, FormStateInterface $form_state) {
    $field_name = $field->getName();
    $label = $field->getLabel();

    $field_row = [
      'human_name' => [
        'data' => [
          '#plain_text' => $label,
        ],
      ],
    ];

    /** @var \Drupal\feeds_migrate_ui\FeedsMigrateUiFieldInterface $plugin */
    $plugin = $this->fieldProcessorManager->getFieldPlugin($field, $this->entity);
    $field_row['selectors']['data'] = $plugin->buildConfigurationForm($form, $form_state);

    // Base button element for the various plugin settings actions.
    $base_button = [
      '#submit' => ['::multistepSubmit'],
      '#ajax' => [
        'callback' => '::multistepAjax',
        'wrapper' => 'field-display-overview-wrapper',
        'effect' => 'fade',
      ],
      '#field_name' => $field_name,
    ];
    $field_row['settings_edit']['data'] = $base_button;

    $field_row['settings_edit']['data'] += [
      '#type' => 'image_button',
      '#name' => $field_name . '_settings_edit',
      '#src' => 'core/misc/icons/787878/cog.svg',
      '#attributes' => [
        'class' => ['field-plugin-settings-edit'],
        'alt' => $this->t('Edit'),
      ],
      '#op' => 'edit',
      // Do not check errors for the 'Edit' button, but make sure we get
      // the value of the 'plugin type' select.
      '#limit_validation_errors' => [['fields', $field_name, 'type']],
      '#prefix' => '<div class="field-plugin-settings-edit-wrapper">',
      '#suffix' => '</div>',
    ];

    return $field_row;
  }

  /**
   * Find the entity type the migration is importing into.
   *
   * @return string
   *   Machine name of the entity type eg 'node'.
   */
  protected function getEntityTypeFromMigration() {
    if (isset($this->entity->destination['plugin'])) {
      $destination = $this->entity->destination['plugin'];
      if (strpos($destination, ':') !== FALSE) {
        list(, $entity_type) = explode(':', $destination);
        return $entity_type;
      }
    }
  }

  /**
   * The bundle the migration is importing into.
   *
   * @return string
   *   Entity type bundle eg 'article'.
   */
  protected function getEntityBunddleFromMigration() {
    if (!empty($this->entity->source['constants']['bundle'])) {
      return $this->entity->source['constants']['bundle'];
    }
  }

  /**
   * Build unique selection form.
   *
   * @param array $form
   *   Complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  protected function uniqueItemStep(array $form, FormStateInterface $form_state) {
    $form['unique_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unique Identifier'),
      '#description' => $this->t('Specify the unique item for each entry to allow for updates.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    \Drupal::messenger()
      ->addMessage($this->t('Saved migration %label', ['%label' => $this->entity->label()]));
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    $values =& $form_state->getValues();

    // Moved advanced settings to regular settings.
    foreach ($this->getPlugins() as $type => $plugin) {
      if (isset($values[$type . '_wrapper']['advanced'])) {
        if (!isset($values[$type . '_configuration'])) {
          $values[$type . '_configuration'] = [];
        }
        $values[$type . '_configuration'] += $values[$type . '_wrapper']['advanced'];
      }
      unset($values[$type . '_wrapper']);
    }

    // Set ID and label.
    $entity->set('id', $values['id']);
    $entity->set('label', $values['label']);

    // Get source.
    $source = $this->entity->get('source');

    // Set source plugin.
    // @todo Make it so that source plugin is not hard coded.
    $source['plugin'] = 'null';

    // Set fetcher and parser on source.
    $source['data_fetcher_plugin'] = $values['fetcher'];
    $source['data_parser_plugin'] = $values['parser'];

    // Set id_selector and fields.
    // @todo Make it so id_selector is not hard coded.
    $id_selector = '//';
    $source['item_selector'] = '//';
    $source['ids'] = ['guid' => ['type' => 'string']];
    $source['fields']['guid'] = [
      'name' => 'guid',
      'label' => 'guid',
      'selector' => $id_selector,
    ];

    // Write source back to entity.
    $entity->set('source', $source);

    // Set destination.
    $entity->set('destination', ['plugin' => $values['destination']]);

    // Set migration group.
    $entity->set('migration_group', $values['migration_group']);

    // Allow option forms to set values.
    foreach ($this->getPlugins() as $type => $plugin) {
      $plugin_state = $this->createSubFormState($type . '_configuration', $form_state);
      if ($plugin && isset($form[$type . '_wrapper']['advanced']) && $this->formFactory->hasForm($plugin, 'option')) {
        $option_form = $this->formFactory->createInstance($plugin, 'option');
        $option_form->copyFormValuesToEntity($entity, $form[$type . '_wrapper']['advanced'], $plugin_state);
      }
    }

    // @todo allow configuration forms to set values.
  }

  /**
   * Copies top-level form values to entity properties.
   *
   * This should not change existing entity properties that are not being edited
   * by this form.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the current form should operate upon.
   * @param array $form
   *   A nested array of form elements comprising the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  protected function copyFormValuesToEntityStepOne(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    parent::copyFormValuesToEntity($entity, $form, $form_state);
    $source = $entity->get('source') ?: [];
    $source['plugin'] = 'url';
    $source['data_fetcher_plugin'] = $form_state->getTriggeringElement()['#name'];
    $entity->set('source', $source);
  }

  /**
   * Copies top-level form values to entity properties.
   *
   * This should not change existing entity properties that are not being edited
   * by this form.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the current form should operate upon.
   * @param array $form
   *   A nested array of form elements comprising the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function copyFormValuesToEntityStepTwo(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    $source = $entity->get('source') ?: [];
    $fetcher_plugin_id = $source['data_fetcher_plugin'];
    /** @var \Drupal\feeds_migrate\DataFetcherFormInterface $fetcher_plugin */
    $fetcher_plugin = $this->fetcherPlugins->createInstance($fetcher_plugin_id);
    $parser_data = $fetcher_plugin->getParserData($form, $form_state);

    if ($parser_plugin = $this->parserSuggestion->getSuggestedParser($parser_data)) {
      $source = $entity->get('source');
      $source['data_parser_plugin'] = $parser_plugin->getPluginId();
      $source['urls'] = $parser_data;
      $entity->set('source', $source);
    }
  }

  /**
   * Copies top-level form values to entity properties.
   *
   * This should not change existing entity properties that are not being edited
   * by this form.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the current form should operate upon.
   * @param array $form
   *   A nested array of form elements comprising the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  protected function copyFormValuesToEntityStepThree(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    $source = $entity->get('source');
    if (!empty($source['data_parser_plugin'])) {
      /** @var \Drupal\feeds_migrate\DataParserFormInterface $parser_plugin */
      $parser_plugin = $this->parserManager->createInstance($source['data_parser_plugin']);
      $parser_plugin->copyFormValuesToEntity($entity, $form, $form_state);
    }

    if ($entity_type = $form_state->getValue('entity_type')) {
      $entity->set('destination', ['plugin' => 'entity:' . $entity_type]);
    }

    if ($entity_bundle = $form_state->getValue('entity_bundle')) {
      $source = $entity->get('source') ?: [];

      $id_selector = $form_state->getValue('ids');
      $source['ids'] = ['guid' => ['type' => 'string']];

      $source['fields'][] = [
        'name' => 'guid',
        'label' => 'guid',
        'selector' => $id_selector,
      ];

      $source['constants']['bundle'] = $entity_bundle;
      $entity->set('source', $source);

      $process = $entity->get('process') ?: [];
      $bundle_key = $this->getBundleKey();
      $process[$bundle_key] = 'constants/bundle';
      $entity->set('process', $process);
    }

  }

  /**
   * Get the bundle key for the configured entity type on the migration.
   *
   * @return string|null
   *   Bundle Key.
   */
  protected function getBundleKey() {
    try {
      /** @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $entity_storage */
      $entity_storage = $this->entityTypeManager->getStorage($this->getEntityTypeFromMigration());
    }
    catch (\Exception $e) {
      return NULL;
    }
    /** @var \Drupal\Core\Entity\ContentEntityType $entity_type */
    $entity_type = $entity_storage->getEntityType();
    return $entity_type->get('entity_keys')['bundle'] ?: NULL;
  }

  /**
   * Copies top-level form values to entity properties.
   *
   * This should not change existing entity properties that are not being edited
   * by this form.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the current form should operate upon.
   * @param array $form
   *   A nested array of form elements comprising the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function copyFormValuesToEntityStepFour(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->cleanEmptyFieldValues($values);
    $bundle_key = $this->getBundleKey();

    $process = $entity->get('process') ?: [];
    $process = [
      $bundle_key => $process[$bundle_key],
    ];

    $source = $entity->get('source') ?: [];
    $id_name = key($source['ids']);
    $guid_selector = NULL;
    foreach ($source['fields'] as $delta => $field) {
      if ($field['name'] == $id_name) {
        $guid_selector = $field;
        break;
      }
    }
    $source['fields'] = $guid_selector ? [$guid_selector] : [];

    foreach ($values['mapping'] as $field => $field_data) {
      $selectors = $field_data['selectors']['data'];

      if (is_string($selectors)) {
        $source['fields'][] = [
          'name' => $field,
          'label' => $field,
          'selector' => $selectors,
        ];
        $process[$field] = $field;
      }
      else {
        foreach ($selectors as $column => $selector) {
          $source['fields'][] = [
            'name' => "{$field}__$column",
            'label' => "{$field}__$column",
            'selector' => $selector,
          ];

          $process["$field/$column"] = "{$field}__$column";
        }
      }
    }

    $entity->set('process', $process);
    $entity->set('source', $source);
  }

  /**
   * Remove empty values.
   *
   * @param mixed $values
   *   Anything other than an object.
   */
  protected function cleanEmptyFieldValues(&$values) {
    if (!is_array($values)) {
      return;
    }
    foreach ($values as &$value) {
      $this->cleanEmptyFieldValues($value);
    }
    $values = array_filter($values);
  }

  /**
   * Creates a FormStateInterface object for a plugin.
   *
   * @param string|array $key
   *   The form state key.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to copy values from.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   A new form state object.
   *
   * @see FormStateInterface::getValue()
   */
  protected function createSubFormState($key, FormStateInterface $form_state) {
    // There might turn out to be other things that need to be copied and passed
    // into plugins. This works for now.
    return (new FormState())->setValues($form_state->getValue($key, []));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);

    // Redirect the user back to the listing route after the save operation.
    $form_state->setRedirect('entity.migration.list',
    ['migration_group' => $this->entity->get('migration_group')]);
  }

}
