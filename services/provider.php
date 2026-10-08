<?php

/**
 * @package     Pnkr.Plugin
 * @subpackage  Content.Pnkrindexnow
 *
 * @copyright   Copyright (C) 2025 Panagiotis Kiriakopoulos. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Pnkr\Plugin\Content\Pnkrindexnow\Extension\Pnkrindexnow;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(Pnkrindexnow::class, function (Container $container) {
                $plugin = new Pnkrindexnow(
                    (array) PluginHelper::getPlugin('content', 'pnkrindexnow')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            })
        );
    }
};
