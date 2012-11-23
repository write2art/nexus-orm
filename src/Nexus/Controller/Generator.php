<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonModule for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Nexus\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\Console\Request as ConsoleRequest;

class GeneratorController extends AbstractActionController
{
    public function indexAction()
    {
        // Make sure that we are running in a console and the user has not tricked our
        // application into running this action from a public web server.
        if (!$this->getRequest() instanceof ConsoleRequest)
            throw new \RuntimeException('You can only use this action from a console!');

        switch ($this->getRequest()->getParam('mode'))
        {
            case 'schema':
                $generator = $this->getServiceLocator()->get('Generator\XmlSchema');
                break;

            case 'models':
                $generator = $this->getServiceLocator()->get('Generator\Model');
                break;
        }

        //echo __DIR__ . "\n";

        //print_r($this->getServiceLocator()->get('nexus_module_options'));

        return $generator->generate();
    }

}