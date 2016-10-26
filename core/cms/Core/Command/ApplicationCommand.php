<?php
/*
 +------------------------------------------------------------------------+
 | PhalconEye CMS                                                         |
 +------------------------------------------------------------------------+
 | Copyright (c) 2013-2016 PhalconEye Team (http://phalconeye.com/)       |
 +------------------------------------------------------------------------+
 | This source file is subject to the New BSD License that is bundled     |
 | with this package in the file LICENSE.txt.                             |
 |                                                                        |
 | If you did not receive a copy of the license and are unable to         |
 | obtain it through the world-wide-web, please send an email             |
 | to license@phalconeye.com so we can send you a copy immediately.       |
 +------------------------------------------------------------------------+
 | Author: Ivan Vorontsov <lantian.ivan@gmail.com>                 |
 +------------------------------------------------------------------------+
*/

namespace Core\Command;

use Core\Model\PackageModel;
use Core\Model\WidgetModel;
use Engine\Config;
use Engine\Console\AbstractCommand;
use Engine\Console\CommandInterface;
use Engine\Utils\ConsoleUtils;
use Engine\Exception;
use Engine\Package\Manager;
use Engine\Utils\StringUtils;

/**
 * Application command.
 *
 * @category  PhalconEye
 * @package   Core\Commands
 * @author    Ivan Vorontsov <lantian.ivan@gmail.com>
 * @copyright 2013-2016 PhalconEye Team
 * @license   New BSD License
 * @link      http://phalconeye.com/
 *
 * @CommandName(['application', 'app'])
 * @CommandDescription('Application management.')
 */
class ApplicationCommand extends AbstractCommand implements CommandInterface
{
    /**
     * Synchronize application data (packages metadata and database packages rows).
     *
     * @return void
     */
    public function syncAction()
    {
        try {
            /**
             * Add missing packages.
             * Read packages files and find packages that is missing in db.
             *
             * $modulesWidgets - array of widgets that is located in modules [module => [widgets...n]].
             * $notFoundWidgets - array of widgets as external packages [widgets...n].
             * $packages - all packages names found at metadata files.
             * $widgets - all widgets names found at metadata files.
             */
            list ($modulesWidgets, $notFoundWidgets, $packages, $widgets) = $this->_checkMissingPackages();

            /**
             * Add missing widgets from modules and from packages.
             */
            $this->_checkMissingWidgets($modulesWidgets, $notFoundWidgets);

            /**
             * Remove unused packages.
             */
            $this->_removeUnusedPackages($packages);

            /**
             * Remove unused widgets.
             */
            $this->_removeUnusedWidgets($widgets);

            /**
             * Generate metadata.
             */
            $manager = new Manager(PackageModel::find(), $this->getDI());
            $manager->generateMetadata(null, true);
            print ConsoleUtils::success('Application successfully synchronized.') . PHP_EOL;
        } catch (Exception $e) {
            print ConsoleUtils::error($e->getMessage()) . PHP_EOL;
        }
    }

    /**
     * Check missing packages.
     * And collect widgets data.
     *
     * @return array
     */
    protected function _checkMissingPackages()
    {
        $modulesWidgets = [];
        $notFoundWidgets = [];
        $packages = [];
        $widgets = [];

        print ConsoleUtils::head('Checking packages existence...');
        foreach (scandir(ROOT_PATH . Config::CONFIG_METADATA_PACKAGES) as $file) {
            if (!StringUtils::endsWith($file, '.json')) {
                continue;
            }

            $packageParts = explode('-', basename($file, '.json'));
            $packageInDB = $this->_getPackage($packageParts[0], $packageParts[1]);
            $packageFromManifest = $this->_package($file);
            $this->_info('Package ' . $packageParts[0] . '.' . $packageParts[1] . ': ');

            // Save widgets to check them later.
            if (
                !empty($packageFromManifest->data) &&
                !empty($packageFromManifest->data['widgets']) &&
                $packageFromManifest->type == Manager::PACKAGE_TYPE_MODULE
            ) {
                $modulesWidgets[$packageParts[1]] = $packageFromManifest->data['widgets'];
                foreach ($packageFromManifest->data['widgets'] as $widget) {
                    $widgets[] = $widget['name'];
                }
            }

            if ($packageFromManifest->type == Manager::PACKAGE_TYPE_WIDGET) {
                $notFoundWidgets[] = $packageFromManifest;
                $widgets[] = $packageFromManifest->name;
            }

            if (!$packageInDB) {
                if ($packageFromManifest->save()) {
                    $packages[] = $packageFromManifest->type . '.' . $packageFromManifest->name;
                    print ConsoleUtils::info('Created', false, 1);
                } else {
                    print ConsoleUtils::info('Failed', false, 1, ConsoleUtils::FG_RED);
                    $messages = iterator_to_array($packageFromManifest->getMessages());
                    $this->getDI()->getLogger()->error(
                        'Failed to created package "' . $packageParts[1] . '": ' .
                        implode(', ', $messages)
                    );
                }
            } else {
                $packages[] = $packageFromManifest->type . '.' . $packageFromManifest->name;
                print ConsoleUtils::info('Exists.', false, 1, ConsoleUtils::FG_GREEN);
            }
        }

        print PHP_EOL;
        return [$modulesWidgets, $notFoundWidgets, $packages, $widgets];
    }

    /**
     * Check missing widgets and add them.
     *
     * @param array $modulesWidgets  Widget modules.
     * @param array $notFoundWidgets Widgets packages that must be created.
     *
     * @return void
     */
    protected function _checkMissingWidgets($modulesWidgets, $notFoundWidgets)
    {
        print ConsoleUtils::head('Checking widgets existence...');
        foreach ($modulesWidgets as $module => $widgets) {
            foreach ($widgets as $widgetObject) {
                $this->_info('Widget ' . $module . '.' . $widgetObject['name'] . ': ');

                $widget = WidgetModel::getFirst('module = "%s" AND name = "%s"', [$module, $widgetObject['name']]);
                if (!$widget) {
                    $widget = new WidgetModel();
                    if ($widget->save($widgetObject)) {
                        print ConsoleUtils::info('Created.', false, 1);
                    } else {
                        print ConsoleUtils::info('Failed.', false, 1, ConsoleUtils::FG_RED);
                        $messages = iterator_to_array($widget->getMessages());
                        $this->getDI()->getLogger()->error(
                            'Failed to created widget "' . $module . '"."' . $widgetObject . '": ' .
                            implode(', ', $messages)
                        );
                    }
                } else {
                    print ConsoleUtils::info('Exists.', false, 1, ConsoleUtils::FG_GREEN);
                }
            }
        }

        foreach ($notFoundWidgets as $widgetObject) {
            $this->_info('Widget ' . $widgetObject->name . ': ');

            $widget = WidgetModel::findFirstByName($widgetObject->name);
            if ($widget) {
                print ConsoleUtils::info('Exists.', false, 1, ConsoleUtils::FG_GREEN);
                continue;
            }

            $widget = new WidgetModel();
            if ($widget->save($widgetObject->toArray())) {
                print ConsoleUtils::info('Created.', false, 1);
            } else {
                print ConsoleUtils::info('Failed.', false, 1, ConsoleUtils::FG_RED);
                $messages = iterator_to_array($widget->getMessages());
                $this->getDI()->getLogger()->error(
                    'Failed to created widget "' . $widgetObject . '": ' .
                    implode(', ', $messages)
                );
            }
        }

        print PHP_EOL;
    }

    /**
     * Remove packages that is not defined at metadata.
     *
     * @param array $packages Packages list.
     *
     * @return void
     */
    protected function _removeUnusedPackages($packages)
    {
        // Get packages from databases.
        print ConsoleUtils::head('Checking unused packages...');
        foreach (PackageModel::find() as $package) {
            if (!in_array($package->type . '.' . $package->name, $packages)) {
                // Check that this is not a widget that is related to module.
                if ($package->type == Manager::PACKAGE_TYPE_WIDGET && ($widget = $package->getWidget())) {
                    if (!empty($widget->module)) {
                        continue;
                    }
                }

                $this->_info('Removing unused package: ' . $package->name . PHP_EOL);
                $package->delete();
            }
        }

        print PHP_EOL;
    }

    /**
     * Remove widgets if their is unused.
     *
     * @param array $widgets Widgets list.
     *
     * @return void
     */
    protected function _removeUnusedWidgets($widgets)
    {
        // Get widgets from databases.
        print ConsoleUtils::head('Checking unused widgets...');
        foreach (WidgetModel::find() as $widget) {
            if (!in_array($widget->name, $widgets)) {
                $this->_info('Removing unused widget: ' . $widget->name . PHP_EOL);
                $widget->delete();
            }
        }

        print PHP_EOL;
    }

    /**
     * Get package.
     *
     * @param string $type Package type.
     * @param string $name Package name.
     *
     * @return PackageModel
     */
    protected function _getPackage($type, $name)
    {
        $query = $this->getDI()->get('modelsManager')->createBuilder()
            ->from(['t' => '\Core\Model\PackageModel'])
            ->where("t.type = :type: AND t.name = :name:", ['type' => $type, 'name' => $name]);

        return $query->getQuery()->execute()->getFirst();
    }

    /**
     * Create package from metadata file.
     *
     * @param string $file File path to metadata.
     *
     * @return PackageModel
     */
    protected function _package($file)
    {
        $package = new PackageModel();
        $package->fromJson(file_get_contents(ROOT_PATH . Config::CONFIG_METADATA_PACKAGES . '/' . $file));
        return $package;
    }

    /**
     * Print special info message.
     *
     * @param string $msg Info message.
     *
     * @return void
     */
    protected function _info($msg)
    {
        print ConsoleUtils::info($msg, false, 0, ConsoleUtils::FG_CYAN);
    }
}