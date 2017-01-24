<?php

namespace Pantheon\TerminusS3Sync\Commands;

require_once __DIR__ . '/../../vendor/autoload.php';

use GuzzleHttp\ClientInterface;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Robo\Common\IO;

use Aws\S3\S3Client;

/**
 * Class SyncS3Command
 * Syncs a sites backups with an S3 Account
 */
class SyncS3Command extends TerminusCommand implements SiteAwareInterface, RequestAwareInterface
{
  use RequestAwareTrait;
  use SiteAwareTrait;

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
   *
   * @usage terminus site:sync-s3 <site>.<env> <bucket> <profile> <aws_region> <aws_key> <aws_secret> <tmp_location> <bucket_path>
   */
  public function sync($site_env, $bucket, $options = ['profile' => NULL, 'aws_region' => NULL, 'aws_key' => NULL, 'aws_secret' => NULL, 'tmp_location' => NULL, 'save_path' => NULL]) {
    list($site, $env) = $this->getOptionalSiteEnv($site_env);

    $client_options = $this->generateCredentials($options);
    $client = new S3Client($client_options);
    $client->registerStreamWrapper();

    $dir = "s3://" . $bucket . '/' ;

    if(!empty($options['save_path']))
      $dir .= $options['save_path'] . '/';

    $envs = array();
    if(is_null($env)) {
      $envs = $site->getEnvironments()->ids();
    }else{
      $envs[] = $env->id;
    }

    $backup_element = NULL;
    foreach($envs AS $tmp_env) {
      $tmp = $site->get('name') . '.' . $tmp_env;
      list(, $full_env) = $this->getSiteEnv($tmp);
      $tmp_dir = $dir . $site->get('name') . '/' . $full_env->id . '/';
      if(!file_exists($tmp_dir)) {
        mkdir($tmp_dir, 0777, TRUE);
      }

      $backups = $full_env->getBackups()->getFinishedBackups($backup_element);
      foreach ($backups AS $backup_key => $backup) {
        $file_name = $tmp_dir . $backup->get('filename');
        if(!file_exists($file_name)) {
          //file_put_contents($file_name, 'a');
        }
        print_r($backup->getDate());
        die;

        $backup_url = $backup->getUrl();
        $save_path = '/tmp/' . $backup->get('filename');
        //Download file locally
        //$this->request()->download($backup_url, $save_path);
      }
    }
  }

  private function generateCredentials($options){
    $data = [
      'version' => 'latest',
      'region' => 'us-east-1'
    ];

    if(!empty($options['aws_region']))
      $data['region'] = $options['aws_region'];

    if(!empty($options['profile']))
      $data['profile'] = $options['profile'];

    if(!empty($options['aws_key']) && !empty($options['aws_secret'])) {
      $data['credentials']['key'] = $options['aws_key'];
      $data['credentials']['secret'] = $options['aws_secret'];
    }

    return $data;
  }
}

