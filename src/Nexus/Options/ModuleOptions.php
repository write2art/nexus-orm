<?php

namespace Nexus\Options;

use Zend\Stdlib\AbstractOptions;

class ModuleOptions extends AbstractOptions
{
    /**
     * Turn off strict options mode
     */
    protected $__strictMode__ = false;

    /**
     * @var bool
     */
    protected $schemaOutputPath = 'so';

    /**
     * set schema generation output path
     *
     * @param string $path
     * @return ModuleOptions
     */
    public function setSchemaOutputPath($path)
    {
        $this->schemaOutputPath = $path;
        return $this;
    }

    /**
     * get schema generation output path
     *
     * @return string
     */
    public function getSchemaOutputPath()
    {
        return $this->schemaOutputPath;
    }

}