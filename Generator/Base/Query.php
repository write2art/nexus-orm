<?php

class Nexus_Generator_Base_Query
{
    public static function create(Zend_Db_Select $select = null, Nexus_Query_Abstract $externalUser = null)
    {
        return new self($select, $externalUser);
    }





}
