<?php

// phpcs:ignoreFile

//pull vcap services out of environment
$vcapServices = json_decode(getenv('VCAP_SERVICES'), TRUE);

// DATABASE
$mysqlCreds = $vcapServices['mysql'][0]['credentials'];
$databases['default']['default'] = array(
  'driver' => 'mysql',
  'database' => $mysqlCreds['name'],
  'username' => $mysqlCreds['username'],
  'password' => $mysqlCreds['password'],
  'host' => $mysqlCreds['host'],
  'port' => $mysqlCreds['port'],
  'prefix' => 'lgdrupal_',
  'collation' => 'utf8mb4_general_ci',
  'pdo' => array(
    PDO::MYSQL_ATTR_SSL_CA => 'https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem',
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => FALSE,
  ),
);


// S3
$s3Creds = $vcapServices['aws-s3-bucket'][0]['credentials'];
$settings['s3fs.access_key'] = $s3Creds['aws_access_key_id'];
$settings['s3fs.secret_key'] = $s3Creds['aws_secret_access_key'];

$settings['s3fs.use_s3_for_public'] = TRUE;
$settings['s3fs.use_s3_for_private'] = TRUE;

$config['s3fs.settings']['bucket'] = $s3Creds['bucket_name'];
$config['s3fs.settings']['region'] = $s3Creds['aws_region'];

// Elasticsearch
$esCreds = $vcapServices['elasticsearch'][0]['credentials'];
$config['elasticsearch_connector.cluster.paas_es']['url'] = 'https://'. $esCreds['hostname'] .':' . $esCreds['port'];
$config['elasticsearch_connector.cluster.paas_es']['options']['username'] = $esCreds['username'];
$config['elasticsearch_connector.cluster.paas_es']['options']['password'] = $esCreds['password'];

// Redis
$redisCreds = $vcapServices['redis'][0]['credentials'];
$settings['redis.connection']['interface'] = 'PhpRedis';
$settings['redis.connection']['host']      = "tls://" . $redisCreds['host'];
$settings['redis.connection']['port']      = $redisCreds['port'];
$settings['redis.connection']['password']  = $redisCreds['password'];
$settings['cache']['default']              = 'cache.backend.redis';



// HASH SALT
$settings['hash_salt'] = getenv("HASH_SALT");

// pull vcap_application out of environment
$vcapApplication = json_decode(getenv('VCAP_APPLICATION'), TRUE);

$settings['trusted_host_patterns'] = [];

foreach($vcapApplication['application_uris'] as $pattern) {
  $settings['trusted_host_patterns'][] = '^' . str_replace('.', '\.', $pattern) . '$';
}























// Here be dragons

$settings['php_storage']['twig']['directory'] = '../storage/php';
$settings['file_private_path'] = '../storage/private';
$settings['config_sync_directory'] = '../config_sync';


$settings['update_free_access'] = FALSE;
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['migrate_node_migrate_type_classic'] = FALSE;
