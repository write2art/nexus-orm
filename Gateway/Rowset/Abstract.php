<?php

abstract class Nexus_Gateway_Rowset_Abstract implements SeekableIterator, Countable
{
    protected $data;

    protected $gateway;

    /**
     * Iterator pointer.
     *
     * @var integer
     */
    protected $_pointer = 0;

    /**
     * How many data rows there are.
     *
     * @var integer
     */
    protected $_count;

    public function __construct()
    {
    }

    /**
     * Метод для привязки объекта к шлюзу.
     * @access public
     * @method
     */
    public function setGateway(Nexus_Gateway_Abstract $gateway)
    {
        $this->gateway = $gateway;
        return $this;
    }

    /**
     * Метод для получения шлюза к которому привязан объект.
     * @access public
     * @return Nexus_Gateway_Abstract
     */
    public function getGateway()
    {
        return $this->gateway;
    }

    /**
     * Заполняет объект результатом записей.
     * @access public
     * @method
     */
    public function initialize($data)
    {
        $this->data = $data;
        $this->_count = count($data);
        return $this;
    }

    /**
     * Сортирует набор записей по указанному полю.
     * @access public
     * @method
     */
    public function sort($column, $direction)
    {

    }

    /**
     * Конвертирует набор записей в массив. На вход получает параметр $recursive.
     * Если true, то конвертирует в массив так же и все объекты из набора.
     * @access public
     * @method
     */
    public function toArray($recursive = false)
    {
        $result = array();
        foreach($this as $record)
        {
            if ($recursive)
                $result[] = $record->toArray(true);
            else
                $result[] = $record;
        }

        return $result;
    }


    /**
     * Rewind the Iterator to the first element.
     * Similar to the reset() function for arrays in PHP.
     * Required by interface Iterator.
     *
     * @return Nexus_Gateway_Rowset_Abstract Fluent interface.
     */
    public function rewind()
    {
        $this->_pointer = 0;
        return $this;
    }

    /**
     * Return the current element.
     * Similar to the current() function for arrays in PHP
     * Required by interface Iterator.
     *
     */
    public function current()
    {
        if ($this->valid() === false)
            return null;

        if ($this->data instanceof SeekableIterator)
        {
            $this->data->seek($this->_pointer);
            $row = $this->data->current();
        }
        else
            $row = $this->data[$this->_pointer];

        return $this->getGateway()->row($row);
    }

    /**
     * Return the identifying key of the current element.
     * Similar to the key() function for arrays in PHP.
     * Required by interface Iterator.
     *
     * @return int
     */
    public function key()
    {
        return $this->_pointer;
    }

    /**
     * Move forward to next element.
     * Similar to the next() function for arrays in PHP.
     * Required by interface Iterator.
     *
     * @return void
     */
    public function next()
    {
        ++$this->_pointer;
    }

    /**
     * Check if there is a current element after calls to rewind() or next().
     * Used to check if we've iterated to the end of the collection.
     * Required by interface Iterator.
     *
     * @return bool False if there's nothing more to iterate over
     */
    public function valid()
    {
        return $this->_pointer >= 0 && $this->_pointer < $this->_count;
    }

    /**
     * Returns the number of elements in the collection.
     *
     * Implements Countable::count()
     *
     * @return int
     */
    public function count()
    {
        return $this->_count;
    }

    /**
     * Take the Iterator to position $position
     * Required by interface SeekableIterator.
     *
     * @param int $position the position to seek to
     * @return Nexus_Gateway_Rowset_Abstract
     * @throws Zend_Db_Table_Rowset_Exception
     */
    public function seek($position)
    {
        $position = (int) $position;
        if ($position < 0 || $position >= $this->count())
            throw new Zend_Db_Table_Rowset_Exception("Illegal index $position");

        $this->_pointer = $position;
        return $this;
    }


}