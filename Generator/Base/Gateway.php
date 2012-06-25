<?php

class Nexus_Generator_Base_Gateway
{
    protected static $instance = null;

    public function  __construct()
    {
    }

    public static function getInstance()
    {
        if (null === self::$instance)
            self::$instance = new self();

        return self::$instance;
    }

    public function row($data)
    {
        return parent::row($data);
    }

    public function generateUuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }


}
