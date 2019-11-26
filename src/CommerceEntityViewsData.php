<?php

namespace Drupal\commerce;

use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\views\EntityViewsData;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides improvements to core's generic views integration for entities.
 */
class CommerceEntityViewsData extends EntityViewsData {

  use EntityManagerBridgeTrait;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new CommerceEntityViewsData object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type to provide views integration for.
   * @param \Drupal\Core\Entity\Sql\SqlEntityStorageInterface $storage
   *   The storage handler used for this entity type.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation_manager
   *   The translation manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeInterface $entity_type, SqlEntityStorageInterface $storage, EntityManagerInterface $entity_manager, ModuleHandlerInterface $module_handler, TranslationInterface $translation_manager, EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($entity_type, $storage, $entity_manager, $module_handler, $translation_manager);

    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('entity.manager'),
      $container->get('module_handler'),
      $container->get('string_translation'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();
    // Workaround for core issue #3004300.
    if ($this->entityType->isRevisionable()) {
      $revision_table = $this->entityType->getRevisionTable() ?: $this->entityType->id() . '_revision';
      $data[$revision_table]['table']['entity revision'] = TRUE;
    }
    // Add missing reverse relationships. Workaround for core issue #2706431.
    $base_fields = $this->entityFieldManager->getBaseFieldDefinitions($this->entityType->id());
    $entity_reference_fields = array_filter($base_fields, function (BaseFieldDefinition $field) {
      return $field->getType() == 'entity_reference';
    });
    if (in_array($this->entityType->id(), ['commerce_order', 'commerce_product'])) {
      // Product variations and order items have reference fields pointing
      // to the parent entity, no need for a reverse relationship.
      unset($entity_reference_fields['variations']);
      unset($entity_reference_fields['order_items']);
    }
    $this->addReverseRelationships($data, $entity_reference_fields);

    return $data;
  }

  /**
   * Corrects the views data for commerce_price base fields.
   *
   * @param string $table
   *   The table name.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $views_field
   *   The views field data.
   * @param string $field_column_name
   *   The field column being processed.
   */
  protected function processViewsDataForCommercePrice($table, FieldDefinitionInterface $field_definition, array &$views_field, $field_column_name) {
    if ($field_column_name == 'number') {
      $views_field['filter']['id'] = 'numeric';
    }
  }

  /**
   * Adds reverse relationships for the base entity reference fields.
   *
   * @param array $data
   *   The views data.
   * @param \Drupal\Core\Field\BaseFieldDefinition[] $fields
   *   The entity reference fields.
   */
  protected function addReverseRelationships(array &$data, array $fields) {
    $entity_type_id = $this->entityType->id();
    $base_table = $this->getViewsTableForEntityType($this->entityType);
    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $table_mapping */
    $table_mapping = $this->storage->getTableMapping();
    assert($this->entityType instanceof ContentEntityType);
    $revision_metadata_field_names = array_flip($this->entityType->getRevisionMetadataKeys());

    foreach ($fields as $field) {
      $target_entity_type_id = $field->getSettings()['target_type'];
      $target_entity_type = $this->entityTypeManager->getDefinition($target_entity_type_id);
      if (!($target_entity_type instanceof ContentEntityType)) {
        continue;
      }
      $target_table = $this->getViewsTableForEntityType($target_entity_type);
      $field_name = $field->getName();
      $field_storage = $field->getFieldStorageDefinition();

      $args = [
        '@label' => $target_entity_type->getLowercaseLabel(),
        '@entity' => $this->entityType->getLabel(),
        '@field_name' => $field_name,
      ];
      $pseudo_field_name = 'reverse__' . $entity_type_id . '__' . $field_name;
      $relationship_data = [
        'label' => $this->entityType->getLabel(),
        'group' => $target_entity_type->getLabel(),
        'entity_type' => $entity_type_id,
      ];
      if ($table_mapping->requiresDedicatedTableStorage($field_storage)) {
        $data[$target_table][$pseudo_field_name]['relationship'] = [
          'id' => 'entity_reverse',
          'title' => $this->t('@entity using @field_name', $args),
          'help' => $this->t('Relate each @entity with a @field_name field set to the @label.', $args),
          'base' => $base_table,
          'base field' => $this->entityType->getKey('id'),
          'field_name' => $field_name,
          'field table' => $table_mapping->getFieldTableName($field_name),
          'field field' => $table_mapping->getFieldColumnName($field_storage, 'target_id'),
        ] + $relationship_data;
      }
      elseif (isset($revision_metadata_field_names[$field_name])) {
        // Revision metadata fields exist only on the revision table, so the
        // relationship has to be to that rather than to the base table.
        $revision_table = $this->entityType->getRevisionTable() ?: $this->entityType->id() . '_revision';

        $data[$target_table][$pseudo_field_name]['relationship'] = [
          'id' => 'standard',
          'title' => $this->t('@entity revision using @field_name', $args),
          'help' => $this->t('Relate each @entity revision with a @field_name field set to the @label.', $args),
          'base' => $revision_table,
          'base field' => $table_mapping->getFieldColumnName($field_storage, 'target_id'),
          'relationship field' => $target_entity_type->getKey('id'),
        ] + $relationship_data;
      }
      else {
        // The data is on the base table.
        $data[$target_table][$pseudo_field_name]['relationship'] = [
          'id' => 'standard',
          'title' => $this->t('@entity using @field_name', $args),
          'help' => $this->t('Relate each @entity with a @field_name field set to the @label.', $args),
          'base' => $base_table,
          'base field' => $table_mapping->getFieldColumnName($field_storage, 'target_id'),
          'relationship field' => $target_entity_type->getKey('id'),
        ] + $relationship_data;
      }
    }
  }

}
