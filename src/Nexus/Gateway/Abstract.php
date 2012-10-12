<?php

abstract class Nexus_Gateway_Abstract
{
    const FETCH_ARRAY = 'fetch_array';
    const FETCH_OBJECT = 'fetch_object';

    /**
     * Класс для взаимодействия с таблицей в базе данных.
     * @access protected
     * @var Zend_Db_Table_Abstract
     */
    protected $table = null;

    /**
     * Первичный ключ таблицы.
     * @access protected
     * @var scalar | array
     */
    protected $primary;

    /**
     * Первичный ключ использует autoIncrement.
     * @access protected
     * @var scalar | array
     */
    protected $autoIncrement = true;

    /**
     * Класс для работы с одной записью из таблицы
     * @var string
     */
    protected $rowClass;

    /**
     * Класс для набора записей
     * @var string
     */
    protected $rowsetClass = 'Nexus_Gateway_Rowset';

    public function  __construct()
    {
    }

    abstract public function setTable(Zend_Db_Table_Abstract $table);
    /**
     * @return Zend_Db_Table_Abstract
     */
    abstract public function getTable();

    /**
     *
     * @param type $data
     * @return Nexus_Gateway_Row_Abstract
     */
    public function row($data)
    {
        $row = new $this->rowClass();
        $row->initialize($data);

        return $row;
    }

    /**
     *
     * @param type $data
     * @return Nexus_Gateway_Rowset_Abstract
     */
    public function rowset($data)
    {
        $rowset = new $this->rowsetClass();
        $rowset->setGateway($this)
               ->initialize($data);

        return $rowset;
    }

    /**
     * Метод достаёт из базы объект или объекты по первичному ключу. Принимает на вход один или несколько аргументов.
     * Каждый аргумент интерпретируется как primary key. Каждый аргумент сравнивается по типу с переменной $this->primary.
     * Если аргумент имеет тип отличный от типа свойства $this->primary, будет брошено исключение.
     * Если аргумент один, метод вернёт одну запись или null. Если аргументов несколько, метод вернёт набор записей, даже если
     * будет найдена только одна запись.
     *
     * @access public
     * @param array | int | string
     */
    public function findPK()
    {
        $args = func_get_args();

        if (count($args) == 0)
            return null;

        $keyNames = array_values((array) $this->primary);

        $findCallArgs = array();
        foreach ($args as $key => $argument)
        {
            for ($i = 0; $i < count((array) $argument); $i++)
                $findCallArgs[$i][] = is_array($argument) ? $argument[$i] : $argument;
        }

        $result = call_user_func_array(array($this->getTable(), "find"), $findCallArgs);

        return count($args) > 1 ? $this->rowset($result) : count($result) > 0 ? $this->row($result->current()) : null;
    }

    /**
     * Метод прнимает на вход объект запроса к базе и возврщает все найденные записи
     *
     * @access public
     * @param Zend_Db_Select $select
     */
    public function find(Zend_Db_Select $select = null, $fetchMode = self::FETCH_OBJECT)
    {
        switch ($fetchMode)
        {
            case self::FETCH_ARRAY:
                $result = $this->getTable()->getAdapter()->query($select)->fetchAll();
                break;

            case self::FETCH_OBJECT:
            default:
                $result = $this->rowset($this->getTable()->getAdapter()->fetchAll($select));
        }

        return $result;
    }

    /**
     * Метод принимает на вход объект запроса к базе ланных и возвращает число записей.
     * @param Zend_Db_Select $select
     * @return int
     */
    public function count(Zend_Db_Select $select)
    {
        $select->reset('columns')
               ->columns(array("COUNT(*)"));

        return $this->getTable()->getAdapter()->query($select)->fetchColumn();
    }

    /**
     * Метод принимает на вход объект запроса к базе и массив с новыми значениями. Возвращает число строк, которые были обновлены.
     * Если в массиве с новыми значениями присутствуют поля которых нет у объекта, будет брошено исключение.
     */
    public function update()
    {

    }

    /**
     * Метод удаляет из базы все записи удовлетворяющие заданным критериям.
     * На вход получает объект запроса к базе данных или объект который необходимо удалить.
     * Возвращает число строк которые были удалены.
     * @param Zend_Db_Select | Common_Dao_Row_Abstract
     * @return int
     */
    public function delete($data)
    {
        if ($data instanceof Zend_Db_Select)
        {
            if (count($data->getPart('union')) > 0)
                throw new Zend_Exception('Deletion from joined tables is disabled right now. Simplify your query and try again');

            $where = implode(' ', $data->getPart('where'));
        }
        else if ($data instanceof Nexus_Gateway_Row_Abstract)
        {
            $db = $this->getTable()->getAdapter();
            $quote = function($key, $value) use ($db) {
                return $db->quoteInto("{$db->quoteIdentifier($key)} = ?", $value);
            };

            $rowData = $data->extract();
            if (is_array($this->primary))
            {
                $where = array();
                foreach ($this->primary as $key)
                    $where[] = $quote($key, $rowData[$key]);
            }
            else
                $where = $quote($this->primary, $rowData[$this->primary]);

        }
        else
            throw new Zend_Exception ("Object must be Select or Row");

        //print_r($where);

        return $this->getTable()->delete($where);
    }

    /**
     * Метод принимает на вход объект для сохранения и записывает его в базу.
     */
    public function save(Nexus_Gateway_Row_Abstract $row)
    {
        $data = $row->extract();

        $searchKey = array();
        if (is_array($this->primary))
            foreach ($this->primary as $value)
                $searchKey[] = $data[$value];
        else
            $searchKey[] = $data[$this->primary];

        $record = call_user_func_array(array($this->getTable(), "find"), $searchKey)->current();

        if ($record)
        {
            foreach ($data as $key => $value)
                $record->$key = $value;

            $record->save();
        }
        else
        {
            $this->getTable()->insert($data);

            if ($this->autoIncrement)
            {
                $data[$this->primary] = $this->getTable()->getAdapter()->lastInsertId();
                $row->initialize($data);
            }

        }

        return $this;
    }

}