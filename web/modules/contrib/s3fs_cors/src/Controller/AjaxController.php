<?php

namespace Drupal\s3fs_cors\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\Entity\File;
use Drupal\s3fs\S3fsServiceInterface;
use Drupal\s3fs\StreamWrapper\S3fsStream;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Default controller for the s3fs_cors module.
 */
class AjaxController extends ControllerBase {

  /**
   * S3 Client Interface.
   *
   * @var \Aws\S3\S3ClientInterface
   */
  protected $s3Client;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Mime Type Guesser Interface.
   *
   * @var \Drupal\Core\File\MimeType\MimeTypeGuesser
   */
  protected $mimeType;

  /**
   * Logger Channel Interface.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * AjaxController constructor.
   *
   * @param \Drupal\s3fs\S3fsServiceInterface $s3fs
   *   The S3fs service interface.
   * @param \Drupal\Core\Database\Connection $database
   *   The Drupal database connection service.
   * @param \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface $mimeType
   *   The mime type guesser service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The core logger factory service.
   *
   * @throws \Drupal\s3fs\S3fsException
   *   The S3fs exception.
   */
  public function __construct(S3fsServiceInterface $s3fs, Connection $database, MimeTypeGuesserInterface $mimeType, LoggerChannelFactoryInterface $loggerFactory) {
    $s3_config = $this->config('s3fs.settings')->get();
    $this->s3Client = $s3fs->getAmazonS3Client($s3_config);
    $this->database = $database;
    $this->mimeType = $mimeType;
    $this->logger = $loggerFactory->get('s3fs');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('s3fs'),
      $container->get('database'),
      $container->get('file.mime_type.guesser'),
      $container->get('logger.factory')
    );
  }

  /**
   * Return the file key (i.e. the path and name).
   *
   * The values $file_size and $file_index are just values to be passed through
   * and returned to the javaScript function.
   */
  public function getKey($directory, $file_name, $file_size, $file_index, $replace = FileSystemInterface::EXISTS_RENAME) {

    // Strip control characters (ASCII value < 32). Though these are allowed in
    // some filesystems, not many applications handle them well.
    $file_name = preg_replace('/[\x00-\x1F]/u', '_', $file_name);
    // Also replace forbidden chars if this is a Windows envrionment.
    if (substr(PHP_OS, 0, 3) == 'WIN') {
      // These characters are not allowed in Windows filenames.
      $file_name = str_replace([':', '*', '?', '"', '<', '>', '|'], '_', $file_name);
    }

    // Decode the "/" chars in the directory and build an initial file key.
    // Note: this will include the s3fs root folder, if specified.
    $directory = str_replace('::', '/', $directory);
    $file_key = $directory . '/' . $file_name;

    // Check if a file with this key already exists on S3.
    $file_exists = $this->s3FileExists($file_key);

    if ($file_exists) {
      switch ($replace) {
        case FileSystemInterface::EXISTS_REPLACE:
          // Do nothing here, we want to overwrite the existing file.
          break;

        case FileSystemInterface::EXISTS_RENAME:
          $file_key = $this->createFileKey($directory, $file_name);
          break;

        case FileSystemInterface::EXISTS_ERROR:
          // Error reporting handled by calling function.
          return FALSE;
      }
    }
    // Core file_destination is not able to check remoe file existience.
    return new JsonResponse([
      'file_key' => $file_key,
      'file_name' => $file_name,
      'file_size' => $file_size,
      'file_index' => $file_index,
    ]);
  }

  /**
   * Create a new file key if the original one already exists.
   */
  private function createFileKey($directory, $file_name) {

    // Remove the root folder from the file directory if specified.
    $root_folder = '';
    $config = $this->config('s3fs.settings');
    if (!empty($config->get('root_folder'))) {
      $root_folder = $config->get('root_folder') . '/';
      $directory = str_replace($root_folder, '', $directory);
    }

    $separator = '/';
    // A URI or path may already have a trailing slash or look like "public://".
    if (substr($directory, -1) == '/') {
      $separator = '';
    }

    // Extract the file base name and the file extension (with leading period).
    $base_name = substr($file_name, 0, strrpos($file_name, '.'));
    $extension = substr($file_name, strrpos($file_name, '.'));

    $key_base = $root_folder . $directory . $separator . $base_name;

    // Look in the s3fs cache to find files with a key like this.
    $uri_base = 's3://' . $directory . $separator . $base_name;
    $records = $this->database->select('s3fs_file', 's')
      ->fields('s', ['uri'])
      ->condition('uri', $this->database->escapeLike($uri_base) . '%', 'LIKE')
      ->execute()
      ->fetchCol();

    // Process the results array to extract the suffix values.
    $results = [];
    foreach ($records as $record) {
      $suffix = str_replace([$uri_base, $extension], '', $record);
      if ($suffix) {
        // Drop the leading underscore char.
        $suffix = (int) substr($suffix, 1);
        $results[$suffix] = $record;
      }
    }

    // Find a key suffix that can be used by looking for a gap in suffix values.
    for ($suffix = 0; $suffix < count($results); $suffix++) {
      if (!isset($results[$suffix])) {
        break;
      }
    }
    // If we drop out the bottom then suffix will be one greater then largest
    // existing value.  Create a trial key and test.
    $trial_key = $key_base . '_' . $suffix . $extension;

    if ($this->s3FileExists($trial_key)) {
      // Destination file already exists, then cache is stale. Rebuild required.
      $this->logger->info('S3fs cache table rebuild required (key %key missing)',
        ['%key' => $trial_key]);
      // Look for a new suffix value greater then the largest already known.
      $suffix = max(array_keys($results));
      do {
        $trial_key = $key_base . '_' . ++$suffix . $extension;
      } while ($this->s3FileExists($trial_key));
    }

    return $trial_key;
  }

  /**
   * Check whehter a passed file name exists (using the file key).
   */
  private function s3FileExists($key) {
    $config = $this->config('s3fs.settings');
    $bucket = $config->get('bucket');
    return $this->s3Client->doesObjectExist($bucket, $key);
  }

  /**
   * Save the file details to the managed file table.
   */
  public function saveFile($file_path, $file_name, $file_size, $field_name) {
    $user = $this->currentUser();

    // Decode the "/" chars from file path.
    $file_path = str_replace('::', '/', $file_path);

    // Remove the root folder from the file path if specified.
    $config = $this->config('s3fs.settings');
    if (!empty($config->get('root_folder'))) {
      $root_folder = $config->get('root_folder');
      $file_path = str_replace($root_folder . '/', '', $file_path);
    }

    $file_uri = 's3://' . $file_path;

    // Record the uploaded file in the s3fs cache. This needs to be done before
    // the file is saved so the the filesize can be found from the cache.
    $wrapper = new S3fsStream();
    $wrapper->writeUriToCache($file_uri);

    // Convert URLs back to their proper (original) file stream names.
    $public_folder = 's3://' . ($config->get('public_folder') ?: 's3fs-public');
    $private_folder = 's3://' . ($config->get('private_folder') ?: 's3fs-private');
    if (strpos($file_uri, $public_folder) === 0 || strpos($file_uri, $private_folder) === 0) {
      $file_uri = str_replace([$public_folder, $private_folder], ['public:/', 'private:/'], $file_uri);
    }

    $file_mime = $this->mimeType->guess($file_name);

    $values = [
      'uid' => $user->id(),
      'status' => 0,
      'filename' => $file_name,
      'uri' => $file_uri,
      'filesize' => $file_size,
      'filemime' => $file_mime,
      'source' => $field_name,
    ];
    $file = File::create($values);

    $errors = [];
    $errors = array_merge($errors, $this->moduleHandler()->invokeAll('file_validate', [$file]));

    if (empty($errors)) {
      $file->save();
      $values['fid'] = $file->id();
      $values['uuid'] = $file->uuid();
    }
    else {
      $file->delete();
      $values['errmsg'] = implode("\n", $errors);
    }

    return new JsonResponse($values);
  }

}
