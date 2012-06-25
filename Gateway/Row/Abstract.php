<?php

abstract class Nexus_Gateway_Row_Abstract
{
    protected $data = array();

    protected $references = array();

    protected $dependencies = array();

    /**
     * @var Nexus_Gateway_Abstract
     */
    protected $gateway = null;

    protected $new = false;

    protected $modified = false;

    public function __construct()
    {
        $this->new = true;
        return $this;
    }

    /**
     * @method
     * @return Nexus_Gateway_Abstract
     */
    abstract public function gateway();

    public function initialize($data)
    {
        if ($data instanceof Zend_Db_Table_Row_Abstract)
            $data = $data->toArray();
        elseif (is_object($data))
            $data = (array) $data;

        if (!is_array($data))
            throw new Zend_Exception('Initial data must be an array or object');

        foreach ($data as $key => $value)
            $this->data[$key] = $value;

        $this->new = false;

        return $this;
    }

    public function isNew()
    {
        return $this->new;
    }

    public function isModified()
    {
        return $this->modified;
    }

    public function save()
    {
        foreach ($this->references as $name => $reference)
        {
            $reference->save();
            $this->{"set$name"}($reference);
            unset($this->reference[$name]);
        }

        if ($this->isModified())
        {
            $this->gateway()->save($this);
            $this->new = false;
            $this->modified = false;
        }

        foreach ($this->dependencies as $name => $dependence)
        {
            foreach ($dependence as $row)
            {
                $this->{"add$name"}($row);
                $row->save();
            }
            unset($this->dependencies[$name]);
        }

        return $this;
    }

    public function delete()
    {
        return $this->gateway()->delete($this);
    }

    public function extract()
    {
        return $this->data;
    }

    /**
     * Конвертирует объект в массив.
     * @access public
     * @return array
     */
    public function toArray()
    {
        $data = array();

        foreach ($this->data as $key => $value)
            $data[lcfirst( implode('', array_map(function($n) {return ucfirst($n);}, explode('_', $key) )) )] = $value;

        /*
        foreach ($this->relations as $key => $value)
        {

        }
        */

        return $data;
    }

    /*
    protected function underscoreToCamelCase($name)
    {
        return implode('', array_map(function($n) {return ucfirst($n);}, explode('_', $name) ));
    }
    */

}