<?php

namespace Drupal\s3fs_cors\Element;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Sts\StsClient;
use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\Environment;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\Element\ManagedFile;
use Drupal\Core\Site\Settings;
use Drupal\file\Entity\File;

/**
 * Provides an S3fs Cors File Element.
 *
 * @FormElement("s3fs_cors_file")
 */
class S3fsCorsFile extends ManagedFile {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    $parent = get_parent_class($this);
    return [
      '#input' => TRUE,
      '#process' => [
        [$class, 'processManagedFile'],
      ],
      '#element_validate' => [
        [$class, 'validateManagedFile'],
      ],
      '#pre_render' => [
        [$parent, 'preRenderManagedFile'],
      ],
      '#progress_indicator' => 'throbber',
      '#progress_message' => NULL,
      '#theme' => 'file_managed_file',
      '#theme_wrappers' => ['form_element'],
      '#size' => 22,
      '#multiple' => FALSE,
      '#extended' => FALSE,
      '#attached' => [
        'library' => [
          // 'file/drupal.file',.
          's3fs_cors/cors.file',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    // Find the current value of this field.
    $fids = !empty($input['fids']) ? explode(' ', $input['fids']) : [];
    // ksm($form_state->getValues());
    foreach ($fids as $key => $fid) {
      $fids[$key] = (int) $fid;
    }
    $force_default = FALSE;

    // @FIXME: This can certainly be improved. We have copied code from core file module
    // Process any input and save new uploads.
    if ($input !== FALSE) {
      $input['fids'] = $fids;
      $return = $input;
      // Check for #filefield_value_callback values.
      // Because FAPI does not allow multiple #value_callback values like it
      // does for #element_validate and #process, this fills the missing
      // functionality to allow File fields to be extended through FAPI.
      if (isset($element['#file_value_callbacks'])) {
        foreach ($element['#file_value_callbacks'] as $callback) {
          $callback($element, $input, $form_state);
        }
      }

      // Load files if the FIDs have changed to confirm they exist.
      if (!empty($input['fids'])) {
        $fids = [];
        foreach ($input['fids'] as $fid) {
          if ($file = File::load($fid)) {
            $fids[] = $file->id();
            // Temporary files that belong to other users should never be
            // allowed.
            if ($file->isTemporary()) {
              if ($file->getOwnerId() != \Drupal::currentUser()->id()) {
                $force_default = TRUE;
                break;
              }
              // Since file ownership can't be determined for anonymous users,
              // they are not allowed to reuse temporary files at all. But
              // they do need to be able to reuse their own files from earlier
              // submissions of the same form, so to allow that, check for the
              // token added by S3fsCorsFile::processManagedFile().
              elseif (\Drupal::currentUser()->isAnonymous()) {
                $token = NestedArray::getValue($form_state->getUserInput(), array_merge($element['#parents'], ['file_' . $file->id(), 'fid_token']));
                if ($token !== Crypt::hmacBase64('file-' . $file->id(), \Drupal::service('private_key')->get() . Settings::getHashSalt())) {
                  $force_default = TRUE;
                  break;
                }
              }
            }
          }
        }
        if ($force_default) {
          $fids = [];
        }
      }
    }

    // If there is no input or if the default value was requested above, use the
    // default value.
    if ($input === FALSE || $force_default) {
      if ($element['#extended']) {
        $default_fids = isset($element['#default_value']['fids']) ? $element['#default_value']['fids'] : [];
        $return = isset($element['#default_value']) ? $element['#default_value'] : ['fids' => []];
      }
      else {
        $default_fids = isset($element['#default_value']) ? $element['#default_value'] : [];
        $return = ['fids' => []];
      }

      // Confirm that the file exists when used as a default value.
      if (!empty($default_fids)) {
        $fids = [];
        foreach ($default_fids as $fid) {
          if ($file = File::load($fid)) {
            $fids[] = $file->id();
          }
        }
      }
    }

    $return['fids'] = $fids;
    return $return;
  }

  /**
   * Handle a managed file from a form upload field.
   */
  public static function processManagedFile(&$element, FormStateInterface $form_state, &$complete_form) {
    // Get ManagedFile Process Form Element and Alter it.
    $element = parent::processManagedFile($element, $form_state, $complete_form);
    // Alter Upload - Input File element.
    $element['upload']['#attributes'] = ['class' => ['s3fs-cors-upload']];

    $config = \Drupal::config('s3fs.settings');
    $cors_config = \Drupal::config('s3fs_cors.settings');

    // Create Configurations needed for AWS S3 CORS Upload.
    $acl = $cors_config->get('s3fs_access_type');
    $bucket = $config->get('bucket');

    $upload_parts = explode('://', $element['#upload_location']);
    $s3_key = $upload_parts[1];
    // If a base folder for public or private uri schemes has been defined,
    // prepend it to the $s3 key, else use the same defaults as the s3fs module.
    if (method_exists('\Drupal\Core\StreamWrapper\StreamWrapperManager', 'getScheme')) {
      // Drupal 8.8+, inc. Drupal 9.
      $uri_scheme = StreamWrapperManager::getScheme($element['#upload_location']);
    }
    else {
      // Drupal < 8.8.
      $uri_scheme = \Drupal::service('file_system')->uriScheme($element['#upload_location']);
    }
    if ($uri_scheme == 'public' || $uri_scheme == 'private') {
      $config_key = $uri_scheme . '_folder';
      $folder_key = empty($config->get($config_key)) ? 's3fs-' . $uri_scheme : $config->get($config_key);
      $s3_key = $folder_key . '/' . $s3_key;
    }
    // If a root folder has been set, prepend it to the $s3_key at this time.
    if (!empty($config->get('root_folder'))) {
      $s3_key = $config->get('root_folder') . '/' . $s3_key;
    }
    // Drop the "s3://" stream prefix as it is misleading.
    $element['#upload_location'] = $bucket . '::' . $s3_key;

    $datenow = new DrupalDateTime('now');
    $datenow->setTimezone(new \DateTimeZone('UTC'));
    $expiration = clone $datenow;
    $expiration->add(new \DateInterval('PT6H'));
    $region = $config->get('region') ?: Settings::get('s3fs.region', '');

    // Use the memoized default credential provider.
    $provider = CredentialProvider::defaultProvider();

    // Chain a provider with the local values if they exist.
    $credentials = NULL;
    $access_key = $config->get('access_key') ?: Settings::get('s3fs.access_key', '');
    $secret_key = $config->get('secret_key') ?: Settings::get('s3fs.secret_key', '');
    if ($access_key && $secret_key) {
      $credentials = new Credentials($access_key, $secret_key);
      $provider = CredentialProvider::chain(CredentialProvider::fromCredentials($credentials), $provider);
    }

    // Create an S3 client using the provider. This should use the Instance
    // Profile provider if this code is running in an AWS instance.
    /** @var \Drupal\s3fs\S3fsServiceInterface $s3fs */
    $s3fs = \Drupal::service('s3fs');
    $client = $s3fs->getAmazonS3Client($config->get());
    $creds = $client->getCredentials()->wait();
    $access_key = $creds->getAccessKeyId();
    $secret_key = $creds->getSecretKey();
    $session_token = $creds->getSecurityToken();
    // If not running on an AWS instance the S3 Client doesn't have session
    // token, so create an STS client and use the session token from that.
    if (empty($credentials) && empty($session_token)) {
      $sts_policy_resource = $cors_config->get('s3fs_sts_policy_resource') ?: '';
      $sts = new StsClient([
        'region' => $region,
        'version' => 'latest',
      ]);

      $sessionToken = $sts->getFederationToken([
        'Name' => 'User1',
        'DurationSeconds' => '3600',
        'Policy' => json_encode([
          'Statement' => [
            'Sid' => 'drupals3fscorsid' . time(),
            'Action' => [
              "s3:PutObject",
              "s3:GetObjectAcl",
              "s3:GetObject",
              "s3:DeleteObjectVersion",
              "s3:PutObjectVersionAcl",
              "s3:GetObjectVersionAcl",
              "s3:DeleteObject",
              "s3:PutObjectAcl",
              "s3:GetObjectVersion",
            ],
            'Effect' => 'Allow',
            'Resource' => $sts_policy_resource,
          ],
        ]),
      ]);
      $access_key = $sessionToken['Credentials']['AccessKeyId'];
      $secret_key = $sessionToken['Credentials']['SecretAccessKey'];
      $session_token = $sessionToken['Credentials']['SessionToken'];
    }

    // Specify the S3 upload policy.
    $policy = [
      'expiration' => $expiration->format('Y-m-d\TH:i:s\Z'),
      'conditions' => [
        ['bucket' => $bucket],
        ['acl' => $acl],
        ['starts-with', '$key', $s3_key],
        ['starts-with', '$Content-Type', ''],
        ['success_action_status' => '201'],
        ['x-amz-algorithm' => 'AWS4-HMAC-SHA256'],
        ['x-amz-credential' => $access_key . '/' . $datenow->format('Ymd') . '/' . $region . '/s3/aws4_request'],
        ['x-amz-date' => $datenow->format('Ymd\THis\Z')],
        ['x-amz-expires' => '21600'],
      ],
    ];
    // Include the session token if it exists.
    if ($session_token) {
      $policy['conditions'][] = ['x-amz-security-token' => $session_token];
    }

    // Generate a string to sign from the policy.
    $base64Policy = base64_encode(json_encode($policy));
    // Generate the v4 signing key.
    $date_key = hash_hmac('sha256', $datenow->format('Ymd'), 'AWS4' . $secret_key, TRUE);
    $region_key = hash_hmac('sha256', $region, $date_key, TRUE);
    $service_key = hash_hmac('sha256', 's3', $region_key, TRUE);
    $signing_key = hash_hmac('sha256', 'aws4_request', $service_key, TRUE);
    $signature = hash_hmac('sha256', $base64Policy, $signing_key);

    $js_settings = [];
    // Add the extension list to the page as JavaScript settings.
    if (isset($element['#upload_validators']['file_validate_extensions'][0])) {
      $js_settings['extension_list'] = implode(',', array_filter(explode(' ', $element['#upload_validators']['file_validate_extensions'][0])));
    }

    if (isset($element['#max_filesize'])) {
      $max_filesize = Bytes::toInt($element['#max_filesize']);
    }
    elseif (isset($element['#upload_validators']['file_validate_size'])) {
      $max_filesize = $element['#upload_validators']['file_validate_size'][0];
    }
    else {
      $max_filesize = Environment::getUploadMaxSize();
    }

    $js_settings['max_size'] = $max_filesize;
    $js_settings['upload_location'] = $element['#upload_location'];
    $js_settings['cors_form_data'] = [
      'acl' => $acl,
      'success_action_status' => 201,
      'x-amz-algorithm' => 'AWS4-HMAC-SHA256',
      'x-amz-credential' => $access_key . '/' . $datenow->format('Ymd') . '/' . $region . '/s3/aws4_request',
      'x-amz-date' => $datenow->format('Ymd\THis\Z'),
      'policy' => $base64Policy,
      'x-amz-signature' => $signature,
      'x-amz-expires' => '21600',
    ];
    // Include the session token if it exists.
    if ($session_token) {
      $js_settings['cors_form_data']['x-amz-security-token'] = $session_token;
    }

    $element_parents = $element['#array_parents'];
    // Remove the delta value from element parents if multiple files allowed.
    if ($element['#multiple']) {
      array_pop($element_parents);
    }
    // Pass the element parents through to the javascript function.
    $js_settings['element_parents'] = implode('/', $element_parents);

    // Use s3fs settings for constructing the form action.
    $hostname = $config->get('use_customhost') ? $config->get('hostname') : 's3.' . $region . '.amazonaws.com';
    $endpoint = $config->get('use_path_style_endpoint') ? $hostname . '/' . $bucket : $bucket . '.' . $hostname;
    $js_settings['cors_form_action'] = $cors_config->get('s3fs_https') . '://' . $endpoint . '/';

    $field_name = $element['#field_name'];
    if (!empty($element['#field_parents'])) {
      $field_name = sprintf('%s_%s', implode('_', $element['#field_parents']), $field_name);
    }
    $element['upload']['#attached']['drupalSettings']['s3fs_cors'][$field_name] = $js_settings;

    $item = $element['#value'];
    $item['fids'] = $element['fids']['#value'];
    // Add the description field if enabled.
    if ($element['#description_field'] && $item['fids']) {
      $config = \Drupal::config('file.settings');
      $element['description'] = [
        '#type' => $config->get('description.type'),
        '#title' => t('Description'),
        '#value' => isset($item['description']) ? $item['description'] : '',
        '#maxlength' => $config->get('description.length'),
        '#description' => t('The description may be used as the label of the link to the file.'),
      ];
    }

    return $element;
  }

  /**
   * Render API callback: Custom validation for the managed_file element.
   *
   * Copied from \Drupal\file\Element\ManagedFile.
   * Currently identical.
   */
  public static function validateManagedFile(&$element, FormStateInterface $form_state, &$complete_form) {
    $clicked_button = end($form_state->getTriggeringElement()['#parents']);
    if ($clicked_button != 'remove_button' && !empty($element['fids']['#value'])) {
      $fids = $element['fids']['#value'];
      foreach ($fids as $fid) {
        if ($file = File::load($fid)) {
          // If referencing an existing file, only allow if there are existing
          // references. This prevents unmanaged files from being deleted if
          // this item were to be deleted. When files that are no longer in use
          // are automatically marked as temporary (now disabled by default),
          // it is not safe to reference a permanent file without usage. Adding
          // a usage and then later on removing it again would delete the file,
          // but it is unknown if and where it is currently referenced. However,
          // when files are not marked temporary (and then removed)
          // automatically, it is safe to add and remove usages, as it would
          // simply return to the current state.
          // @see https://www.drupal.org/node/2891902
          if ($file->isPermanent() && \Drupal::config('file.settings')->get('make_unused_managed_files_temporary')) {
            $references = static::fileUsage()->listUsage($file);
            if (empty($references)) {
              // We expect the field name placeholder value to be wrapped in t()
              // here, so it won't be escaped again as it's already marked safe.
              $form_state->setError($element, t('The file used in the @name field may not be referenced.', ['@name' => $element['#title']]));
            }
          }
        }
        else {
          // We expect the field name placeholder value to be wrapped in t()
          // here, so it won't be escaped again as it's already marked safe.
          $form_state->setError($element, t('The file referenced by the @name field does not exist.', ['@name' => $element['#title']]));
        }
      }
    }

    // Check required property based on the FID.
    if ($element['#required'] && empty($element['fids']['#value']) && !in_array($clicked_button, ['upload_button', 'remove_button'])) {
      // We expect the field name placeholder value to be wrapped in t()
      // here, so it won't be escaped again as it's already marked safe.
      $form_state->setError($element, t('@name field is required.', ['@name' => $element['#title']]));
    }

    // Consolidate the array value of this field to array of FIDs.
    if (!$element['#extended']) {
      $form_state->setValueForElement($element, $element['fids']['#value']);
    }
  }

}
