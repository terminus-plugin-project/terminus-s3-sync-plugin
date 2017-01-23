<?php

namespace Pantheon\TerminusFiler\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Collections\Sites;
use Pantheon\Terminus\Exceptions\TerminusException;

/**
 * Class SyncS3Command
 * Syncs a sites backups with an S3 Account
 */
class SyncS3Command extends TerminusCommand implements SiteAwareInterface {
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
  *
   * @usage terminus site:sync-s3 <site>.<env>
   */
  public function filer($site_env, $options = []) {
    list($site, $env) = $this->getSiteEnv($site_env);
  }
}

