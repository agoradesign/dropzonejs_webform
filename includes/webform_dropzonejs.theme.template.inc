<?php

/**
 * @file
 * Preprocessors and helper functions to make theming easier.
 */

/**
 * Preprocesses "webform_element_dropzonejs"
 */
function template_preprocess_webform_element_dropzonejs(array &$variables) {
  \Drupal::moduleHandler()->loadInclude('webform', 'inc', 'includes/webform.theme.template');
  template_preprocess_webform_element_managed_file($variables);
}
