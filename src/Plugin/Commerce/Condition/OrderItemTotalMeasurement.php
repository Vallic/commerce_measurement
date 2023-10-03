<?php

namespace Drupal\commerce_measurement\Plugin\Commerce\Condition;

use Drupal\Core\Entity\EntityInterface;

/**
 * Provides the total discounted product quantity condition.
 *
 * @CommerceCondition(
 *   id = "order_total_measurement",
 *   label = @Translation("Total product measurements"),
 *   category = @Translation("Products"),
 *   entity_type = "commerce_order",
 * )
 */
class OrderItemTotalMeasurement extends MeasurementBaseCondition {

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $entity;

    $order_items = $order->getItems();
    $configuration = $this->configuration['measurements'];
    if (empty($configuration) || !$order_items) {
      return FALSE;
    }

    /** @var \Drupal\physical\Measurement[] $total_measurement */
    $total_measurement = [];
    foreach ($order_items as $order_item) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $purchased_entity */
      $purchased_entity = $order_item->getPurchasedEntity();
      foreach ($configuration as $values) {
        $field_name = $values['field_name'];
        if (!$purchased_entity->hasField($field_name) || $purchased_entity->get($field_name)->isEmpty()) {
          return FALSE;
        }
        $order_item_measurement = $purchased_entity->get($field_name)->first()->toMeasurement()->multiply($order_item->getQuantity());
        if (empty($total_measurement[$field_name])) {
          $total_measurement[$field_name] = [
            'measurement' => $order_item_measurement,
            'values' => $values,
          ];
        }
        else {
          $total_measurement[$field_name]['measurement'] = $total_measurement[$field_name]['measurement']->add($order_item_measurement);
        }
      }
    }

    foreach ($total_measurement as $item) {
      if (!$this->evaluateMeasurement($item['measurement'], $item['values'])) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
