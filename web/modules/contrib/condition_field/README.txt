CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Recommended Modules
 * Installation
 * Configuration
 * Maintainers


INTRODUCTION
------------

Condition field allows the user to add a visibility conditions field to any
entity. These conditions can be used for visibility or other purposes.


REQUIREMENTS
------------

Requires the following modules:

 * Condition Field (https://drupal.org/project/condition_field)


RECOMMENDED MODULES
-------------------


INSTALLATION
------------

Install the Condition Field module as you would normally install a contributed Drupal module.
Visit https://www.drupal.org/node/1897420 for further information.


CONFIGURATION
-------------

- Enable the module
- Add a Conditions field to an entity
- Use a piece of custom code in a hook to evaluate the conditions

Example:

function hook_entity_view(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, $view_mode) {
  if ($entity->get('CONDITION_FIELDNAME')->isEmpty()) {
    return TRUE;
  }

  $conditions = [];
  // Single value field.
  $conditions_config = $entity->get('CONDITION_FIELDNAME')->getValue()[0]['conditions'];

  $manager = \Drupal::service('plugin.manager.condition');
  foreach ($conditions_config as $condition_id => $values) {
    /** @var \Drupal\Core\Condition\ConditionInterface $condition */
    $conditions[] = $manager->createInstance($condition_id, $values);
  }

  $isVisible = ConditionAccessResolver::checkAccess($conditions, 'or');

  if (!$isVisible) {
    $build = [];
  }
}


MAINTAINERS
-----------
