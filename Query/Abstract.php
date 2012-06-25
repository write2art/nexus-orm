<?php

abstract class Nexus_Query_Abstract
{
    /**
     * Объект запроса к базе
     * @access protected
     * @var Zend_Db_Select
     */
    protected $select;

    /**
     * @access protected
     * @var Nexus_Gateway_Abstract
     */
    protected $gateway;

    /**
     * Имя таблицы в базе данных с которой работает этот объект запроса
     * @access protected
     * @var string
     */
    protected $tableName;

    /**
     *
     * @access protected
     * @var Zend_Db_Adapter_Abstract
     */
    protected $db;

    /**
     * Список родственных отношений
     * @access protected
     * @var array
     */
    protected $referenceMap = array();

    /**
     * Ссылка на объект который вызвал этот
     * @var Nexus_Query_Abstract
     */
    protected $externalUser = null;


    public function __construct(Zend_Db_Select $select = null, Nexus_Query_Abstract $externalUser = null)
    {
        $this->db = $this->getGateway()->getTable()->getAdapter();
        //$this->tableName = $this->getGateway()->getTable()->info('name');
        $this->select = $select ? : $this->getGateway()->getTable()->select()->from($this->tableName);
        $this->externalUser = $externalUser ? : null;
    }

    /**
     * @return Nexus_Gateway_Abstract
     */
    abstract protected function getGateway();

    public function find()
    {
        return $this->getGateway()->find($this->select);
    }

    public function findDistinct()
    {
        $select = clone $this->select->distinct();
        return $this->getGateway()->find($select);
    }

    public function findPk()
    {
        return call_user_func_array(array($this->getGateway(), "findPK"), func_get_args());
    }

    public function count()
    {
        return $this->getGateway()->count($this->select);
    }

    public function delete()
    {
        return $this->getGateway()->delete($this->select);
    }

    protected function stringFilter($key, $value)
    {
        if (is_array($value) && !(array_key_exists('min', $value) || array_key_exists('max', $value)))
            $this->select->where($this->applyInFilter($key, $value));
        if (is_string($value) && strpos($value, '%') !== false)
            $this->select->where($this->applyLikeFilter($key, $value));
        if (!is_array($value) && strpos($value, '%') === false)
            $this->select->where($this->applyDefaultFilter($key, $value));
    }

    protected function booleanFilter($key, $value)
    {
        if (in_array($value, array('yes', 1, true, 'on')))
            $value = 1;
        elseif (in_array($value, array('no', 0, false, 'off', 'not')))
            $value = 0;

        $this->select->where($this->applyDefaultFilter($key, $value));
    }

    protected function integerFilter($key, $value)
    {
        $this->numberFilter($key, $value);
    }

    protected function numberFilter($key, $value)
    {
        if (is_array($value) && (array_key_exists('min', $value) || array_key_exists('max', $value)) )
            $this->select->where($this->applyMinMaxFilter($key, $value));
        if (is_array($value) && !(array_key_exists('min', $value) || array_key_exists('max', $value)))
            $this->select->where($this->applyInFilter($key, $value));
        if (!is_array($value) && strpos($value, '%') === false)
            $this->select->where($this->applyDefaultFilter($key, $value));
    }

    protected function timeFilter($key, $value)
    {
        if (is_array($value) && (array_key_exists('min', $value) || array_key_exists('max', $value)))
            $this->select->where($this->applyMinMaxFilter($key, $value));
        if (!is_array($value) && strpos($value, '%') === false)
            $this->select->where($this->applyDefaultFilter($key, $value));
    }

    protected function enumFilter($key, $value)
    {
        if (is_array($value) && !(array_key_exists('min', $value) || array_key_exists('max', $value)))
            $this->select->where($this->applyInFilter($key, $value));
        if (!is_array($value) && strpos($value, '%') === false)
            $this->select->where($this->applyDefaultFilter($key, $value));
    }

    protected function applyDefaultFilter($key, $value)
    {
        if ($value === null || 'null' == strtolower($value) || 'is null' == strtolower($value))
            $where = "{$this->db->quoteIdentifier("{$this->tableName}.{$key}")} IS NULL";
        elseif ('is not null' == strtolower($value) || 'not null' == strtolower($value))
            $where = "{$this->db->quoteIdentifier("{$this->tableName}.{$key}")} IS NOT NULL";
        else
            $where = $this->db->quoteInto("{$this->db->quoteIdentifier("{$this->tableName}.{$key}")} = ?", $value);

        return $where;
    }

    protected function applyInFilter($key, $values)
    {
        $in = array();
        foreach ($values as $value)
            $in[] = $this->db->quote($value);

        return "{$this->db->quoteIdentifier("{$this->tableName}.{$key}")} IN (" . implode(', ', $in) . ")";
    }

    protected function applyMinMaxFilter($key, $range)
    {
        $where = array();
        if (array_key_exists('min', $range))
            $where[] = $this->db->quoteInto("{$this->db->quoteIdentifier("{$this->tableName}.{$key}")} > ?", $range['min']);
        if (array_key_exists('max', $range))
            $where[] = $this->db->quoteInto("{$this->db->quoteIdentifier("{$this->tableName}.{$key}")} < ?", $range['max']);

        return implode(" AND ", $where);
    }

    protected function applyLikeFilter($key, $value)
    {
        return $this->db->quoteInto("{$this->db->quoteIdentifier("{$this->tableName}.{$key}")} LIKE ?", $value);
    }

    public function endUse()
    {
        if ($this->externalUser === null)
            return $this;

        return $this->externalUser;
    }

    protected function isJoined($tableName)
    {
        foreach ($this->select->getPart('from') as $table => $config)
            if ($table == $tableName)
                return true;

        return false;
    }

    public function distinct($flag = true)
    {
        $this->select->distinct($flag);
        return $this;
    }

    public function sql()
    {
        return $this->select->assemble();
    }


}
