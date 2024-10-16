<?php

namespace Drupal\commerce_measurement\Plugin\Commerce\Condition;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\physical\MeasurementType;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Abstract measurement condition class.
 */
abstract class MeasurementBaseCondition extends ConditionBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The commerce product variation type storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $variationTypeStorage;

  /**
   * Array of fields.
   *
   * @var array
   */
  protected $matchedFields = [];

  /**
   * Constructs a new MeasurementBaseCondition object.
   *
   * @param array $configuration
   *   The plugin configuration, i.e. an array with configuration values keyed
   *   by configuration option name.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->variationTypeStorage = $entity_type_manager->getStorage('commerce_product_variation_type');
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'measurements' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $wrapper_id = Html::getUniqueId($this->pluginId . '-condition-ajax-wrapper');
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';

    // Initialize the measurements form.
    if (!$form_state->get('measurements_items_init')) {
      $measurements_items = $this->configuration['measurements'] ?: [];
      $form_state->set('measurements', $measurements_items);
      $form_state->set('measurements_items_init', TRUE);
    }
    $class = get_class($this);

    $fields = $this->getFields();

    if (empty($fields)) {
      $form['empty'] = [
        '#type' => 'item',
        '#title' => $this->t('There are no physical measurements field available'),
        '#disabled' => TRUE,
      ];
      return $form;
    }

    $form['measurements']['items'] = [
      '#type' => 'table',
      '#header' => [
        t('Measurement'),
        t('Field name'),
        t('Operator'),
        t('Value'),
        t('Operations'),
      ],
      '#input' => TRUE,
      '#required' => TRUE,
    ];

    foreach ($form_state->get('measurements') as $index => $value) {
      $measurement_form = &$form['measurements']['items'][$index];
      $field_machine_name = $value['field_name'];
      $measurement_type = $fields[$field_machine_name]['measurement_type'] ?? NULL;
      $field_name = $fields[$field_machine_name]['field_name'] ?? $field_machine_name;

      if (!$measurement_type) {
        throw new \InvalidArgumentException('Something went wrong with mapping of measurement fields and types');
      }
      $measurement_form['measurement_type'] = [
        '#type' => 'hidden',
        '#value' => $measurement_type,
        '#required' => TRUE,
        '#suffix' => $measurement_type,
      ];

      $measurement_form['field_name'] = [
        '#type' => 'hidden',
        '#value' => $field_machine_name,
        '#required' => TRUE,
        '#suffix' => $field_name,
      ];
      $measurement_form['operator'] = [
        '#type' => 'select',
        '#title' => $this->t('Operator'),
        '#options' => $this->getComparisonOperators(),
        '#default_value' => $value['operator'] ?? NULL,
        '#required' => TRUE,
      ];
      $measurement_form['value'] = [
        '#type' => 'physical_measurement',
        '#measurement_type' => $fields[$field_machine_name]['measurement_type'],
        '#title' => $field_name,
        '#default_value' => $value['value'] ?? NULL,
        '#required' => TRUE,
        '#title_display' => 'invisible',
      ];

      $measurement_form['remove'] = [
        '#type' => 'submit',
        '#name' => 'remove_measurement',
        '#value' => t('Remove'),
        '#limit_validation_errors' => [],
        '#plugin_id' => $this->pluginId,
        '#submit' => [[get_called_class(), 'changeMeasurements']],
        '#measurement_index' => $index,
        '#ajax' => [
          'callback' => [get_called_class(), 'ajaxCallback'],
          'wrapper' => $wrapper_id,
        ],
      ];
      // If field is added, remove it from dropdown.
      unset($fields[$field_machine_name]);
    }

    $select_options = [];
    foreach ($fields as $key => $field) {
      $select_options[$key] = $field['field_name'] . ' (' . $field['measurement_type'] . ')';
    }

    $form['measurements']['actions']['select'] = [
      '#type' => 'select',
      '#options' => $select_options,
      '#name' => 'select_measurement',
      '#empty_option' => t('- Select -'),
      '#empty_value' => '',
      '#limit_validation_errors' => [],
      '#prefix' => $this->t('If you combine multiple measurement fields, operator between these measurements fields is always AND'),
    ];

    $form['measurements']['actions']['add'] = [
      '#type' => 'submit',
      '#name' => 'add_measurement',
      '#value' => t('Add measurement'),
      '#submit' => [[$class, 'changeMeasurements']],
      '#plugin_id' => $this->pluginId,
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [get_called_class(), 'ajaxCallback'],
        'wrapper' => $wrapper_id,
      ],

    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);

    // Clear actions array and cleanups remove actions.
    unset($values['actions']);
    $measurements = $values['measurements']['items'];
    foreach ($measurements as $key => $value) {
      unset($measurements[$key]['remove']);
    }

    if ($measurements) {
      $this->configuration['measurements'] = $measurements;
    }
  }

  /**
   * Ajax callback.
   */
  public static function ajaxCallback(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = $triggering_element['#array_parents'];
    $form_index = array_search($triggering_element['#plugin_id'], $parents, TRUE);
    $parents = array_splice($parents, 0, $form_index + 2);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * Submit callback for adding measurement fields.
   */
  public static function changeMeasurements(array $form, FormStateInterface $form_state) {
    $measurements_items = $form_state->get('measurements');
    $triggering_element = $form_state->getTriggeringElement();
    if (isset($triggering_element['#measurement_index'])) {
      unset($measurements_items[$triggering_element['#measurement_index']]);
    }
    else {
      $user_input = $form_state->getUserInput();
      $select = $user_input['select_measurement'] ?? NULL;
      $measurements_items = array_merge($measurements_items, [['field_name' => $select]]);
    }
    $form_state->set('measurements', $measurements_items);
    $form_state->setRebuild();
  }

  /**
   * Evaluation per single field from condition.
   *
   * @param \Drupal\physical\Measurement $order_item_measurement
   *   The order item measurement.
   * @param array $values
   *   The array of values from single condition.
   *
   * @return bool
   *   Return true if condition pass.
   */
  protected function evaluateMeasurement($order_item_measurement, $values) {
    $measurement_type = $values['measurement_type'];
    $condition_unit = $values['value']['unit'];
    // Convert order item measurement to match that one in condition.
    $order_item_measurement = $order_item_measurement->convert($condition_unit);

    // Convert values from conditions to corresponding measurement class.
    $condition_measurement = $this->toMeasurement($measurement_type, $values['value']);
    return match ($values['operator']) {
      '>=' => $order_item_measurement->greaterThanOrEqual($condition_measurement),
      '>' => $order_item_measurement->greaterThan($condition_measurement),
      '<=' => $order_item_measurement->lessThanOrEqual($condition_measurement),
      '<' => $order_item_measurement->lessThan($condition_measurement),
      '==' => $order_item_measurement->equals($condition_measurement),
      default => throw new \InvalidArgumentException("Invalid operator {$this->configuration['operator']}"),
    };
  }

  /**
   * Gets the Measurement value object for the current field item.
   *
   * @return \Drupal\physical\Measurement
   *   A subclass of Measurement (Length, Volume, etc).
   */
  protected function toMeasurement($measurement_type, $value) {
    $class = MeasurementType::getClass($measurement_type);
    return new $class($value['number'], $value['unit']);
  }

  /**
   * Return list of eligible fields.
   *
   * @return array
   *   The formatted array of eligible fields.
   */
  protected function getFields() {
    if (!$this->matchedFields) {
      $matched_fields = [];
      $variation_types = $this->variationTypeStorage->loadMultiple();
      foreach ($variation_types as $variation_type) {
        $fields = $this->entityFieldManager->getFieldDefinitions('commerce_product_variation', $variation_type->id());

        foreach ($fields as $field) {
          if ($field->getType() === 'physical_measurement') {
            $matched_fields[$field->getName()] = [
              'measurement_type' => $field->getSetting('measurement_type'),
              'field_name' => $field->getLabel(),
            ];
          }
        }
      }

      $this->matchedFields = $matched_fields;
    }

    return $this->matchedFields;
  }

}
