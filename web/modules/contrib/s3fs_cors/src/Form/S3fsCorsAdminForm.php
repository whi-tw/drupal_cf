<?php

namespace Drupal\s3fs_cors\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\s3fs\S3fsServiceInterface;;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Config settings for S3FS Cors.
 */
class S3fsCorsAdminForm extends ConfigFormBase {

  use StringTranslationTrait;

  /**
   * S3 Client Interface.
   *
   * @var \Aws\S3\S3ClientInterface
   */
  private $s3Client;

  /**
   * S3fsCorsAdminForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory interface.
   * @param \Drupal\s3fs\S3fsServiceInterface $s3fs
   *   The S3fs service interface.
   *
   * @throws \Drupal\s3fs\S3fsException
   *   The S3fs exception.
   */
  public function __construct(ConfigFactoryInterface $config_factory, S3fsServiceInterface $s3fs) {
    parent::__construct($config_factory);
    $s3_config = $this->config('s3fs.settings')->get();
    $this->s3Client = $s3fs->getAmazonS3Client($s3_config);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('s3fs')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 's3fs_cors_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['s3fs_cors.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('s3fs_cors.settings');

    $form['s3fs_cors_origin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CORS Origin'),
      '#description' => $this->t('Please enter the URL from which your users access this website, e.g. <i>www.example.com</i>.
      You may optionally specifiy up to one wildcard, e.g. <i>*.example.com</i>.<br>
      Upon submitting this form, if this field is filled, your S3 bucket will be configured to allow CORS
      requests from the specified origin. If the field is empty, your bucket\'s CORS config will be deleted.'),
      '#default_value' => !empty($config->get('s3fs_cors_origin')) ? $config->get('s3fs_cors_origin') : '',
    ];

    $form['s3fs_https'] = [
      '#type' => 'radios',
      '#title' => $this->t('Use Https/Http'),
      '#description' => $this->t('Select what method you will like to use with your bucket'),
      '#default_value' => !empty($config->get('s3fs_https')) ? $config->get('s3fs_https') : 'http',
      '#options' => ['http' => $this->t('HTTP'), 'https' => $this->t('HTTPS')],
    ];

    $form['s3fs_access_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Access Type on File Uploads'),
      '#description' => $this->t('Select what access permission should be there on File Upload.'),
      '#default_value' => !empty($config->get('s3fs_access_type')) ? $config->get('s3fs_access_type') : 'public-read',
      '#options' => ['public-read' => $this->t('Public Read'), 'private' => $this->t('Private')],
    ];

    $form = parent::buildForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $cors_origin = $form_state->getValue('s3fs_cors_origin');
    $this->config('s3fs_cors.settings')
      ->set('s3fs_cors_origin', $cors_origin)
      ->set('s3fs_https', $form_state->getValue('s3fs_https'))
      ->set('s3fs_access_type', $form_state->getValue('s3fs_access_type'))
      ->save();

    // parent::submitForm($form, $form_state);
    // Get S3FS Settings.
    $s3_config = $this->config('s3fs.settings');
    if (!empty($s3_config)) {
      if (!empty($cors_origin)) {
        // Create an array of allowed CORS origins
        $cors_origins = array_filter(explode(',', str_replace(' ', ',', $cors_origin)));
        $allowed_origins = [];
        foreach ($cors_origins as $origin) {
          $allowed_origins[] = 'http://' . $origin;
          $allowed_origins[] = 'https://' . $origin;
        }
        $this->s3Client->putBucketCors([
          // REQUIRED.
          'Bucket' => $s3_config->get('bucket'),
          // REQUIRED.
          'CORSConfiguration' => [
            // REQUIRED.
            'CORSRules' => [
              [
                'AllowedHeaders' => ['*'],
                'ExposeHeaders' => ['x-amz-version-id'],
                'AllowedMethods' => ['POST'],
                'MaxAgeSeconds' => 3000,
                'AllowedOrigins' => $allowed_origins,
              ],
              [
                'AllowedMethods' => ['GET'],
                'AllowedOrigins' => ['*'],
              ],
              // ...
            ],
          ],
        ]);
        $this->messenger()->addMessage($this->t("CORS settings have been succesfully updated at AWS CORS"));
      }
      else {
        // If $form_state['values']['s3fs_cors_origin'] is empty, that means we
        // need to delete their bucket's CORS config.
        $this->s3Client->deleteBucketCors([
          'Bucket' => $s3_config->get('bucket'),
        ]);
        $this->messenger()->addMessage($this->t("CORS settings have been deleted succesfully"));
      }
    }
    else {
      $this->messenger()->addMessage($this->t('No values have been saved. Please check S3 Settings First'));
    }

  }

}
