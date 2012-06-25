<?php

/**
 * Description of Generator_DbSchema
 *
 * This Class generates raw xml schema of the existing database.
 * Class adds table to scheme only if it does not exist yet.
 *
 * @author tema
 */
class Nexus_Generator_DbSchema
{
    protected $xml;
    protected $xpath;

    public function __construct($config = null)
    {
        $this->xml = new DOMDocument('1.0', 'utf-8');
        $this->xml->preserveWhiteSpace = false;
        $this->xml->formatOutput = true;

        if ($config && file_exists($config))
            $this->xml->load($config);
        else
            $this->xml->appendChild($this->xml->createElement('database'));

        $this->xpath = new DOMXPath($this->xml);
    }

    public function generate()
    {
        $root = $this->xml->documentElement;

        foreach($this->getTables() as $value)
        {
            $tableName = array_shift(array_values($value));

            $table = new Zend_Db_Table(array('name' => $tableName));
            $tableInfo = $table->info();

            if ($this->xpath->query("//table[@name=\"$tableName\"]")->length > 0)
                $tableNode = $this->xpath->query("//table[@name=\"$tableName\"]")->item (0);
            else
            {
                $tableNode = $this->xml->createElement('table');
                $this->createNodeAttribute('name', $tableInfo['name'], $tableNode);
                $this->createNodeAttribute('phpName', $this->underscoreToCamelCase($tableInfo['name']), $tableNode);
            }

            foreach ($tableInfo['cols'] as $column)
            {
                //$xpath = new DOMXPath($tableNode);
                if ($this->xpath->query("//table[@name=\"$tableName\"]/column[@name=\"$column\"]")->length > 0)
                    continue;

                //echo $tableNode->getAttribute('name') . "\n";

                $this->generateColumnDescription($column, $tableNode, $tableInfo);
            }

            $this->generateForeignKeyDefinitions($tableNode);

            $root->appendChild($tableNode);
        }

        $this->xml->save(APPLICATION_PATH . '/configs/db_schema.xml');
    }

    protected function generateForeignKeyDefinitions(DOMElement $tableNode)
    {
        foreach ($this->getForeignKeyInfo($tableNode->getAttribute('name')) as $fk)
        {
            if ($this->xpath->query("//table[@name=\"{$tableNode->getAttribute('name')}\"]/foreign-key[@sqlName=\"{$fk['constraint_name']}\"]")->length > 0)
                continue;

            $foreignKey = $this->xml->createElement('foreign-key');
            $this->createNodeAttribute('sqlName', $fk['constraint_name'], $foreignKey);
            $this->createNodeAttribute('foreignTable', $fk['foreign_table'], $foreignKey);
            $this->createNodeAttribute('phpName', $this->underscoreToCamelCase($fk['foreign_table']), $foreignKey);
            $this->createNodeAttribute('refPhpName', $tableNode->getAttribute('phpName'), $foreignKey);

            $reference = $this->xml->createElement('reference');
            $this->createNodeAttribute('local', $fk['column_name'], $reference);
            $this->createNodeAttribute('foreign', $fk['foreign_column'], $reference);
            $foreignKey->appendChild($reference);

            $tableNode->appendChild($foreignKey);
        }
    }

    protected function generateColumnDescription($column, DOMElement $tableNode, array $tableInfo)
    {
        $columnNode = $this->xml->createElement('column');
        $this->createNodeAttribute('name', $column, $columnNode);

        if (strpos($tableInfo['metadata'][$column]['DATA_TYPE'], 'enum') === false)
            $this->createNodeAttribute('sqlType', $tableInfo['metadata'][$column]['DATA_TYPE'], $columnNode);
        else
        {
            $this->createNodeAttribute('sqlType', 'enum', $columnNode);
            $this->createNodeAttribute('enumValues', str_replace(array("enum(", "'", ")"), '', $tableInfo['metadata'][$column]['DATA_TYPE']), $columnNode);
            $this->createNodeAttribute('type', 'enum', $columnNode);
        }

        $this->setColumnType($columnNode, $tableInfo['metadata'][$column]['DATA_TYPE']);

        if ($tableInfo['metadata'][$column]['LENGTH'] !== null)
            $this->createNodeAttribute('size', $tableInfo['metadata'][$column]['LENGTH'], $columnNode);

        if ($tableInfo['metadata'][$column]['DEFAULT'] !== null)
            $this->createNodeAttribute('default', $tableInfo['metadata'][$column]['DEFAULT'], $columnNode);

        if ($tableInfo['metadata'][$column]['NULLABLE'] != 1)
            $this->createNodeAttribute('required', 'true', $columnNode);

        if (in_array($column, $tableInfo['primary']))
        {
            $this->createNodeAttribute('primaryKey', 'true', $columnNode);

            if ($tableInfo['metadata'][$column]['IDENTITY'] == 1)
                $this->createNodeAttribute('autoIncrement', 'true', $columnNode);
        }

        $columnPosition = $tableInfo['metadata'][$column]['COLUMN_POSITION'];
        $columnsCount = $tableNode->getElementsByTagName('column')->length;

        if ($columnsCount > $columnPosition && $columnPosition == 1)
            $tableNode->insertBefore($columnNode, $tableNode->getElementsByTagName('column')->item(0));
        elseif ($columnsCount >= $columnPosition)
            $tableNode->insertBefore($columnNode, $tableNode->getElementsByTagName('column')->item($columnPosition - 1));
        else
            $tableNode->appendChild($columnNode);
    }

    protected function setColumnType(DOMElement $columnNode, $sqlType)
    {
        switch($sqlType)
        {
            case 'char':
            case 'varchar':
            case 'tinytext':
            case 'text':
            case 'mediumtext':
            case 'longtext':
                $this->createNodeAttribute('type', 'string', $columnNode);
                break;

            case 'tinyint':
                $this->createNodeAttribute('type', 'boolean', $columnNode);
                break;

            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'bigint':
                $this->createNodeAttribute('type', 'integer', $columnNode);
                break;

            case 'decimal':
            case 'float':
            case 'double':
            case 'real':
                $this->createNodeAttribute('type', 'number', $columnNode);
                break;

            case 'date':
            case 'datetime':
            case 'timestamp':
            case 'time':
            case 'year':
                $this->createNodeAttribute('type', 'time', $columnNode);
                break;
        }
    }

    protected function getTables()
    {
        return Zend_Db_Table::getDefaultAdapter()->query("show tables;")->fetchAll();
    }

    protected function getForeignKeyInfo($tableName)
    {
        $sql = "SELECT `column_name`, `constraint_name`, `referenced_table_name` AS foreign_table, `referenced_column_name`  AS foreign_column
                FROM `information_schema`.`KEY_COLUMN_USAGE`
                WHERE (`constraint_schema` = SCHEMA() AND `table_name` = '$tableName' AND `referenced_column_name` IS NOT NULL)
                ORDER BY `column_name`;";

        return Zend_Db_Table::getDefaultAdapter()->query($sql)->fetchAll();
    }

    protected function createNodeAttribute($name, $value, DOMElement $node)
    {
        $nodeName = $this->xml->createAttribute($name);
        $nodeName->value = $value;
        $node->appendChild($nodeName);
    }

    protected function underscoreToCamelCase($name)
    {
        return implode('', array_map(function($n) {return ucfirst($n);}, explode('_', $name) ));
    }






}