<?php
return array(
    'controllers' => array(
        'invokables' => array(
            'Nexus\Generator' => 'Nexus\Controller\GeneratorController',
        ),
    ),
    'service_manager' => array(
        'aliases' => array(
            'nexus_zend_db_adapter' => 'Zend\Db\Adapter\Adapter',
        ),
    ),
    'console' => array(
        'router' => array(
            'routes' => array(
                'nexus-generator' => array(
                    'options' => array(
                        'route' => 'nexus generate (module|schema|models):mode',
                        'defaults' => array(
                            'controller' => 'Nexus\Generator',
                            'action' => 'index'
                        )
                    )
                )
            )
        )
    ),
);