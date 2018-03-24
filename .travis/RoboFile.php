<?php

// @codingStandardsIgnoreStart
use Robo\Exception\TaskException;

/**
 * Base tasks for setting up a module to test within a full Drupal environment.
 *
 * This file expects to be called from the root of a Drupal site.
 *
 * @class RoboFile
 * @codeCoverageIgnore
 */
class RoboFile extends \Robo\Tasks {

  /**
   * The database URL.
   */
  const DB_URL = 'sqlite://tmp/site.sqlite';

  /**
   * The website's URL.
   */
  const DRUPAL_URL = 'http://drupal.docker.localhost:8000';

  /**
   * RoboFile constructor.
   */
  public function __construct() {
    // Treat this command like bash -e and exit as soon as there's a failure.
    $this->stopOnFail();
  }

  /**
   * Command to run unit tests.
   *
   * @return \Robo\Result
   *   The result of the collection of tasks.
   */
  public function jobRunUnitTests() {
    $collection = $this->collectionBuilder();
    $collection->addTask($this->installDrupal());
    $collection->addTaskList($this->runUnitTests());
    return $collection->run();
  }

  /**
   * Command to check coding standards.
   *
   * @return null|\Robo\Result
   *   The result of the set of tasks.
   *
   * @throws \Robo\Exception\TaskException
   */
  public function jobCheckCodingStandards() {
    return $this->taskExecStack()
      ->stopOnFail()
      ->exec('vendor/bin/phpcs --config-set installed_paths vendor/drupal/coder/coder_sniffer')
      ->exec('vendor/bin/phpcs --standard=Drupal web/modules/custom')
      ->exec('vendor/bin/phpcs --standard=DrupalPractice web/modules/custom')
      ->run();
  }

  /**
   * Command to run behat tests.
   *
   * @return \Robo\Result
   *   The resul tof the collection of tasks.
   */
  public function jobRunBehatTests() {
    $collection = $this->collectionBuilder();
    $collection->addTaskList($this->downloadDatabase());
    $collection->addTaskList($this->buildEnvironment());
    $collection->addTask($this->waitForDrupal());
    $collection->addTaskList($this->runBehatTests());
    return $collection->run();
  }

  /**
   * Download's database to use within a Docker environment.
   *
   * @return \Robo\Task\Base\Exec[]
   *   An array of tasks.
   */
  protected function downloadDatabase() {
    $tasks = [];
    $tasks[] = $this->taskFilesystemStack()
      ->mkdir('mariadb-init');
    $tasks[] = $this->taskExec('wget ' . getenv('DB_DUMP_URL'))
      ->dir('mariadb-init');
    return $tasks;
  }

  /**
   * Builds the Docker environment.
   *
   * @return \Robo\Task\Base\Exec[]
   *   An array of tasks.
   */
  protected function buildEnvironment() {
    $tasks = [];
    $tasks[] = $this->taskFilesystemStack()
      ->copy('.travis/docker-compose.yml', 'docker-compose.yml')
      ->copy('.travis/traefik.yml', 'traefik.yml')
      ->copy('.travis/.env', '.env')
      ->copy('.travis/config/settings.local.php', 'web/sites/default/settings.local.php')
      ->copy('web/sites/default/default.settings.php', 'web/sites/default/settings.php')
      ->copy('.travis/config/behat.yml', 'tests/behat.yml');

    $tasks[] = $this->taskExec('docker-compose pull --parallel');
    $tasks[] = $this->taskExec('docker-compose up -d');
    return $tasks;
  }

  /**
   * Waits for Drupal to accept requests.
   *
   * @TODO Find an efficient way to wait for Drupal.
   *
   * @return \Robo\Task\Base\Exec
   *   A task to check that Drupal is ready.
   */
  protected function waitForDrupal() {
    return $this->taskExec('sleep 30s');
  }

  /**
   * Install Drupal.
   *
   * @return \Robo\Task\Base\Exec
   *   A task to install Drupal.
   */
  protected function installDrupal() {
    $task = $this->drush()
      ->args('site-install')
      ->option('verbose')
      ->option('yes')
      ->option('db-url', static::DB_URL, '=');
    return $task;
  }

  /**
   * Starts the web server.
   *
   * @return \Robo\Task\Base\Exec[]
   *   An array of tasks.
   */
  protected function startWebServer() {
    $tasks = [];
    $tasks[] = $this->taskExec('vendor/bin/drush --root=' . $this->getDocroot() . '/web runserver ' . static::DRUPAL_URL . ' &')->silent(TRUE);
    $tasks[] = $this->taskExec('until curl -s ' . static::DRUPAL_URL . '; do true; done > /dev/null');
    return $tasks;
  }

  /**
   * Run unit tests.
   *
   * @return \Robo\Task\Base\Exec[]
   *   An array of tasks.
   */
  protected function runUnitTests() {
    $tasks = [];
    $tasks[] = $this->taskFilesystemStack()
      ->copy('.travis/config/phpunit.xml', 'web/core/phpunit.xml');
    $tasks[] = $this->taskExecStack()
      ->dir('web')
      ->exec('../vendor/bin/phpunit -c core --debug --coverage-clover ../build/logs/clover.xml --verbose modules/custom');
    return $tasks;
  }

  /**
   * Starts the browser.
   *
   * @return \Robo\Task\Base\Exec
   *   A task to start the browser.
   */
  protected function startBrowser() {
    return $this->taskExec('phantomjs --webdriver=8643 &');
  }

  /**
   * Runs Behat tests.
   *
   * @return \Robo\Task\Base\Exec[]
   *   An array of tasks.
   */
  protected function runBehatTests() {
    $tasks = [];
    $tasks[] = $this->taskExecStack()
      ->exec('docker-compose exec -T php vendor/bin/behat --verbose -c tests/behat.yml');
    return $tasks;
  }

  /**
   * Return drush with default arguments.
   *
   * @return \Robo\Task\Base\Exec
   *   A drush exec command.
   */
  protected function drush() {
    // Drush needs an absolute path to the docroot.
    $docroot = $this->getDocroot() . '/web';
    return $this->taskExec('vendor/bin/drush')
      ->option('root', $docroot, '=');
  }

  /**
   * Get the absolute path to the docroot.
   *
   * @return string
   *   The document root.
   */
  protected function getDocroot() {
    return (getcwd());
  }

}
