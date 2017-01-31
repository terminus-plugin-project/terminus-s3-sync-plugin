<?php

namespace Pantheon\TerminusS3Sync\Commands;

require_once __DIR__ . '/../../vendor/autoload.php';

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use League\Container\ContainerAwareInterface;
use League\Container\ContainerAwareTrait;
use WriteiniFile\WriteiniFile;
use GuzzleHttp\Promise\EachPromise;

use Aws\S3\S3Client;

/**
 * Class SyncS3Command
 * Syncs a sites backups with an S3 Account
 */
class SyncS3Command extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface, ContainerAwareInterface {
  use RequestAwareTrait;
  use SiteAwareTrait;
  use ContainerAwareTrait;

  /**
   * Syncs a sites backup files with S3 bucket
   *
   * @authorize
   *
   * @command site:sync-s3
   * @aliases site:s3
   *
   * @param string $site_env Site & environment in the format `site-name.env`
   * @param string $bucket S3 Bucket Name
   * @option string $profile AWS Profile (optional)
   * @option string $aws_region AWS Region (optional)
   * @option string $aws_key AWS Key (optional)
   * @option string $aws_secret AWS Secret (optional)
   * @option string $tmp_location Temporary Location to store downloads (optional)
   * @option string $save_path Path within AWS bucket to store downloads (optional)
   * @option boolean $save Save config settings (optional)
   * @option int $concurrent Concurrent Downloads to process (optional)
   * @option string $elements Elements to sync (optional)
   * @option string $after Date which to start syncing items (optional)
   *
   * @usage terminus site:sync-s3 <site>.<env> <bucket> <profile> <aws_region> <aws_key> <aws_secret> <tmp_location> <save_path> <save> <concurrent> <elements> <after>
   */
  public function sync($site_env, $bucket, $options = [
    'profile' => NULL,
    'aws_region' => NULL,
    'aws_key' => NULL,
    'aws_secret' => NULL,
    'tmp_location' => '/tmp',
    'save_path' => NULL,
    'save' => FALSE,
    'concurrent' => 2,
    'elements' => NULL,
    'after' => NULL
  ]) {
    list($site, $env) = $this->getOptionalSiteEnv($site_env);

    $client_options = $this->generateCredentials($options);
    $client = new S3Client($client_options);
    $client->registerStreamWrapper();

    $dir = '';

    if (!empty($options['save_path'])) {
      $dir .= $options['save_path'] . '/';
    }

    /**
     * Get all environments for a site.
     */
    $envs = [];
    if (is_null($env)) {
      $envs = $site->getEnvironments()->ids();
    }
    else {
      $envs[] = $env->id;
    }

    /**
     * Create location if does not exist.
     */
    if (!file_exists($options['tmp_location'])) {
      mkdir($options['tmp_location'], 0777);
    }

    foreach ($envs AS $tmp_env) {
      $tmp = $site->get('name') . '.' . $tmp_env;
      list(, $full_env) = $this->getSiteEnv($tmp);
      $bucket_dir = $dir . $site->get('name') . '/' . $full_env->id . '/';
      if (!file_exists("s3://" . $bucket . '/' . $bucket_dir)) {
        //mkdir("s3://" . $bucket . '/' . $bucket_dir, 0777, TRUE);
      }


      $backups = $full_env->getBackups()
        ->getFinishedBackups($options['elements']);
      // Reference: https://blog.madewithlove.be/post/concurrent-http-requests/

      // @todo: Check to see if file already exists otherwise do not create an upload.
      $promises = (function () use ($backups, $client, $bucket, $bucket_dir, $options) {

        foreach ($backups AS $backup_key => $backup) {
          $file_name = $bucket_dir . $backup->get('filename');
          if(file_exists("s3://" . $bucket . '/' . $file_name)){
            continue;
          }

          $backup_url = $backup->getUrl();

          $file = fopen($backup_url, 'r');
          $original = Psr7\stream_for($file);
          $stream = new Psr7\CachingStream($original);
          yield $client->putObjectAsync(array(
            'Bucket' => $bucket,
            'Key' => $file_name,
            'Body' => $stream,
            '@http' => [
              'progress' => function ($expectedDl, $dl, $expectedUl, $ul) use ($file_name, $options){
                if($options['verbose']) {
                  printf(
                    "%s: %s/%s %s uploaded\n",
                    basename($file_name),
                    $this->formatBytes($ul),
                    $this->formatBytes($expectedUl),
                    $this->formatePercentage($ul, $expectedUl )
                  );
                }
            }],
          ))->then(function() use($file_name){
            print $file_name . ": Complete\n";
          });
        }
      })();

      $waiter = new EachPromise($promises, [
        'concurrency' => $options['concurrent'],
        'fulfilled' => function () {

        },
      ]);

      $waiter->promise()->wait();
    }
  }

  private function formatePercentage($a, $b){
    return number_format( ($a / $b) * 100, 2 ) . '%';
  }

  private function generateCredentials($options) {
    $data = [
      'version' => 'latest',
      'region' => 'us-east-1'
    ];

    if (!empty($options['aws_region'])) {
      $data['region'] = $options['aws_region'];
    }

    if (!empty($options['profile'])) {
      $data['profile'] = $options['profile'];
    }

    if (!empty($options['aws_key']) && !empty($options['aws_secret'])) {
      $data['credentials']['key'] = $options['aws_key'];
      $data['credentials']['secret'] = $options['aws_secret'];
    }

    return $data;
  }

  /**
   * Configure S3 Credentials
   *
   * @command site:sync-s3:config
   * @aliases site:s3:config
   *
   * @option string $profile AWS Profile (optional)
   * @option string $aws_region AWS Region (optional)
   * @option string $aws_key AWS Key (optional)
   * @option string $aws_secret AWS Secret (optional)
   *
   * @usage terminus site:sync-s3:config <profile> <aws_region> <aws_key> <aws_secret>
   */
  public function s3Config($options = [
    'profile' => 'default',
    'aws_region' => 'us-east-1',
    'aws_key' => NULL,
    'aws_secret' => NULL
  ]) {
    $home = $_SERVER['HOME'];

    if (!file_exists($home . '/.aws')) {
      mkdir($home . '/.aws');
    }

    $data_credentials = array();
    if (file_exists($home . '/.aws/credentials')) {
      $data_credentials = parse_ini_file($home . '/.aws/credentials', TRUE);
    }

    $data_credentials[$options['profile']] = array(
      'aws_access_key_id' => $options['aws_key'],
      'aws_secret_access_key' => $options['aws_secret']
    );

    chmod($home . '/.aws/credentials', 0777);
    $credentials = new WriteiniFile($home . '/.aws/credentials');
    $credentials->update($data_credentials);
    $credentials->write();


    $data_config = array();
    if (file_exists($home . '/.aws/config')) {
      $data_config = parse_ini_file($home . '/.aws/config', TRUE);
    }
    $data_config[$options['profile']] = array(
      'region' => $options['aws_region'],
      'output' => 'json'
    );
    chmod($home . '/.aws/config', 0777);
    $config = new WriteiniFile($home . '/.aws/config');
    $config->update($data_config);
    $config->write();
  }

  /**
   * @param int $bytes Number of bytes (eg. 25907)
   * @param int $precision [optional] Number of digits after the decimal point (eg. 1)
   * @return string Value converted with unit (eg. 25.3KB)
   */
  private function formatBytes($bytes, $precision = 2) {
    $unit = ["B", "KB", "MB", "GB"];
    $exp = floor(log($bytes, 1024)) | 0;
    return round($bytes / (pow(1024, $exp)), $precision).$unit[$exp];
  }
}

