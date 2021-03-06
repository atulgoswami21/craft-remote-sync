<?php

namespace weareferal\RemoteSync\services;

use yii\base\Component;
use Craft;
use Craft\helpers\FileHelper;
use Craft\helpers\StringHelper;

use weareferal\RemoteSync\RemoteSync;
use weareferal\RemoteSync\services\providers\S3Provider;
use weareferal\RemoteSync\helpers\ZipHelper;


interface Provider
{
    public function list($filterExtensions): array;
    public function push($path);
    public function pull($key, $path);
    public function delete($key);
}

class RemoteSyncInstance
{
    public $filename;
    public $datetime;
    public $label;
    public $env;

    // Regex to capture/match:
    // - Site name
    // - Environment (optional and captured)
    // - Date (required and captured)
    // - Random string
    // - Version
    // - Extension
    private static $regex = '/^(?:[a-zA-Z0-9\-]+)\_(?:([a-zA-Z0-9\-]+)\_)?(\d{6}\_\d{6})\_(?:[a-zA-Z0-9]+)\_(?:[v0-9\.]+)\.(?:\w{2,10})$/';

    public function __construct($_filename)
    {
        // Extract values from filename
        preg_match(RemoteSyncInstance::$regex, $_filename, $matches);
        $env = $matches[1];
        $date = $matches[2];
        $datetime = date_create_from_format('ymd_Gis', $date);
        $label = $datetime->format('Y-m-d H:i:s');
        if ($env) {
            $label = $label  . ' (' . $env . ')';
        }
        $this->filename = $_filename;
        $this->datetime = $datetime;
        $this->label = $label;
        $this->env = $env;
    }
}

class RemoteSyncService extends Component
{
    /**
     * Return the remote database backup filenames
     * 
     * @return array An array of label/filename objects
     * @since 1.0.0
     */
    public function listDatabases(): array
    {
        $filenames = $this->list(".sql");
        $backups = $this->parseFilenames($filenames);
        $options = [];
        foreach ($backups as $i => $backup) {
            $options[$i] = [
                "label" => $backup->label,
                "value" => $backup->filename
            ];
        }
        return $options;
    }

    /**
     * Return the remote bolume backup filenames
     * 
     * @return array An array of label/filename objects
     * @since 1.0.0
     */
    public function listVolumes(): array
    {
        $filenames = $this->list('.zip');
        $backups = $this->parseFilenames($filenames);
        $options = [];
        foreach ($backups as $i => $backup) {
            $options[$i] = [
                "label" => $backup->label,
                "value" => $backup->filename
            ];
        }
        return $options;
    }

    /**
     * Create a new Remote Sync of the database
     * 
     * @return string The filename of the newly created Remote Sync
     * @since 1.0.0
     */
    public function pushDatabase()
    {
        $filename = $this->getFilename();
        $path = $this->createDatabaseDump($filename);
        $this->push($path);
        unlink($path);
        return $filename;
    }

    /**
     * Push all volumes
     * 
     * @return string The filename of the newly created Remote Sync
     * @return null If no volumes exist
     * @since 1.0.0
     */
    public function pushVolumes(): string
    {
        $filename = $this->getFilename();
        $path = $this->createVolumesZip($filename);
        $this->push($path);
        unlink($path);
        return $filename;
    }

    /**
     * Pull a remote database and restore it
     * 
     */
    public function pullDatabase($filename)
    {
        // Before pulling a database, backup the local
        $settings = RemoteSync::getInstance()->getSettings();
        if ($settings->keepEmergencyBackup) {
            $this->createDatabaseDump("emergency-backup");
        }

        $path = $this->getLocalDir() . DIRECTORY_SEPARATOR . $filename;
        $this->pull($filename, $path);
        Craft::$app->getDb()->restore($path);
        unlink($path);
    }

    /**
     * Pull remote volumes and restore them
     */
    public function pullVolume($filename)
    {
        // Before pulling volumes, backup the local
        $settings = RemoteSync::getInstance()->getSettings();
        if ($settings->keepEmergencyBackup) {
            $this->createVolumesZip("emergency-backup");
        }

        $path = $this->getLocalDir() . DIRECTORY_SEPARATOR . $filename;
        $this->pull($filename, $path);
        $this->restoreVolumesZip($path);
        unlink($path);
    }

    public function deleteDatabase($filename)
    {
        return $this->delete($filename);
    }

    public function deleteVolume($filename)
    {
        return $this->delete($filename);
    }

    private function createVolumesZip($filename): string
    {
        $path = $this->getLocalDir() . DIRECTORY_SEPARATOR . $filename . '.zip';
        if (file_exists($path)) {
            unlink($path);
        }

        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $tmpDirName = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . strtolower(StringHelper::randomString(10));

        if (count($volumes) <= 0) {
            return null;
        }

        foreach ($volumes as $volume) {
            $tmpPath = $tmpDirName . DIRECTORY_SEPARATOR . $volume->handle;
            FileHelper::copyDirectory($volume->rootPath, $tmpPath);
        }

        ZipHelper::recursiveZip($tmpDirName, $path);
        FileHelper::clearDirectory(Craft::$app->getPath()->getTempPath());
        return $path;
    }

    private function restoreVolumesZip($path)
    {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $tmpDirName = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . strtolower(StringHelper::randomString(10));

        ZipHelper::unzip($path, $tmpDirName);

        $folders = array_diff(scandir($tmpDirName), array('.', '..'));
        foreach ($folders as $folder) {
            foreach ($volumes as $volume) {
                if ($folder == $volume->handle) {
                    $dest = $tmpDirName . DIRECTORY_SEPARATOR . $folder;
                    if (!file_exists($volume->rootPath)) {
                        FileHelper::createDirectory($volume->rootPath);
                    } else {
                        FileHelper::clearDirectory($volume->rootPath);
                    }
                    FileHelper::copyDirectory($dest, $volume->rootPath);
                }
            }
        }

        FileHelper::clearDirectory(Craft::$app->getPath()->getTempPath());
    }

    private function createDatabaseDump($filename): string
    {
        $path = $this->getLocalDir() . DIRECTORY_SEPARATOR . $filename . '.sql';
        Craft::$app->getDb()->backupTo($path);
        return $path;
    }

    /**
     * Return a unique filename for a backup file
     * 
     * Based on getBackupFilePath():
     * 
     * https://github.com/craftcms/cms/tree/master/src/db/Connection.php
     * 
     * @return string The unique backup filename
     */
    private function getFilename(): string
    {
        $currentVersion = 'v' . Craft::$app->getVersion();
        $systemName = FileHelper::sanitizeFilename(Craft::$app->getInfo()->name, ['asciiOnly' => true]);
        $systemEnv = Craft::$app->env;
        $filename = ($systemName ? $systemName . '_' : '') . ($systemEnv ? $systemEnv . '_' : '') . gmdate('ymd_His') . '_' . strtolower(StringHelper::randomString(10)) . '_' . $currentVersion;
        return mb_strtolower($filename);
    }

    /**
     * Return a chronologically sorted array of Backup objects
     * 
     * @param string[] Array of filenames
     * @return array[] Array of Backup objects
     */
    private function parseFilenames($filenames): array
    {
        $backups = [];

        foreach ($filenames as $filename) {
            array_push($backups, new RemoteSyncInstance($filename));
        }

        uasort($backups, function ($b1, $b2) {
            return $b1->datetime <=> $b2->datetime;
        });

        return array_reverse($backups);
    }

    protected function getLocalDir()
    {
        $dir = Craft::$app->path->getStoragePath() . "/sync";
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * Factory method to return appropriate class depending on provider
     * setting
     * 
     * @return class The provider
     */
    public static function create($provider)
    {
        switch ($provider) {
            case "s3":
                return S3Provider::class;
                break;
        }
    }
}
