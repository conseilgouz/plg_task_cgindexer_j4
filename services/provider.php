<?php

/**
/** Plugin Task CG Indexer : indexation des contenus à indexer dans la recherche avancée
 * Version			: 1.0.3
 * copyright 		: Copyright (C) 2023 ConseilGouz. All rights reserved.
 * license    		: http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Http\HttpFactory;
use ConseilGouz\Plugin\Task\CGIndexer\Extension\CGIndexer;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   4.2.0
     */
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new CGIndexer(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('task', 'cgindexer')
                );
                $plugin->setApplication(Factory::getApplication());
                return $plugin;
            }
        );
    }
};
