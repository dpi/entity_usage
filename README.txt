Entity Usage
============

DO NOT USE THIS MODULE IN PRODUCTION YET

This module is a proof-of-concept for a tool to track usage of entities by other entities in drupal.

For the moment only entities referenced in entity_reference fields are tracked.

There is no specific configuration, once enabled, the module will start tracking the relation between entities, getting
updated on all CRUD entity operations.

A basic views integration is provided. To use the tracked information in a view, follow the following steps:
 1) Create a view that has as base table any content entity (Node, User, Etc)
 2) Add a relationship to your view named
    "Information about the usage of this @entity_type"
 3) After adding the relationship, add the field to your view:
    "Usage count"
 4) You will probably want to enable aggregation, to avoid duplicate rows and have the real count sum. To do that, go to
    the "Advanced" section of the view configuration, select "Use aggregation" to "Yes"
 5) Go to the "Usage count" field you added before, open up the "Aggregation settings" form, and select "SUM".

If you are developing you can also check the tracking information recorded by this module at the "entity_usage" table.

You can also use the service
  \Drupal::service('entity_usage.usage')->listUsage($entity);
to get the statistics in code.

Feedback is very welcome in the issue queue.
