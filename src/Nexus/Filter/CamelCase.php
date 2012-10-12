<?php

class Nexus_Filter_CamelCase implements Zend_Filter_Interface
{
    public function filter($value)
    {
        return implode('', array_map(function($n) {return ucfirst($n);}, explode('_', $value) ));
    }

}
