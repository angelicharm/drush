<?php
namespace Drush\Drupal\Commands\config;

use Consolidation\AnnotatedCommand\CommandData;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;

class ConfigExportCommands extends DrushCommands
{

    /**
     * @var ConfigManagerInterface
     */
    protected $configManager;

    /**
     * @var StorageInterface
     */
    protected $configStorage;

    /**
     * @var StorageInterface
     */
    protected $configStorageSync;

    /**
     * @return ConfigManagerInterface
     */
    public function getConfigManager()
    {
        return $this->configManager;
    }

    /**
     * @return StorageInterface
     */
    public function getConfigStorage()
    {
        return $this->configStorage;
    }

    /**
     * @return StorageInterface
     */
    public function getConfigStorageSync()
    {
        return $this->configStorageSync;
    }


    /**
     * @param ConfigManagerInterface $configManager
     * @param StorageInterface $configStorage
     * @param StorageInterface $configStorageSync
     */
    public function __construct(ConfigManagerInterface $configManager, StorageInterface $configStorage, StorageInterface $configStorageSync)
    {
        parent::__construct();
        $this->configManager = $configManager;
        $this->configStorage = $configStorage;
        $this->configStorageSync = $configStorageSync;
    }

    /**
     * Export Drupal configuration to a directory.
     *
     * @command config-export
     * @interact-config-label
     * @param string $label A config directory label (i.e. a key in $config_directories array in settings.php).
     * @option add Run `git add -p` after exporting. This lets you choose which config changes to sync for commit.
     * @option commit Run `git add -A` and `git commit` after exporting.  This commits everything that was exported without prompting.
     * @option message Commit comment for the exported configuration.  Optional; may only be used with --commit.
     * @option destination An arbitrary directory that should receive the exported files. An alternative to label argument.
     * @usage drush config-export --destination
     *   Export configuration; Save files in a backup directory named config-export.
     * @bootstrap DRUSH_BOOTSTRAP_DRUPAL_FULL
     * @aliases cex
     */
    public function export($label = null, $options = ['add' => false, 'commit' => false, 'message' => null, 'destination' => ''])
    {
        $destination_dir = $this->processDestination($label, $options);

        // Do the actual config export operation.
        $preview = $this->doExport($options, $destination_dir);

        // Do the VCS operations.
        $this->doAddCommit($options, $destination_dir, $preview);
    }

    public function processDestination($label, $options)
    {
        // Determine which target directory to use.
        if ($target = $options['destination']) {
            if ($target === true) {
                // User did not pass a specific value for --destination. Make one.
                $destination_dir = drush_prepare_backup_dir('config-export');
            } else {
                $destination_dir = $target;
                // It is important to be able to specify a destination directory that
                // does not exist yet, for exporting on remote systems
                drush_mkdir($destination_dir);
            }
        } else {
            $destination_dir = \config_get_config_directory($label ?: CONFIG_SYNC_DIRECTORY);
        }
        return $destination_dir;
    }

    public function doExport($options, $destination_dir)
    {
        if (count(glob($destination_dir . '/*')) > 0) {
            // Retrieve a list of differences between the active and target configuration (if any).
            if ($destination_dir == \config_get_config_directory(CONFIG_SYNC_DIRECTORY)) {
                $target_storage = $this->getConfigStorageSync();
            } else {
                $target_storage = new FileStorage($destination_dir);
            }
            $active_storage = $this->getConfigStorage();
            $comparison_source = $active_storage;

            $config_comparer = new StorageComparer($comparison_source, $target_storage, $this->getConfigManager());
            if (!$config_comparer->createChangelist()->hasChanges()) {
                $this->logger()->notice(dt('The active configuration is identical to the configuration in the export directory (!target).', array('!target' => $destination_dir)));
                return;
            }

            drush_print("Differences of the active config to the export directory:\n");
            $change_list = array();
            foreach ($config_comparer->getAllCollectionNames() as $collection) {
                $change_list[$collection] = $config_comparer->getChangelist(null, $collection);
            }
            // Print a table with changes in color, then re-generate again without
            // color to place in the commit comment.
            ConfigCommands::configChangesTablePrint($change_list);
            $tbl = ConfigCommands::configChangesTableFormat($change_list);
            $preview = $tbl->getTable();
            if (!stristr(PHP_OS, 'WIN')) {
                $preview = str_replace("\r\n", PHP_EOL, $preview);
            }

            if (!$this->io()->confirm(dt('The .yml files in your export directory (!target) will be deleted and replaced with the active config.', array('!target' => $destination_dir)))) {
                throw new UserAbortException();
            }
            // Only delete .yml files, and not .htaccess or .git.
            $target_storage->deleteAll();
        }

        // Write all .yml files.
        $source_storage = $this->getConfigStorage();
        if ($destination_dir == \config_get_config_directory(CONFIG_SYNC_DIRECTORY)) {
            $destination_storage = $this->getConfigStorageSync();
        } else {
            $destination_storage = new FileStorage($destination_dir);
        }

        foreach ($source_storage->listAll() as $name) {
            $destination_storage->write($name, $source_storage->read($name));
        }

        // Export configuration collections.
        foreach ($this->getConfigStorage()->getAllCollectionNames() as $collection) {
            $source_storage = $source_storage->createCollection($collection);
            $destination_storage = $destination_storage->createCollection($collection);
            foreach ($source_storage->listAll() as $name) {
                $destination_storage->write($name, $source_storage->read($name));
            }
        }

        $this->logger()->success(dt('Configuration successfully exported to !target.', array('!target' => $destination_dir)));
        drush_backend_set_result($destination_dir);
        return isset($preview) ? $preview : 'No existing configuration to diff against.';
    }

    public function doAddCommit($options, $destination_dir, $preview)
    {
        // Commit or add exported configuration if requested.
        if ($options['commit']) {
            // There must be changed files at the destination dir; if there are not, then
            // we will skip the commit step.
            $result = drush_shell_cd_and_exec($destination_dir, 'git status --porcelain .');
            if (!$result) {
                throw new \Exception(dt("`git status` failed."));
            }
            $uncommitted_changes = drush_shell_exec_output();
            if (!empty($uncommitted_changes)) {
                $result = drush_shell_cd_and_exec($destination_dir, 'git add -A .');
                if (!$result) {
                    throw new \Exception(dt("`git add -A` failed."));
                }
                $comment_file = drush_save_data_to_temp_file($options['message'] ?: 'Exported configuration.'. $preview);
                $result = drush_shell_cd_and_exec($destination_dir, 'git commit --file=%s', $comment_file);
                if (!$result) {
                    throw new \Exception(dt("`git commit` failed.  Output:\n\n!output", array('!output' => implode("\n", drush_shell_exec_output()))));
                }
            }
        } elseif ($options['add']) {
            drush_shell_exec_interactive('git add -p %s', $destination_dir);
        }
    }

    /**
     * @hook validate config-export
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     */
    public function validate(CommandData $commandData)
    {
        $destination = $commandData->input()->getOption('destination');

        if ($destination === true) {
            // We create a dir in command callback. No need to validate.
            return;
        }

        if (!empty($destination)) {
            $additional = array();
            $values = drush_sitealias_evaluate_path($destination, $additional, true);
            if (!isset($values['path'])) {
                throw new \Exception('The destination directory could not be evaluated.');
            }
            $destination = $values['path'];
            $commandData->input()->setOption('destination', $destination);
            if (!file_exists($destination)) {
                $parent = dirname($destination);
                if (!is_dir($parent)) {
                    throw new \Exception('The destination parent directory does not exist.');
                }
                if (!is_writable($parent)) {
                    throw new \Exception('The destination parent directory is not writable.');
                }
            } else {
                if (!is_dir($destination)) {
                    throw new \Exception('The destination is not a directory.');
                }
                if (!is_writable($destination)) {
                    throw new \Exception('The destination directory is not writable.');
                }
            }
        }
    }
}
