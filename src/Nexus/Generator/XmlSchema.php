<?php

namespace Nexus\Generator;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Metadata\Metadata;

/**
 * Description of Generator XmlSchema
 *
 * This Class generates raw xml schema of the existing database.
 * Class adds table to scheme only if it does not exist yet.
 *
 * @author tema
 */
class XmlSchema
{
    protected $config = null;

    protected $adapter = null;

    protected $xml;
    protected $xpath;

    public function __construct($config = null)
    {
        $this->config = $config;

        $this->xml = new \DOMDocument('1.0', 'utf-8');
        $this->xml->preserveWhiteSpace = false;
        $this->xml->formatOutput = true;

        if (isset($config['output_schema_path']) && file_exists($config['output_schema_path']))
        {
            fwrite(STDOUT, "Config found. Updating if nessasary.\n");
            $this->xml->load($config['output_schema_path']);
        }
        else
        {
            fwrite(STDOUT, "Config not found. Generating from scratch.\n");
            $this->xml->appendChild($this->xml->createElement('database'));
        }

        $this->xpath = new \DOMXPath($this->xml);
    }

    public function setDbAdapter(Adapter $adapter)
    {
        $this->adapter = $adapter;
        return $this;
    }

    public function getDbAdapter()
    {
        return $this->adapter;
    }

    public function generate()
    {
        $metadata = new Metadata($this->getDbAdapter());

        fwrite(STDOUT, "ACTION:\t\tTYPE:\t\tNAME: \n");

        $root = $this->xml->documentElement;
        foreach($metadata->getTableNames() as $tableName)
        {
            $tableInfo = $metadata->getTable($tableName);

            $constraints = $metadata->getConstraints($tableName);
            $triggers = $metadata->getTriggers();

            //print_r($constraints);
            //print_r($triggers);

            if ($this->xpath->query("//table[@name=\"$tableName\"]")->length > 0)
                $tableNode = $this->xpath->query("//table[@name=\"$tableName\"]")->item (0);
            else
            {
                fwrite(STDOUT, "added\t\ttable\t\t$tableName\n");
                $tableNode = $this->xml->createElement('table');
                $this->createNodeAttribute('name', $tableName, $tableNode);
                $this->createNodeAttribute('phpName', $this->underscoreToCamelCase($tableName), $tableNode);
            }

            foreach ($tableInfo->getColumns() as $column)
            {
                if ($this->xpath->query("//table[@name=\"$tableName\"]/column[@name=\"{$column->getName()}\"]")->length > 0)
                    continue;

                fwrite(STDOUT, "added\t\tcolumn\t\t$tableName.{$column->getName()} \n");
                $this->generateColumnDescription($column, $tableNode, $constraints);
            }

            foreach ($constraints as $constraint)
            {
                if ($constraint->isForeignKey())
                    $this->generateForeignKeyDefinition($tableNode, $constraint);
            }

            $root->appendChild($tableNode);
        }

        $this->detectManyToManyRelations();

        $this->xml->save($this->config['output_schema_path']);

        return "Done! Xml schema saved to {$this->config['output_schema_path']}\n";;
    }

    protected function generateColumnDescription($column, \DOMElement $tableNode, $constraints)
    {
        $columnNode = $this->xml->createElement('column');

        $this->createNodeAttribute('name', $column->getName(), $columnNode);
        $this->createNodeAttribute('sqlType', $column->getDataType(), $columnNode);
        $this->setColumnType($columnNode, $column->getDataType());

        if ($column->getErrata('permitted_values'))
            $this->createNodeAttribute('allowedValues', implode(',', $column->getErrata('permitted_values')), $columnNode);

        if ($column->getColumnDefault() !== null)
            $this->createNodeAttribute('default', $column->getColumnDefault(), $columnNode);

        if ($column->getCharacterMaximumLength() !== null)
            $this->createNodeAttribute('size', $column->getCharacterMaximumLength(), $columnNode);

        if (!$column->isNullable())
            $this->createNodeAttribute('required', 'true', $columnNode);

        foreach ($constraints as $constraint)
        {
            if ($constraint->isPrimaryKey())
            {
                if (in_array($column->getName(), $constraint->getColumns()))
                {
                    $this->createNodeAttribute('primaryKey', 'true', $columnNode);

                    /* TODO: как-то понимать autoIncrement колонка или нет. Ниже закомменитирован старый способ.
                    if ($tableInfo['metadata'][$column]['IDENTITY'] == 1)
                        $this->createNodeAttribute('autoIncrement', 'true', $columnNode);
                    */
                }
                continue;
            }
        }

        $columnPosition = $column->getOrdinalPosition();
        $columnsCount = $tableNode->getElementsByTagName('column')->length;

        if ($columnsCount > $columnPosition && $columnPosition == 1)
            $tableNode->insertBefore($columnNode, $tableNode->getElementsByTagName('column')->item(0));
        elseif ($columnsCount >= $columnPosition)
            $tableNode->insertBefore($columnNode, $tableNode->getElementsByTagName('column')->item($columnPosition - 1));
        else
            $tableNode->appendChild($columnNode);
    }

    protected function setColumnType(\DOMElement $columnNode, $sqlType)
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

            case 'enum':
                $this->createNodeAttribute('type', 'enum', $columnNode);
                break;
        }
    }

    protected function generateForeignKeyDefinition(\DOMElement $tableNode, $fk)
    {
        if ($this->xpath->query("//table[@name=\"{$tableNode->getAttribute('name')}\"]/foreign-key[@sqlName=\"{$fk->getName()}\"]")->length == 0)
        {
            $localColumns = $fk->getColumns();
            $foreignColumns = $fk->getReferencedColumns();

            $foreignKey = $this->xml->createElement('foreign-key');

            $this->createNodeAttribute('sqlName', $fk->getName(), $foreignKey);
            $this->createNodeAttribute('foreignTable', $fk->getReferencedTableName(), $foreignKey);

            $foreignKeyPhpName = $this->underscoreToCamelCase($fk->getReferencedTableName());
            $this->createNodeAttribute('phpName', $foreignKeyPhpName, $foreignKey);

            $foreignKeyRefPhpName = $this->underscoreToCamelCase($tableNode->getAttribute('name'));
            $this->createNodeAttribute('refPhpName', $foreignKeyRefPhpName, $foreignKey);

            for ($i = 0; $i < count($fk->getColumns()); $i++)
            {
                $reference = $this->xml->createElement('reference');
                $this->createNodeAttribute('local', $localColumns[$i], $reference);
                $this->createNodeAttribute('foreign', $foreignColumns[$i], $reference);
                $foreignKey->appendChild($reference);
            }

            $tableNode->appendChild($foreignKey);
        }
    }

    protected function detectManyToManyRelations()
    {
        foreach ($this->xml->getElementsByTagName('foreign-key') as $fk)
        {
            $table = $fk->parentNode;

            if ($table->getElementsByTagName('column')->length == 2 && $table->getElementsByTagName('foreign-key')->length == 2 &&
            $this->xpath->query("//table[@name=\"{$table->getAttribute('name')}\"]/column[@primaryKey=\"true\"]")->length == 2 )
            {
                $this->createNodeAttribute('crossRefGroup', $table->getAttribute('name'), $fk);
                $fk->setAttribute('refPhpName', $this->underscoreToCamelCase($table->getAttribute('name')));
            }
        }
    }

    protected function createNodeAttribute($name, $value, \DOMElement $node)
    {
        $nodeName = $this->xml->createAttribute($name);
        $nodeName->value = $value;
        $node->appendChild($nodeName);
    }

    protected function getTables()
    {
        $sql = "SELECT table_name AS name, engine " .
               "FROM information_schema.tables " .
               "WHERE table_type = 'BASE TABLE' AND table_schema=SCHEMA() " .
               "ORDER BY table_name ASC;";

        return $this->getDbAdapter()->query($sql, array());
    }

    protected function getForeignKeyInfo($tableName)
    {
        $sql = "SELECT `column_name`, `constraint_name`, `referenced_table_name` AS foreign_table, `referenced_column_name`  AS foreign_column
                FROM `information_schema`.`KEY_COLUMN_USAGE`
                WHERE (`constraint_schema` = SCHEMA() AND `table_name` = '$tableName' AND `referenced_column_name` IS NOT NULL)
                ORDER BY `column_name`;";

        return $this->getDbAdapter()->query($sql, array());
    }

    protected function underscoreToCamelCase($name)
    {
        return implode('', array_map(function($n) {return ucfirst($n);}, explode('_', $name) ));
    }






}