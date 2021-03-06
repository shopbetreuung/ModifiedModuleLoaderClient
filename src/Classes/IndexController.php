<?php

/*
 * This file is part of MMLC - ModifiedModuleLoaderClient.
 *
 * (c) Robin Wieschendorf <mail@robinwieschendorf.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RobinTheHood\ModifiedModuleLoaderClient;

use RobinTheHood\ModifiedModuleLoaderClient\Helpers\ArrayHelper;
use RobinTheHood\ModifiedModuleLoaderClient\Loader\ModuleLoader;
use RobinTheHood\ModifiedModuleLoaderClient\Loader\LocalModuleLoader;
use RobinTheHood\ModifiedModuleLoaderClient\Loader\RemoteModuleLoader;
use RobinTheHood\ModifiedModuleLoaderClient\Semver\Comparator;
use RobinTheHood\ModifiedModuleLoaderClient\Semver\Parser;
use RobinTheHood\ModifiedModuleLoaderClient\Redirect;
use RobinTheHood\ModifiedModuleLoaderClient\ModuleFilter;
use RobinTheHood\ModifiedModuleLoaderClient\ModuleSorter;
use RobinTheHood\ModifiedModuleLoaderClient\Category;
use RobinTheHood\ModifiedModuleLoaderClient\SendMail;

class IndexController
{
    const REQUIRED_PHP_VERSION = '7.1.12';

    public function invoke()
    {
        $this->invokeDefault();

        $action = ArrayHelper::getIfSet($_GET, 'action', '');

        switch ($action) {
            case 'moduleInfo':
                $this->invokeModuleInfo();
                break;

            case 'lazyModuleInfo':
                $this->invokeLazyModuleInfo();
                break;
            
            case 'lazyModuleUpdateCount':
                $this->invokeLazyModuleUpdateCount();
                break;

            case 'lazyModuleChangeCount':
                $this->invokeLazyModuleChangeCount();
                break;

            case 'lazySystemUpdateCount':
                $this->invokeLazySystemUpdateCount();
                break;

            case 'install':
                $this->invokeInstall();
                break;

            case 'update':
                $this->invokeUpdate();
                break;

            case 'uninstall':
                $this->invokeUninstall();
                break;

            case 'loadRemoteModule':
                $this->invokeLoadRemoteModule();
                break;

            case 'loadAndInstall':
                $this->invokeLoadAndInstall();
                break;

            case 'unloadLocalModule':
                $this->invokeUnloadLocalModule();
                break;

            case 'signIn':
                $this->invokeSignIn();
                break;

            case 'signOut':
                $this->invokeSignOut();
                break;

            case 'selfUpdate':
                $this->invokeSelfUpdate();
                break;

            case 'reportProblem':
                $this->invokeReportProblem();
                break;

            default:
                $this->invokeIndex();
                break;
        }
    }

    public function invokeDefault()
    {
        if (session_status() != PHP_SESSION_ACTIVE) {
            session_start();
        }

        $accessFileCreator = new AccessFileCreator();
        $accessFileCreator->renewAccessFiles();

        if (!ini_get('allow_url_fopen')) {
            Notification::pushFlashMessage([
                'text' => 'Warnung: Keine Verbindung zum Server. <strong>allow_url_fopen</strong> ist in der php.ini deaktiviert.',
                'type' => 'warning'
            ]);
        }

        if (version_compare(PHP_VERSION, self::REQUIRED_PHP_VERSION, '<')) {
            Notification::pushFlashMessage([
                'text' => 'Warnung: Die PHP Version ' . PHP_VERSION . ' wird nicht unterstützt. Der MMLC benötigt ' . self::REQUIRED_PHP_VERSION . ' oder höher.',
                'type' => 'warning'
            ]);
        }
    }

    public function invokeSignIn()
    {
        global $configuration;
        if (session_status() != PHP_SESSION_ACTIVE) {
            session_start();
        }

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $error = '';

            if ($_POST['username'] != $configuration['username']) {
                $error = 'Unbekannter Username';
            } elseif (!password_verify($_POST['password'], $configuration['password'])) {
                $error = 'Falsches passwort';
            }

            if (!$error) {
                $_SESSION['accessRight'] = true;
                Redirect::redirect('/');
            } else {
                $_SESSION['accessRight'] = false;
            }

        }

        include App::getTemplatesRoot() . '/SignIn.tmpl.php';
    }

    public function invokeSignOut()
    {
        if (session_status() != PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['accessRight'] = false;
        Redirect::redirect('/?action=signIn');
    }

    public function invokeSelfUpdate()
    {
        $selfUpdater = new SelfUpdater();
        $installedVersion = $selfUpdater->getInstalledVersion();
        $version = $selfUpdater->getNewestVersionInfo();

        $installVersion = ArrayHelper::getIfSet($_GET, 'install', '');
        if ($installVersion) {
            $selfUpdater->update($installVersion);
            Redirect::redirect('/?action=selfUpdate');
        }

        // Postupdate ausführen, falls erforderlich
        $executed = $selfUpdater->checkAndDoPostUpdate();

        // Wenn der Postupdate durchgeführt werden musste, die Seite noch einmal
        // automatisch neu laden
        if ($executed) {
            Redirect::redirect('/?action=selfUpdate');
        }

        $checkUpdate = $selfUpdater->checkUpdate();
        
        $comparator = new Comparator(new Parser);
        include App::getTemplatesRoot() . '/SelfUpdate.tmpl.php';
    }

    public function invokeIndex()
    {
        $this->checkAccessRight();

        $moduleLoader = ModuleLoader::getModuleLoader();
        $modules = $moduleLoader->loadAllVersionsWithLatestRemote();
        $modules = ModuleFilter::filterNewestOrInstalledVersion($modules);

        $filterModules = ArrayHelper::getIfSet($_GET, 'filterModules', '');
        if ($filterModules == 'loaded') {
            $modules = ModuleFilter::filterLoaded($modules);
        } elseif($filterModules == 'installed') {
            $modules = ModuleFilter::filterInstalled($modules);
        } elseif($filterModules == 'updatable') {
            $modules = ModuleFilter::filterUpdatable($modules);
        } elseif($filterModules == 'changed') {
            $modules = ModuleFilter::filterRepairable($modules);
        } elseif($filterModules == 'notloaded') {
            $modules = ModuleFilter::filterNotLoaded($modules);
        }

        $modules = ModuleSorter::sortByArchiveName($modules);
        $groupedModules = Category::groupByCategory($modules);

        include App::getTemplatesRoot() . '/ModuleListing.tmpl.php';
    }

    public function invokeModuleInfo()
    {
        $archiveName = ArrayHelper::getIfSet($_GET, 'archiveName', null);
        $version = ArrayHelper::getIfSet($_GET, 'version', null);

        if ($version) {
            $moduleLoader = ModuleLoader::getModuleLoader();
            $module = $moduleLoader->loadByArchiveNameAndVersion($archiveName, $version);
        } else {
            $moduleLoader = ModuleLoader::getModuleLoader();
            $modules = $moduleLoader->loadAllVersionsByArchiveNameWithLatestRemote($archiveName);
            $module = ModuleFilter::getLatestVersion($modules);
        }

        if (!$module) {
            $this->addModuleNotFoundNotification($archiveName, $version);
            Redirect::redirect('/');
        }

        include App::getTemplatesRoot() . '/ModuleInfo.tmpl.php';
    }

    public function calcModuleUpdateCount()
    {
        $moduleLoader = LocalModuleLoader::getModuleLoader();
        $modules = $moduleLoader->loadAllVersions();
        $modules = ModuleFilter::filterInstalled($modules);
        return count(ModuleFilter::filterUpdatable($modules));
    }

    public function calcModuleChangeCount()
    {
        $moduleLoader = LocalModuleLoader::getModuleLoader();
        $modules = $moduleLoader->loadAllVersions();
        return count(ModuleFilter::filterRepairable($modules));
    }

    public function calcSystemUpdateCount()
    {
        $selfUpdater = new SelfUpdater();
        $checkUpdate = $selfUpdater->checkUpdate();
        if ($checkUpdate) {
            return 1;
        }
        return 0;
    }

    public function invokeLazyModuleInfo()
    {
        $archiveName = ArrayHelper::getIfSet($_GET, 'archiveName', null);
        $version = ArrayHelper::getIfSet($_GET, 'version', null);
        $data = ArrayHelper::getIfSet($_GET, 'data', null);

        $moduleLoader = ModuleLoader::getModuleLoader();
        $module = $moduleLoader->loadByArchiveNameAndVersion($archiveName, $version);

        if ($data == 'installationMd') {
            echo $module->getInstallationMd();
        } elseif ($data == 'usageMd') {
            echo $module->getUsageMd();
        } elseif ($data == 'changelogMd') {
            echo $module->getChangeLogMd();
        }
    }

    public function invokeLazyModuleUpdateCount()
    {
        $value = $this->calcModuleUpdateCount();
        if ($value) {
            echo $value;
        }
        die();
    }

    public function invokeLazyModuleChangeCount()
    {
        $value = $this->calcModuleChangeCount();
        if ($value) {
            echo $value;
        }
        die();
    }

    public function invokeLazySystemUpdateCount()
    {
        $value = $this->calcSystemUpdateCount();
        if ($value) {
            echo $value;
        }
        die();
    }

    public function invokeInstall()
    {
        $this->checkAccessRight();

        $archiveName = ArrayHelper::getIfSet($_GET, 'archiveName', '');
        $version = ArrayHelper::getIfSet($_GET, 'version', '');

        $moduleLoader = new LocalModuleLoader();
        $module = $moduleLoader->loadByArchiveNameAndVersion($archiveName, $version);

        if (!$module) {
            $this->addModuleNotFoundNotification($archiveName, $version);
            Redirect::redirect('/');
        }

        $moduleInstaller = new ModuleInstaller();
        //$moduleInstaller->install($module);
        //$moduleInstaller->installDependencies($module);
        $moduleInstaller->installWithDependencies($module);

        $this->redirectRef($archiveName, $module->getVersion());
    }

    public function invokeUninstall()
    {
        $this->checkAccessRight();

        $archiveName = ArrayHelper::getIfSet($_GET, 'archiveName', '');
        $version = ArrayHelper::getIfSet($_GET, 'version', '');

        $moduleLoader = new LocalModuleLoader();
        $module = $moduleLoader->loadByArchiveNameAndVersion($archiveName, $version);

        if (!$module) {
            $this->addModuleNotFoundNotification($archiveName, $version);
            Redirect::redirect('/');
        }

        $moduleInstaller = new ModuleInstaller();
        $moduleInstaller->uninstall($module);

        $this->redirectRef($archiveName, $module->getVersion());
    }

    public function invokeUpdate()
    {
        $archiveName = ArrayHelper::getIfSet($_GET, 'archiveName', '');
        $version = ArrayHelper::getIfSet($_GET, 'version', '');

        $moduleLoader = new LocalModuleLoader();
        $module = $moduleLoader->loadByArchiveNameAndVersion($archiveName, $version);

        if (!$module) {
            $this->addModuleNotFoundNotification($archiveName, $version);
            Redirect::redirect('/');
        }

        $moduleInstaller = new ModuleInstaller();
        $newModule = $moduleInstaller->updateWithDependencies($module);

        if (!$newModule) {
            $newestModule = $module->getNewestVersion();
            $this->addModuleNotFoundNotification($archiveName, $newestModule->getVersion());
            Redirect::redirect('/');
        }

        $this->redirectRef($archiveName, $newModule->getVersion());
    }

    public function invokeLoadRemoteModule()
    {
        $this->checkAccessRight();

        $archiveName = ArrayHelper::getIfSet($_GET, 'archiveName', '');
        $version = ArrayHelper::getIfSet($_GET, 'version', '');

        $moduleLoader = RemoteModuleLoader::getModuleLoader();
        $module = $moduleLoader->loadByArchiveNameAndVersion($archiveName, $version);

        if (!$module) {
            $this->addModuleNotFoundNotification($archiveName, $version);
            Redirect::redirect('/');
        }

        $moduleInstaller = new ModuleInstaller();
        if (!$moduleInstaller->pull($module)) {
            Notification::pushFlashMessage([
                'text' => "Fehler: Das Module <strong>$archiveName - $version</strong> konnte nicht geladen werden.",
                'type' => 'error'
            ]);
        }


        $this->redirectRef($archiveName, $module->getVersion());
    }

    public function invokeLoadAndInstall()
    {
        $this->checkAccessRight();

        $archiveName = ArrayHelper::getIfSet($_GET, 'archiveName', '');
        $version = ArrayHelper::getIfSet($_GET, 'version', '');

        $moduleLoader = RemoteModuleLoader::getModuleLoader();
        $module = $moduleLoader->loadByArchiveNameAndVersion($archiveName, $version);

        if (!$module) {
            $this->addModuleNotFoundNotification($archiveName, $version);
            Redirect::redirect('/');
        }

        $moduleInstaller = new ModuleInstaller();
        if (!$moduleInstaller->pull($module)) {
            Notification::pushFlashMessage([
                'text' => "Fehler: Das Module <strong>$archiveName - $version</strong> konnte nicht geladen werden.",
                'type' => 'error'
            ]);
            Redirect::redirect('/');
        }

        $moduleLoader = new LocalModuleLoader();
        $module = $moduleLoader->loadByArchiveNameAndVersion($archiveName, $version);

        if (!$module) {
            $this->addModuleNotFoundNotification($archiveName, $version);
            Redirect::redirect('/');
        }

        $moduleInstaller = new ModuleInstaller();
        $moduleInstaller->install($module);
        $moduleInstaller->installDependencies($module);

        $this->redirectRef($archiveName, $module->getVersion());
    }

    public function invokeUnloadLocalModule()
    {
        $this->checkAccessRight();

        $archiveName = ArrayHelper::getIfSet($_GET, 'archiveName', '');
        $version = ArrayHelper::getIfSet($_GET, 'version', '');

        $moduleLoader = new LocalModuleLoader();
        $module = $moduleLoader->loadByArchiveNameAndVersion($archiveName, $version);

        if (!$module) {
            $this->addModuleNotFoundNotification($archiveName, $version);
            Redirect::redirect('/');
        }

        $moduleInstaller = new ModuleInstaller();
        $moduleInstaller->delete($module);

        Redirect::redirect('/');
    }

    public function invokeReportProblem() 
    {
        $this->checkAccessRight();
        if (isset($_POST['send_mail'])) {
            SendMail::sendFeedback();
        } 
        include App::getTemplatesRoot() . '/ReportProblem.tmpl.php';
    }

    public function checkAccessRight()
    {
        if (session_status() != PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['accessRight']) || $_SESSION['accessRight'] !== true) {
            Redirect::redirect('/?action=signIn');
        }
    }

    public function redirectRef($archiveName, $version = '')
    {
        $ref = ArrayHelper::getIfSet($_GET, 'ref', '');

        if ($ref == 'moduleInfo') {
            $url = '/?action=moduleInfo&archiveName=' . $archiveName;
            if ($version) {
                $url .= '&version=' . $version;
            }
        } else {
            $url = '/';
        }

        Redirect::redirect($url);
    }

    private function addModuleNotFoundNotification($archiveName, $version = '')
    {
        Notification::pushFlashMessage([
            'text' => "Fehler: Das Module <strong>$archiveName - $version</strong> wurde nicht gefunden.",
            'type' => 'error'
        ]);
    }
}
