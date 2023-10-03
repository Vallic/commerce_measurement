<?php

namespace Drupal\commerce_measurement\Plugin\Commerce\Condition;

use Drupal\Core\Entity\EntityInterface;

/**
 * Provides the total discounted product quantity condition.
 *
 * @CommerceCondition(
 *   id = "order_item_measurement",
 *   label = @Translation("Product variation measurements"),
 *   category = @Translation("Products"),
 *   entity_type = "commerce_order_item",
 * )
 */
class OrderItemMeasurement extends MeasurementBaseCondition {

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = $entity;
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $purchased_entity */
    $purchased_entity = $order_item->getPurchasedEntity();
    $configuration = $this->configuration['measurements'];
    if (empty($configuration) || !$purchased_entity) {
      return FALSE;
    }

    foreach ($configuration as $values) {
      $field_name = $values['field_name'];
      if (!$purchased_entity->hasField($field_name) || $purchased_entity->get($field_name)->isEmpty()) {
        return FALSE;
      }

      $order_item_measurement = $purchased_entity->get($field_name)->first()->toMeasurement();

      if (!$this->evaluateMeasurement($order_item_measurement, $values)) {
        return FALSE;
      }

    }

    return TRUE;
  }

}
