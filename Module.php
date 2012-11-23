<?php

namespace Nexus;

use Zend\ModuleManager\ModuleManager;
use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
//use Zend\ModuleManager\Feature\ConfigProviderInterface;
//use Zend\ModuleManager\Feature\ServiceProviderInterface;

class Module implements AutoloaderProviderInterface
{
    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\ClassMapAutoloader' => array(
                __DIR__ . '/autoload_classmap.php',
            ),
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    public function getConfig($env = null)
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getServiceConfig()
    {
        return array(
            'factories' => array(
                'nexus_module_options' => function ($sm) {
                    $config = $sm->get('Config');
                    return $config['nexus'];
                },
                'Generator\XmlSchema' => function ($sm) {
                    $schema = new Generator\XmlSchema($sm->get('nexus_module_options'));
                    $schema->setDbAdapter($sm->get('nexus_zend_db_adapter'));
                    return $schema;
                },
                'Generator\Model' => function ($sm) {
                    $generator = new Generator\Model($sm->get('nexus_module_options'));
                    return $generator;
                }
            )
        );
    }



}