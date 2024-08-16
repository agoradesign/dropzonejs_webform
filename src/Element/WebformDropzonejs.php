<?php

namespace Drupal\webform_dropzonejs\Element;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dropzonejs\Element\DropzoneJs;
use Drupal\file\Entity\File;
use Drupal\webform\Utility\WebformElementHelper;

/**
 * Provides a webform element for a 'dropzonejs' element.
 *
 * @FormElement("webform_dropzonejs")
 */
class WebformDropzonejs extends DropzoneJs {

  /**
   * A defualut set of valid extensions.
   */
  const DEFAULT_VALID_EXTENSIONS = 'jpg jpeg gif png txt doc xls pdf ppt pps odt ods odp';

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#process' => [[$class, 'processDropzoneJs']],
      '#pre_render' => [[$class, 'preRenderDropzoneJs']],
      '#theme' => 'dropzonejs',
      '#theme_wrappers' => ['form_element'],
      '#tree' => TRUE,
      '#attached' => [
        'library' => ['webform_dropzonejs/integration'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function preRenderDropzoneJs(array $element) {
    // Grab the maximum number of files allowed. This is based on the
    // $element['#multiple'] value:
    // - When this value is not set, only allow one.
    // - When this value equals TRUE, allow unlimited.
    // - Otherwise $element['#multiple'] equals the number they can upload.
    $max_files = 1;
    if (isset($element['#multiple'])) {
      if ($element['#multiple'] === TRUE) {
        $max_files = NULL;
      }
      else {
        $max_files = (int) $element['#multiple'];
      }
    }

    $element['#dropzone_description'] = t('Drop files here to upload them');
    $element['#extensions'] = isset($element['#upload_validators']['file_validate_extensions'][0]) ? $element['#upload_validators']['file_validate_extensions'][0] : '';
    $element['#max_files'] = $max_files;
    $element['#max_filesize'] = !empty($element['#max_filesize']) ? $element['#max_filesize'] . 'M' : '';
    $libraries = $element['#attached']['library'];
    $element = parent::preRenderDropzoneJs($element);
    if ($libraries === $element['#attached']['library']) {
      return $element;
    }
    if (!empty($libraries) && !empty($element['#attached']['library'])) {
      foreach ($element['#attached']['library'] as $key => $library) {
        if ($library === 'dropzonejs/integration') {
          unset($element['#attached']['library'][$key]);
        }
      }
      $element['#attached']['library'] = array_merge($libraries, $element['#attached']['library']);
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function processDropzoneJs(&$element, FormStateInterface $form_state, &$complete_form) {
    $files = [];
    $element_id = $element['#id'];

    // Add already uploaded files to this dropzonejs field.
    if (!empty($element['#default_value'])) {
      // Put together the data to send to the JS.
      foreach ($element['#default_value'] as $fid) {
        if ($file = File::load($fid)) {
          // Is this file an image?
          $is_image = FALSE;
          switch($file->getMimeType()) {
            case 'image/jpeg':
            case 'image/gif':
            case 'image/png':
              $is_image = TRUE;
              break;
          }

          $files[] = [
            'id' => $file->id(),
            'path' => $file->createFileUrl(FALSE),
            'name' => $file->getFilename(),
            'size' => $file->getSize(),
            'accepted' => TRUE,
            'is_image' => $is_image,
          ];
        }
      }
    }

    $libraries = $element['#attached']['library'] ?? [];
    // Call the parent method.
    parent::processDropzoneJs($element, $form_state, $complete_form);
    // Send the uploaded files to a JS variable.
    $element['#attached']['drupalSettings']['webformDropzoneJs'][$element_id]['files'] = $files;

    // Define a variable where the files will be uploaded to make it easier
    // to link to them in the JS.
    $element['#attached']['drupalSettings']['webformDropzoneJs'][$element_id]['file_directory'] = str_replace(
      array('private://', '_sid_'),
      array('/system/files/', $element['#webform_submission']),
      $element['#upload_location']
    );
    // Load our JS so we can tweak dropzoneJS and pre-load data.
    if (!empty($libraries)) {
      $element['#attached']['library'] = $libraries + ($element['#attached']['library'] ?? []);
    }
    $element['#attached']['library'][] = 'webform_dropzonejs/integration';

    // Add validate callback.
    $element += ['#element_validate' => []];
    array_unshift($element['#element_validate'], [get_called_class(), 'validateWebformDropzonejs']);

    return $element;
  }

  /**
   * Webform element validation handler for #type 'webform_dropzonejs'.
   */
  public static function validateWebformDropzonejs(&$element, FormStateInterface $form_state) {
    if ($element['#required'] && empty($element['#value']['uploaded_files'])) {
      WebformElementHelper::setRequiredError($element, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    $return['uploaded_files'] = [];

    if ($input !== FALSE) {
      $user_input = NestedArray::getValue($form_state->getUserInput(), $element['#parents'] + ['uploaded_files']);

      if (!empty($user_input['uploaded_files'])) {
        $file_names = array_filter(explode(';', $user_input['uploaded_files']));
        $tmp_upload_scheme = \Drupal::configFactory()->get('dropzonejs.settings')->get('tmp_upload_scheme');

        foreach ($file_names as $name) {
          // The upload handler appended the txt extension to the file for
          // security reasons. We will remove it in this callback.
          $old_filepath = $tmp_upload_scheme . '://' . $name;

          // The upload handler appended the txt extension to the file for
          // security reasons. Because here we know the acceptable extensions
          // we can remove that extension and sanitize the filename.
          $name = self::fixTmpFilename($name);
          $event = new FileUploadSanitizeNameEvent($name, self::getValidExtensions($element));
          \Drupal::service('event_dispatcher')->dispatch($event);
          $name = $event->getFilename();

          // Potentially we moved the file already, so let's check first whether
          // we still have to move.
          if (file_exists($old_filepath)) {
            // Finaly rename the file and add it to results.
            $new_filepath = $tmp_upload_scheme . '://' . $name;
            /** @var \Drupal\Core\File\FileSystemInterface $file_system */
            $file_system = \Drupal::service('file_system');
            $move_result = $file_system->move($old_filepath, $new_filepath);

            if ($move_result) {
              $return['uploaded_files'][] = [
                'path' => $move_result,
                'filename' => $name,
              ];
            }
            else {
              \Drupal::messenger()->addError(self::t('There was a problem while processing the file named @name', ['@name' => $name]));
            }
          }
        }
      }
      $form_state->setValueForElement($element, $return);
    }
    return $return;
  }
}
