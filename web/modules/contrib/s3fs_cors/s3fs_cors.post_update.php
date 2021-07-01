<?php

/**
 * @file
 * Post update functions.
 */

use Drupal\Core\Site\Settings;
use Drupal\file\Entity\File;

/**
 * Update public and private file paths to correct paths.
 */
function s3fs_cors_post_update_fix_file_paths(&$sandbox) {
  $config = \Drupal::config('s3fs.settings');
  $public_folder = 's3://' . ($config->get('public_folder') ?: 's3fs-public');
  $private_folder = 's3://' . ($config->get('private_folder') ?: 's3fs-private');
  if (!isset($sandbox['progress'])) {
    /** @var \Drupal\Core\Entity\EntityTypeManager $entity_type_manager */
    $entity_type_manager = \Drupal::service('entity_type.manager');
    $storage_handler = $entity_type_manager->getStorage('file');
    $public_ids = $storage_handler
      ->getQuery()
      ->condition('uri', $public_folder, 'STARTS_WITH')
      ->accessCheck(FALSE)
      ->execute();
    $private_ids = $storage_handler
      ->getQuery()
      ->condition('uri', $private_folder, 'STARTS_WITH')
      ->accessCheck(FALSE)
      ->execute();
    $sandbox['ids'] = array_merge($public_ids, $private_ids);
    $sandbox['max'] = count($sandbox['ids']);
    $sandbox['progress'] = 0;
  }
  $ids = array_slice($sandbox['ids'], $sandbox['progress'], Settings::get('entity_update_batch_size', 50));

  /** @var \Drupal\file\Entity\File $file */
  foreach (File::loadMultiple($ids) as $file) {
    $file->setFileUri(str_replace([$public_folder, $private_folder], ['public:/', 'private:/'], $file->getFileUri()));
    $file->save();
    $sandbox['progress']++;
  }

  $sandbox['#finished'] = empty($sandbox['max']) ? 1 : ($sandbox['progress'] / $sandbox['max']);

  return t("Updated the public and private file paths (@progress out of @max paths).", ['@progress' => $sandbox['progress'], '@max' => $sandbox['max']]);
}
