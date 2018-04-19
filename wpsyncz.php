<?php

namespace WPSyncz;

use WP_CLI\ExitException;

/**
 * Don't do anything if not running WP-CLI
 */
if (!defined('WP_CLI')) {
    return;
}

/**
 * Sync between environments/aliases
 *
 * @package WPSyncz
 */
class SynczCommand extends \WP_CLI_Command
{

    /**
     * Syncz API version
     *
     * This will increment whenever the API changes
     */
    const API_VERSION = 1;

    /**
     * Syncz working directory (relative to home)
     */
    const WORKING_DIR = '.syncz';

    /**
     * Syncz working directory mode
     */
    const WORKING_DIR_MODE = 0760;

    /**
     * Default SSH Port
     */
    const SSH_PORT = 22;

    /**
     * Default actions
     */
    const DEFAULT_ACTIONS = ['media', 'plugins', 'db'];

    /**
     * Alias config
     *
     * @var array|null
     */
    protected $aliases = null;

    /**
     * Last output from exec()
     *
     * @var string|null
     */
    protected $lastOutput = null;

    /**
     * Sync data
     *
     * @var array
     */
    protected $syncData = [];

    /**
     * Parse command arguments
     *
     * @param array $args
     * @param int $maximumAliases
     * @param array $defaultActions
     *
     * @return array
     */
    protected function parseArgs($args, $maximumAliases = 1, $defaultActions = [])
    {
        $aliases = [];
        $actions = [];

        foreach ($args as $arg) {
            if (substr($arg, 0, 1) === '@') {
                if (count($aliases) > $maximumAliases) {
                    \WP_CLI::error('Too many aliases specified.');
                }
                $aliases[] = $arg;
            } else {
                $actions[] = $arg;
            }
        }

        if (!$aliases) {
            \WP_CLI::error('Missing alias.');
        }

        foreach ($aliases as $alias) {
            if ($alias !== '@local' && !$this->isRealAlias($alias)) {
                \WP_CLI::error("Alias {$alias} is not configured.");
            }
        }

        if (!$actions) {
            $actions = $defaultActions;
        }

        return compact('aliases', 'actions');
    }

    /**
     * Get the WP CLI alias config
     */
    protected function initAliases()
    {
        if ($this->aliases === null) {
            $this->aliases = [];
            $configurator = \WP_CLI::get_configurator();
            foreach ($configurator->get_aliases() as $alias => $config) {
                $this->aliases[$alias] = \WP_CLI\Utils\parse_ssh_url($config['ssh']);
                $this->aliases[$alias] += $config;
                if (!isset($this->aliases[$alias]['port'])) {
                    $this->aliases[$alias]['port'] = self::SSH_PORT;
                }
            }
        }
    }

    /**
     * Check if an alias is found in WP CLI config
     *
     * @param string $alias
     *
     * @return bool
     */
    protected function isRealAlias($alias)
    {
        $this->initAliases();
        return isset($this->aliases[$alias]['ssh']);
    }

    /**
     * Pull data from source to local
     *
     * ## OPTIONS
     *
     * <source>
     * : Source alias to sync from.
     *
     * [<actions>...]
     * : What to pull (default all)
     * ---
     * options:
     *   - db
     *   - media
     *   - plugins
     * ---
     *
     * [--yes]
     * : Answer yes to any confirmation prompts.
     *
     * ## EXAMPLES
     *
     *     # Pull all data from @production to @local
     *     wp syncz pull @production
     *
     *     # Push database from @production to @local
     *     wp syncz pull @production db
     *
     * @param array $args
     * @param array $assocArgs
     */
    public function pull($args, $assocArgs = [])
    {
        /**
         * @var array $aliases
         * @var array $actions
         */
        extract($this->parseArgs($args, 1, self::DEFAULT_ACTIONS));

        if ($aliases[0] === '@local') {
            \WP_CLI::error('Cannot pull from @local.');
        }

        $this->callActions($aliases[0], '@local', $actions, $assocArgs);
    }

    /**
     * Push data from local to destination
     *
     * ## OPTIONS
     *
     * <destination>
     * : Destination alias to push to.
     *
     * [<actions>...]
     * : What to push (default all)
     * ---
     * options:
     *   - db
     *   - media
     *   - plugins
     * ---
     *
     * [--yes]
     * : Answer yes to any confirmation prompts.
     *
     * ## EXAMPLES
     *
     *     # Push all data from @local to @production
     *     wp syncz push @production
     *
     *     # Push database from @local to @production
     *     wp syncz push @production db
     *
     * @param array $args
     * @param array $assocArgs
     */
    public function push($args, $assocArgs = [])
    {
        /**
         * @var array $aliases
         * @var array $actions
         */
        extract($this->parseArgs($args, 1, self::DEFAULT_ACTIONS));

        if ($aliases[0] === '@local') {
            \WP_CLI::error('Cannot push to @local.');
        }

        $this->callActions('@local', $aliases[0], $actions, $assocArgs);
    }

    /**
     * Sync between remotes
     *
     * ## OPTIONS
     *
     * <source>
     * : Source alias
     *
     * <destination>
     * : Destination alias
     *
     * [<actions>...]
     * : What to sync (default all)
     * ---
     * options:
     *   - db
     *   - media
     *   - plugins
     * ---
     *
     * [--yes]
     * : Answer yes to any confirmation prompts.
     *
     * ## EXAMPLES
     *
     *     # Sync all data from @production to @staging
     *     wp syncz remote @production @staging
     *
     *     # Sync database data from @production to @staging
     *     wp syncz remote @production @staging db
     *
     * @param array $args
     * @param array $assocArgs
     */
    public function remote($args, $assocArgs = [])
    {
        /**
         * @var array $aliases
         * @var array $actions
         */
        extract($this->parseArgs($args, 2, self::DEFAULT_ACTIONS));

        if ($aliases[0] === $aliases[1]) {
            \WP_CLI::error("Cannot sync from {$aliases[0]} to {$aliases[1]}.");
        }

        $this->callActions($aliases[0], $aliases[1], $actions, $assocArgs);
    }

    /**
     * Call all requested actions
     *
     * @param string $src
     * @param string $dst
     * @param array $actions
     * @param array $assocArgs
     */
    protected function callActions($src, $dst, $actions, $assocArgs = [])
    {
        $this->fetchSyncData($src, $actions);
        $this->fetchSyncData($dst, $actions);

        foreach ($actions as $action) {
            $method = 'sync' . ucfirst($action);
            if (!method_exists($this, $method)) {
                \WP_CLI::error("Unknown action: {$action}.");
            }

            $this->{$method}($src, $dst, $assocArgs);
        }
    }

    /**
     * Sync plugins
     *
     * @param string $src
     * @param string $dst
     * @param array $assocArgs
     */
    protected function syncPlugins($src, $dst, $assocArgs = [])
    {
        \WP_CLI::line("Copying plugins from $src to $dst...");

        $srcPlugins = $this->syncData[$src]['plugins'];
        $dstPlugins = $this->syncData[$dst]['plugins'];

        foreach ($srcPlugins as $pluginKey => $srcPlugin) {
            $dstPlugin = isset($dstPlugins[$pluginKey]) ? $dstPlugins[$pluginKey] : null;

            $versionComparison = 1;
            if (empty($srcPlugin['Version'])) {
                \WP_CLI::warning("\"{$srcPlugin['Name']}\" has no version.");
            } elseif (!empty($dstPlugin['Version'])) {
                $versionComparison = version_compare($srcPlugin['Version'], $dstPlugin['Version']);
            }

            if ($versionComparison < 0) {
                \WP_CLI::warning("Syncing \"{$srcPlugin['Name']}\" to an older version.");
                \WP_CLI::confirm("Are you sure you want to continue?", $assocArgs);
            }

            $localSlug = preg_replace('%^([^/]+?)(?:\.php)?(?:/.*)?$%im', '$1', $pluginKey);
            if ($versionComparison === 0) {
                \WP_CLI::line("\"{$srcPlugin['Name']}\" is already installed at correct version.");
            } elseif (empty($srcPlugin['Slug']) || $srcPlugin['Slug'] !== $localSlug) {
                \WP_CLI::line("Syncing \"{$srcPlugin['Name']}\" from {$src}.");

                $srcPath = $this->syncData[$src]['pluginDir'] . '/';
                $dstPath = $this->syncData[$dst]['pluginDir'] . '/';
                $isDir = strpos($pluginKey, '/') !== false;

                if ($dstPlugin) {
                    $this->wp($dst, "plugin delete {$localSlug}");
                }

                if ($isDir) {
                    $srcPath .= dirname($pluginKey);
                    $dstPath .= dirname($pluginKey);
                    $this->wpEval($dst, "mkdir('" . addslashes($dstPath) . "');");
                } else {
                    $srcPath .= $pluginKey;
                    $dstPath .= $pluginKey;
                }

                $this->scp($src, $srcPath, $dst, $dstPath, $isDir);
            } else {
                \WP_CLI::line("Syncing \"{$srcPlugin['Name']}\" from the WordPress repository.");

                $command = "plugin install {$srcPlugin['Slug']} --force";
                if (!empty($srcPlugin['Version'])) {
                    $command .= " --version={$srcPlugin['Version']}";
                }

                $this->wp($dst, $command);
            }

            if ($srcPlugin['Status'] === 'active' && $dstPlugin['Status'] !== 'active') {
                $this->wp($dst, "plugin activate {$localSlug}");
                \WP_CLI::debug("Activated {$dstPlugin['Name']}");
            } elseif ($srcPlugin['Status'] !== 'active' && $dstPlugin['Status'] === 'active') {
                $this->wp($dst, "plugin deactivate {$localSlug}");
                \WP_CLI::debug("Deactivated {$dstPlugin['Name']}");
            }
        }

        \WP_CLI::success('Done');
    }

    /**
     * Sync media
     *
     * @param string $src
     * @param string $dst
     * @param array $assocArgs
     */
    protected function syncMedia($src, $dst, $assocArgs = [])
    {
        \WP_CLI::warning("This action will overwrite the media at {$dst}.");
        \WP_CLI::confirm("Are you sure you want to continue?", $assocArgs);

        \WP_CLI::line("Copying media from $src to $dst...");
        $this->scp($src, $this->syncData[$src]['uploadDir'], $dst, $this->syncData[$dst]['uploadDir'], true);
        \WP_CLI::success('Done');
    }

    /**
     * Sync database
     *
     * @param string $src
     * @param string $dst
     * @param array $assocArgs
     */
    protected function syncDb($src, $dst, $assocArgs = [])
    {
        \WP_CLI::warning("This action will overwrite the database at {$dst}.");
        \WP_CLI::confirm("Are you sure you want to continue?", $assocArgs);

        \WP_CLI::line("Backing up database on {$dst}...");
        $backupPath = $this->exportDb($dst);
        \WP_CLI::line("Backed up to {$backupPath} on {$dst}.");

        \WP_CLI::line("Exporting database on {$src}...");
        $exportPath = $this->exportDb($src);

        \WP_CLI::line("Downloading database to {$dst}...");
        $importPath = $this->syncData[$dst]['workingDir'] . '/' . basename($exportPath);
        $this->scp($src, $exportPath, $dst, $importPath);

        \WP_CLI::line("Importing database to {$dst}...");
        $activePlugins = escapeshellarg($this->wp($dst, 'option get active_plugins --format=json'));

        $this->wp($dst, "db import $importPath");

        \WP_CLI::line("Repairing database...");
        foreach ($this->getDbReplacements($src, $dst) as $search => $replace) {
            $search = escapeshellarg($search);
            $replace = escapeshellarg($replace);
            $this->wp($dst, "search-replace --format=count {$search} {$replace}");
        }
        $this->wp($dst, 'transient delete --all');
        $this->wp($dst, 'cache flush');
        $this->wp($dst, 'rewrite flush');
        $this->wp($dst, "option set --format=json active_plugins $activePlugins");

        \WP_CLI::success('Done');
    }

    /**
     * Export a database to a file
     *
     * @param string $alias
     *
     * @return string The path to the export file on the server the database was exported on
     */
    protected function exportDb($alias)
    {
        $site = trim(preg_replace('/\W+/', '-', $this->syncData[$alias]['url']), '-');
        $date = date('ymd-His');
        $random = bin2hex(random_bytes(8));
        $exportFile = $this->syncData[$alias]['workingDir'] . "/db-{$site}-{$date}-{$random}.sql";

        $this->wp($alias, "db export {$exportFile}");
        return $exportFile;
    }

    /**
     * Get database replacements
     *
     * @param string $src
     * @param string $dst
     *
     * @return array
     */
    protected function getDbReplacements($src, $dst)
    {
        $srcData = $this->syncData[$src];
        $dstData = $this->syncData[$dst];
        return [
            $srcData['url'] => $dstData['url'],
            '@' . parse_url($srcData['url'], PHP_URL_HOST) => '@' . parse_url($dstData['url'], PHP_URL_HOST),
            $srcData['rootDir'] => $dstData['rootDir'],
        ];
    }

    /**
     * Run a WP CLI command and return the result
     *
     * @param string $alias
     * @param string $command
     * @param bool $exitError
     *
     * @return string
     *
     * @throws ExitException
     */
    protected function wp($alias, $command, $exitError = true)
    {
        if ($this->isRealAlias($alias)) {
            $command = "{$alias} {$command}";
        }

        if ($key = array_search('--debug', $GLOBALS['argv'])) {
            unset($GLOBALS['argv'][$key]);
        }

        \WP_CLI::debug('WP command: ' . $command);
        $result = \WP_CLI::runcommand($command, ['return' => 'all', 'exit_error' => $exitError]);
        if ($result->return_code) {
            throw new ExitException($result->stderr, $result->return_code);
        }

        return $result->stdout;
    }

    /**
     * Copy a file or files between two environments
     *
     * @param string $srcAlias
     * @param string $srcPath
     * @param string $dstAlias
     * @param string $dstPath
     * @param bool $recursive
     */
    protected function scp($srcAlias, $srcPath, $dstAlias, $dstPath, $recursive = false)
    {
        $src = escapeshellarg($this->getScpUrl($srcAlias, $srcPath));
        $dst = escapeshellarg($this->getScpUrl($dstAlias, $dstPath));

        $options = ' -C'; // use compression

        if ($this->isRealAlias($srcAlias) && $this->isRealAlias($dstAlias)) {
            $options .= ' -3'; // copy between two remote hosts

            if ($this->aliases[$srcAlias]['port'] !== $this->aliases[$dstAlias]['port']) {
                \WP_CLI::error('Cannot copy between remote servers with different ports.');
            }
        }

        if (isset($this->aliases[$srcAlias]['port'])) {
            $options .= ' -P ' . $this->aliases[$srcAlias]['port'];
        } elseif (isset($this->aliases[$dstAlias]['port'])) {
            $options .= ' -P ' . $this->aliases[$dstAlias]['port'];
        }

        if ($recursive) {
            $options .= ' -r'; // copy recursively
            $src .= '/*';
        }

        $command = "scp {$options} {$src} {$dst}";
        \WP_CLI::debug('Executing command: ' . $command);
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            \WP_CLI::error('SCP failed: ' . implode("\n", $output));
        }
    }

    /**
     * Construct URL for SCP
     *
     * @param string $alias
     * @param string $path
     *
     * @return string
     */
    protected function getScpUrl($alias, $path)
    {
        $url = $path;
        if ($this->isRealAlias($alias)) {
            $url = $this->aliases[$alias]['host'] . ':' . rtrim($path, '/');
            if (isset($this->aliases[$alias]['user'])) {
                $url = $this->aliases[$alias]['user'] . '@' . $url;
            }
        }
        return $url;
    }

    /**
     * Eval PHP code using WP CLI
     *
     * @param string $alias
     * @param string $code
     * @param bool $skipWordPress
     *
     * @return string
     */
    protected function wpEval($alias, $code, $skipWordPress = false)
    {
        $code = escapeshellarg($code);
        $skipWordPress = $skipWordPress ? '--skip-wordpress' : '';
        return $this->wp($alias, "eval $code $skipWordPress");
    }

    /**
     * Get data required to for syncing
     *
     * ## OPTIONS
     *
     * <source>
     * : Source to get data from
     *
     * [<actions>...]
     * : Actions to fetch data for (default all)
     *
     * ## EXAMPLES
     *
     *     # Get the data for local
     *     wp syncz data @local
     *
     *     # Get the data for production
     *     wp syncz data @production
     *
     * @param array $args
     * @param array $assocArgs
     */
    public function data($args, $assocArgs = [])
    {
        /**
         * @var array $aliases
         * @var array $actions
         */
        extract($this->parseArgs($args));

        $this->fetchSyncData($aliases[0], $actions);
        \WP_CLI::line(json_encode($this->syncData[$aliases[0]], JSON_PRETTY_PRINT));
    }

    /**
     * Get sync data for an alias
     *
     * @param string $alias
     * @param array $actions
     */
    protected function fetchSyncData($alias, $actions = [])
    {
        if ($this->isRealAlias($alias)) {
            $this->syncData[$alias] = $this->getRemoteData($alias, $actions);
        } elseif ($alias === '@local') {
            $this->syncData[$alias] = $this->getLocalData($actions);
        } else {
            \WP_CLI::error("Alias {$alias} not found.");
        }
    }

    /**
     * Get the local data required for syncing
     *
     * @param array $actions
     *
     * @return array
     */
    protected function getLocalData($actions)
    {
        $data = [
            'url' => get_option('siteurl'),
            'rootDir' => ABSPATH,
            'workingDir' => $this->getWorkingDir(),
            'uploadDir' => $this->getUploadDir(),
            'pluginDir' => WP_PLUGIN_DIR,
            'apiVersion' => self::API_VERSION,
        ];

        if (in_array('plugins', $actions)) {
            $data['plugins'] = $this->getPlugins();
        }

        return $data;
    }

    /**
     * Get remote data required for syncing
     *
     * @param string $alias
     * @param array $actions
     *
     * @return array
     */
    protected function getRemoteData($alias, $actions = [])
    {
        try {
            $json = $this->wp($alias, 'syncz data @local ' . implode(' ', $actions), false);
        } catch (ExitException $e) {
            \WP_CLI::error("Syncz is not installed on {$alias}. Run 'wp syncz install {$alias}' to install.");
        }

        $data = json_decode($json, true);
        if (empty($json) || json_last_error() !== JSON_ERROR_NONE) {
            \WP_CLI::error("Failed to get data from {$alias}.");
        }

        if ($data['apiVersion'] < self::API_VERSION) {
            \WP_CLI::error("Older version of Syncz installed on {$alias}. Run 'wp syncz install {$alias}' to update.");
        } elseif ($data['apiVersion'] > self::API_VERSION) {
            \WP_CLI::error("Newer version of Syncz installed on {$alias}. Update before trying again.");
        }

        return $data;
    }

    /**
     * Get the local working directory
     *
     * @return string
     */
    protected function getWorkingDir()
    {
        $userInfo = posix_getpwuid(posix_getuid());
        $workingDir = $userInfo['dir'] . '/' . self::WORKING_DIR;

        if (!file_exists($workingDir)) {
            @mkdir($workingDir, self::WORKING_DIR_MODE);
            \WP_CLI::debug("Created working directory.");
        }

        if (!is_writable($workingDir)) {
            \WP_CLI::error("Working directory not writable.");
        }

        return $workingDir;
    }

    /**
     * Get the local uploads directory
     *
     * @return string
     */
    protected function getUploadDir()
    {
        $dirs = wp_upload_dir();
        if (!empty($dirs['error'])) {
            \WP_CLI::error($dirs['error']);
        }
        return $dirs['basedir'];
    }

    /**
     * Get a list of all plugins, including activation status and plugin repo slug
     *
     * @return array
     */
    protected function getPlugins()
    {
        delete_site_transient('update_plugins');
        wp_update_plugins();

        $data = get_site_transient('update_plugins');
        $plugins = get_plugins();
        foreach ($plugins as $pluginKey => &$plugin) {
            $plugin['Status'] = is_plugin_active($pluginKey) ? 'active' : 'inactive';

            $plugin['Slug'] = null;
            if (isset($data->response[$pluginKey]->slug)) {
                $plugin['Slug'] = $data->response[$pluginKey]->slug;
            } elseif (isset($data->no_update[$pluginKey]->slug)) {
                $plugin['Slug'] = $data->no_update[$pluginKey]->slug;
            }
        }
        return $plugins;
    }

    /**
     * Install Syncz on a remote host
     *
     * ## OPTIONS
     *
     * [<destination>]
     * : Destination alias to sync to.
     *
     * [--yes]
     * : Answer yes to any confirmation prompts.
     *
     * ## EXAMPLES
     *
     *     # Install Synz on production
     *     wp syncz install @production
     *
     * @param array $args
     * @param array $assocArgs
     */
    public function install($args, $assocArgs)
    {
        if (empty($args[0])) {
            \WP_CLI::error('Missing destination alias.');
        }
        $this->copyInstall($args[0]);
    }

    /**
     * Copy local sycz to a remote alias
     *
     * @param string $alias
     */
    protected function copyInstall($alias)
    {
        if (!$this->isRealAlias($alias)) {
            \WP_CLI::error("Alias {$alias} must be a remote alias.");
        }

        \WP_CLI::line("Installing Syncz to {$alias}...");
        $muPluginDir = $this->wpEval(
            $alias,
            'echo (is_writable(WPMU_PLUGIN_DIR) || @mkdir(WPMU_PLUGIN_DIR)) ? WPMU_PLUGIN_DIR : "";'
        );
        if (!$muPluginDir) {
            \WP_CLI::error('Could not write to WPMU_PLUGIN_DIR on @alias.');
        }

        $dstPath = $muPluginDir . '/' . basename(__FILE__);
        $this->scp('@local', __FILE__, $alias, $dstPath);
        \WP_CLI::line("Syncz installed to {$dstPath} on {$alias}.");
    }
}

/**
 * Register Syncz command
 */
\WP_CLI::add_command('syncz', SynczCommand::class);