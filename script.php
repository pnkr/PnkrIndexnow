<?php

/**
 * @package     Pnkr.Plugin
 * @subpackage  Content.Pnkrindexnow
 *
 * @copyright   Copyright (C) 2025 Panagiotis Kiriakopoulos. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the installer script with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class ($container->get(AdministratorApplication::class)) implements InstallerScriptInterface {
                /**
                 * Minimum Joomla version
                 *
                 * @var    string
                 * @since  1.2.0
                 */
                private const MINIMUM_JOOMLA = '6.0.0';

                /**
                 * Minimum PHP version
                 *
                 * @var    string
                 * @since  1.2.0
                 */
                private const MINIMUM_PHP = '8.3.0';

                /**
                 * @param   AdministratorApplication  $app  The application
                 *
                 * @since   1.2.0
                 */
                public function __construct(private AdministratorApplication $app)
                {
                }

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                /**
                 * Abort the installation on unsupported Joomla or PHP versions
                 *
                 * @param   string            $type     The action being performed
                 * @param   InstallerAdapter  $adapter  The adapter calling this method
                 *
                 * @return  bool
                 *
                 * @since   1.2.0
                 */
                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
                        $this->app->enqueueMessage(Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', self::MINIMUM_PHP), 'error');

                        return false;
                    }

                    if (version_compare(JVERSION, self::MINIMUM_JOOMLA, '<')) {
                        $this->app->enqueueMessage(Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', self::MINIMUM_JOOMLA), 'error');

                        return false;
                    }

                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    return true;
                }
            }
        );
    }
};
