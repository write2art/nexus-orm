<?php

/**
 * Description of Generator_Model
 *
 * This Class generates models from xml schema.
 *
 * @author tema
 */
class Nexus_Generator_Model
{
    /*
     * По этому пути всё будет сложено
     */
    protected $outputPath;

    /*
     * Префикс для моделей
     */
    protected $outputPrefix;

    protected $entities = array(
        'tables' => array(),
        'gateways' => array(),
        'queries' => array(),
        'rows' => array()
    );

    protected $schema;

    protected $xpath;

    public function __construct()
    {
        $config = new Zend_Config_Ini(APPLICATION_PATH . '/configs/nexus.ini', 'nexus');

        $this->outputPrefix = $config->get('output_prefix');
        $this->outputPath = sprintf("%s/%s",
            realpath($config->get('output_path')),
            $this->outputPrefix
        );

        $this->schema = new DOMDocument();
        $this->schema->load(realpath($config->get('schema_path')) . "/{$config->get('schema_name')}");
        $this->xpath = new DOMXPath($this->schema);
    }

    public function generate()
    {
        foreach ($this->schema->getElementsByTagName('table') as $table)
        {
            $this->generateTable($table);
            $this->generateGateway($table);
            $this->generateRow($table);
            $this->generateQuery($table);
        }

        $this->generateForeignKeys();

        foreach ($this->entities as $classes)
        {
            foreach ($classes as $name => $class)
            {
                $file = new Zend_CodeGenerator_Php_File();
                $file->setClass($class);
                $path = sprintf("%s/%s.php",
                    realpath(APPLICATION_PATH . '/../library'),
                    str_replace('_', '/', $name)
                );

                if (!is_dir(substr($path, 0, strrpos($path, '/'))))
                    mkdir(substr($path, 0, strrpos($path, '/')), 0755, true);

                file_put_contents($path, $file->generate());
            }
        }
    }

    protected function generateTable(DOMElement $table)
    {
        $class = Zend_CodeGenerator_Php_Class::fromReflection(new Zend_Reflection_Class('Nexus_Generator_Base_Table'));
        $class->setName($this->getTableName($table));

        $pk = $this->getTablePk($table);
        $autoIncrement = $this->isAutoIncrement($table);

        $class->setProperties(array(
            array(
                'name' => '_name',
                'visibility' => 'protected',
                'defaultValue' => $table->getAttribute('name')
            ),
            array(
                'name' => '_primary',
                'visibility' => 'protected',
                'defaultValue' => $pk
            )
        ));

        if (count($pk) == 1 && $autoIncrement === false)
            $class->setProperty(new Zend_CodeGenerator_Php_Property(array(
                'name' => '_sequence',
                'visibility' => 'protected',
                'defaultValue' => false
            )));

        $this->entities['tables'][$class->getName()] = $class;
    }

    protected function generateGateway($table)
    {
        $class = Zend_CodeGenerator_Php_Class::fromReflection(new Zend_Reflection_Class('Nexus_Generator_Base_Gateway'));
        $class->setName($this->getGatewayName($table));
        $class->setExtendedClass('Nexus_Gateway_Abstract');

        $class->setProperties(array(
            array(
                'name' => 'primary',
                'visibility' => 'protected',
                'defaultValue' => $this->getTablePk($table)
            ),
            array(
                'name' => 'rowClass',
                'visibility' => 'protected',
                'defaultValue' => $this->getFinalRowName($table)
            )
        ));

        if (!$this->isAutoIncrement($table))
            $class->setProperty(new Zend_CodeGenerator_Php_Property(array(
                'name' => 'autoIncrement',
                'visibility' => 'protected',
                'defaultValue' => false
            )));

        $class->setMethods(array(
            array(
                'name' => "setTable",
                'parameters' => array(
                    array(
                        'name' => 'table',
                        'type' => 'Zend_Db_Table_Abstract'
                    )
                ),
                'body' => "\$this->table = \$table;\nreturn \$this;"
            ),
            array(
                'name' => "getTable",
                'body' => "if (\$this->table === null)\n    \$this->setTable(new {$this->getTableName($table)}());\n\nreturn \$this->table;"
            )
        ));

        $methodGetInstance = $class->getMethod('getInstance');
        $methodGetInstance->setDocblock(new Zend_CodeGenerator_Php_Docblock(array(
            'tags' => array(
                array(
                    'name'        => 'return',
                    'description' => $this->getGatewayName($table),
                )
            ),
        )));

        $methodRow = $class->getMethod('row');
        $methodRow->setDocblock(new Zend_CodeGenerator_Php_Docblock(array(
            'shortDescription' => 'Return row object from data',
            'tags' => array(
                array(
                    'name'        => 'return',
                    'description' => $this->getFinalRowName($table),
                )
            ),
        )));

        $this->entities['gateways'][$class->getName()] = $class;
    }

    protected function generateRow(DOMElement $table)
    {
        $class = Zend_CodeGenerator_Php_Class::fromReflection(new Zend_Reflection_Class('Nexus_Generator_Base_Row'));
        $class->setName($this->getRowName($table));
        $class->setExtendedClass('Nexus_Gateway_Row_Abstract');

        $class->setMethods(array(
            array(
                'name' => 'gateway',
                'body' => "if (\$this->gateway === null)\n    \$this->gateway = {$this->getGatewayName($table)}::getInstance();\n\nreturn \$this->gateway;"
            )
        ));

        $data = array();

        foreach ($table->getElementsByTagName('column') as $column)
        {
            $propertyName = $column->getAttribute('name');
            $methodName = $column->getAttribute('phpName') ? : $this->toCamelCase($column->getAttribute('name'));

            $data[$propertyName] = null;

            $class->setMethods(array(
                array(
                    'name' => "set{$methodName}",
                    'parameters' => array(
                        array('name' => 'value')
                    ),
                    'body' => "\$this->data['{$propertyName}'] = \$value;\n\$this->modified = true;\nreturn \$this;"
                ),
                array(
                    'name' => "get{$methodName}",
                    'body' => "return \$this->data['{$propertyName}'];"
                )
            ));
        }

        $class->setProperties(array(
            array(
                'name' => 'data',
                'visibility' => 'protected',
                'defaultValue' => $data
            )
        ));

        $this->entities['rows'][$class->getName()] = $class;

        if (!file_exists($this->outputPath . "/{$table->getAttribute('phpName')}.php"))
        {
            $file = new Zend_CodeGenerator_Php_File();
            $class = new Zend_CodeGenerator_Php_Class(array(
                'name' => $this->getFinalRowName($table),
                'extendedClass' => $this->getRowName($table)
            ));
            $file->setClass($class);
            file_put_contents($this->outputPath . "/{$table->getAttribute('phpName')}.php", $file->generate());
        }
    }

    protected function generateQuery(DOMElement $table)
    {
        $class = Zend_CodeGenerator_Php_Class::fromReflection(new Zend_Reflection_Class('Nexus_Generator_Base_Query'));
        $class->setName($this->getQueryName($table));
        $class->setExtendedClass('Nexus_Query_Abstract');
        $class->setProperty(new Zend_CodeGenerator_Php_Property(array(
            'name' => 'tableName',
            'visibility' => 'protected',
            'defaultValue' => $table->getAttribute('name')
        )));

        $methodCreate = $class->getMethod('create');
        $methodCreate->setDocblock(new Zend_CodeGenerator_Php_Docblock(array(
            'shortDescription' => 'Return query object for further manipulations',
            'tags' => array(
                array(
                    'name'        => 'return',
                    'description' => $class->getName(),
                )
            ),
        )));

        $class->setMethods(array(
            array(
                'name' => 'getGateway',
                'visibility' => 'protected',
                'body' => "if (\$this->gateway === null)\n    \$this->gateway = {$this->getGatewayName($table)}::getInstance();\n\nreturn \$this->gateway;"
            )
        ));

        foreach ($table->getElementsByTagName('column') as $column)
        {
            $columnName = $column->getAttribute('name');
            $methodName = $column->getAttribute('phpName') ? : $this->underscoreToCamelCase($column->getAttribute('name'));

            $class->setMethods(array(
                array(
                    'name' => "filterBy{$methodName}",
                    'parameters' => array(
                        array('name' => 'value'),
                        array(
                            'name' => 'condition',
                            'defaultValue' => Nexus_Query_Abstract::EQUAL
                        )
                    ),
                    'body' => "\$this->{$column->getAttribute('type')}Filter('$columnName', \$value, \$condition);\nreturn \$this;"
                ),
                array(
                    'name' => "orderBy{$methodName}",
                    'parameters' => array(
                        array(
                            'name' => 'direction',
                            'defaultValue' => 'ASC'
                    )),
                    'body' => "\$this->select->order(\"{\$this->tableName}.{$columnName} \$direction\");\n\nreturn \$this;"
                )
            ));
        }

        $this->entities['queries'][$class->getName()] = $class;
    }

    protected function generateForeignKeys()
    {
        $processedRelations[] = array();

        foreach ($this->schema->getElementsByTagName('foreign-key') as $foreignKey)
        {
            $table = $foreignKey->parentNode;
            $foreignTable = $this->xpath->query("//table[@name=\"{$foreignKey->getAttribute('foreignTable')}\"]")->item(0);
            $reference = $foreignKey->getElementsByTagName('reference')->item(0);

            $tablePk = $this->getTablePk($table);

            //echo in_array($foreignKey->getAttribute('name'), $processedRelations) . "\n";

            // Query
            $this->createUseQueryRelationMethod($foreignKey);
            $this->createUseQueryRelationMethod($foreignKey, true);

            if (in_array($foreignKey->getAttribute('sqlName'), $processedRelations))
            {
                continue;
            }
            else if (!is_array($tablePk) && $tablePk == $reference->getAttribute('local'))
            {
                echo "One to one relation detected - {$foreignKey->getAttribute('sqlName')}\n";
                $this->createOneToOneRowRelationMethod($table, $foreignKey);
            }
            else if ($foreignKey->hasAttribute('crossRefGroup') ) //&& !in_array($foreignKey->getAttribute('sqlName'), $processedRelations))
            {
                echo "ManyToMany relation detected in {$table->getAttribute('name')}\n";
                foreach ($this->xpath->query("//table/foreign-key[@crossRefGroup=\"{$foreignKey->getAttribute('crossRefGroup')}\"]") as $value)
                {
                    if ($value->getAttribute('sqlName') == $foreignKey->getAttribute('sqlName'))
                        continue;
                    else
                        $crossForeignKey = $value;
                }

                //Rows
                $this->createManyToManyRowRelationMethod($foreignKey, $crossForeignKey);
            }
            else
            {
                echo "One to many relation detected - {$foreignKey->getAttribute('sqlName')}\n";
                // Rows
                $this->createManyToOneRowRelationMethod($foreignKey->parentNode, $foreignKey);
                $this->createOneToManyRowRelationMethod($foreignKey->parentNode, $foreignKey);
            }

            //echo $foreignKey->getAttribute('name') . "\n";
            $processedRelations[] = $foreignKey->getAttribute('sqlName');
        }
    }

    protected function createUseQueryRelationMethod(DOMElement $foreignKey, $inverse = false)
    {
        $table = $inverse ? $this->xpath->query("//table[@name=\"{$foreignKey->getAttribute('foreignTable')}\"]")->item(0) : $foreignKey->parentNode;
        $foreignTable = $inverse ? $foreignKey->parentNode : $this->xpath->query("//table[@name=\"{$foreignKey->getAttribute('foreignTable')}\"]")->item(0);
        $column = $inverse ? $foreignKey->getElementsByTagName('reference')->item(0)->getAttribute('foreign') : $foreignKey->getElementsByTagName('reference')->item(0)->getAttribute('local');
        $foreignColumn = $inverse ? $foreignKey->getElementsByTagName('reference')->item(0)->getAttribute('local') : $foreignKey->getElementsByTagName('reference')->item(0)->getAttribute('foreign');
        $phpName = $inverse ? $foreignKey->getAttribute('refPhpName') : $foreignKey->getAttribute('phpName');

        $queryClass = $this->entities['queries'][$this->getQueryName($table)];

        if ($queryClass->hasProperty('referenceMap'))
            $referenceMap = $queryClass->getProperty('referenceMap');
        else
        {
            $referenceMap = new Zend_CodeGenerator_Php_Property(array(
                'name' => 'referenceMap',
                'visibility' => 'protected',
                'defaultValue' => array()
            ));
            $queryClass->setProperty($referenceMap);
        }

        $referenceMapValue = $referenceMap->getDefaultValue()->getValue();
        $referenceMapValue[$phpName] = array(
            'gateway' => $this->getGatewayName($foreignTable),
            'local' => $column,
            'foreign' => $foreignColumn
        );

        $referenceMap->getDefaultValue()->setValue($referenceMapValue);

        if ($queryClass->getMethod("use{$phpName}Query") !== false)
            return;

        $queryClass->setMethods(array(
            array(
                'docblock' => new Zend_CodeGenerator_Php_Docblock(array(
                    'tags' => array(
                        array(
                            'name' => 'return',
                            'description' => $this->getQueryName($foreignTable),
                        )
                    ),
                )),
                'name' => "use{$phpName}Query",
                'body' =>  "\$this->select\n    "
                         . "->setIntegrityCheck(false)\n    "
                         //. "->from('{$table->getAttribute('name')}', array('{$table->getAttribute('name')}.*'))\n    "
                         . "->joinLeft(array('{$foreignTable->getAttribute('name')}'), '{$table->getAttribute('name')}.{$column} = {$foreignTable->getAttribute('name')}.{$foreignColumn}', array());\n\n"
                         . "return {$this->getQueryName($foreignTable)}::create(\$this->select, \$this);"
            )
        ));

    }

    protected function createOneToOneRowRelationMethod(DOMElement $table, DOMElement $foreignKey)
    {
        $foreignTable = $this->xpath->query("//table[@name=\"{$foreignKey->getAttribute('foreignTable')}\"]")->item(0);
        $reference = $foreignKey->getElementsByTagName('reference')->item(0);

        $addMethods = function($rowClass, $p) {
            try
            {

                $rowClass->setMethods(array(
                    array(
                        'docblock' => new Zend_CodeGenerator_Php_Docblock(array(
                            'tags' => array(
                                array(
                                    'name' => 'return',
                                    'description' => $p['finalRowName'], //$this->getFinalRowName($foreignTable),
                                )
                            ),
                        )),
                        'name' => "get{$p['phpName']}",
                        'body' => "if (!array_key_exists('{$p['phpName']}', \$this->references) || (!\$this->references['{$p['phpName']}']->isModified() && !\$this->references['{$p['phpName']}']->isNew()) )\n" .
                                  "    \$this->references['{$p['phpName']}'] = {$p['query']}::create()->findPk(\$this->get{$p['localColumn']}());\n\n" .
                                  "return \$this->references['{$p['phpName']}'];"
                    ),
                    array(
                        'name' => "set{$p['phpName']}",
                        'parameters' => array(
                            array(
                                'name' => 'value',
                                'type' => $p['finalRowName']
                        )),
                        'body' => "if (\$value->isNew())\n" .
                                  "    \$this->references['{$p['phpName']}'] = \$value;\n" .
                                  "else\n" .
                                  "    \$this->set{$p['localColumn']}(\$value->get{$p['foreignColumn']}());\n\n" .
                                  "return \$this;"
                    )
                ));
            }
            catch (Zend_Exception $e)
            {
                echo "Some problems occured during model generation in $rowClass\n";
            }
        };

        $rowClass = $this->entities['rows'][$this->getRowName($table)];
        $addMethods($rowClass, array(
            'finalRowName' => $this->getFinalRowName($foreignTable),
            'phpName' => $foreignKey->getAttribute('phpName'),
            'query' => $this->getQueryName($foreignTable),
            'localColumn' => $this->toCamelCase($reference->getAttribute('local')),
            'foreignColumn' => $this->toCamelCase($reference->getAttribute('foreign'))
        ));

        $rowClass = $this->entities['rows'][$this->getRowName($foreignTable)];
        $addMethods($rowClass, array(
            'finalRowName' => $this->getFinalRowName($table),
            'phpName' => $foreignKey->getAttribute('refPhpName'),
            'query' => $this->getQueryName($table),
            'localColumn' => $this->toCamelCase($reference->getAttribute('foreign')),
            'foreignColumn' => $this->toCamelCase($reference->getAttribute('local'))
        ));
    }

    protected function createOneToManyRowRelationMethod(DOMElement $table, DOMElement $foreignKey)
    {
        $foreignTable = $this->xpath->query("//table[@name=\"{$foreignKey->getAttribute('foreignTable')}\"]")->item(0);
        $reference = $foreignKey->getElementsByTagName('reference')->item(0);

        $rowClass = $this->entities['rows'][$this->getRowName($foreignTable)];

        $phpName = $foreignKey->getAttribute('phpName');
        $refPhpName = $foreignKey->getAttribute('refPhpName');
        $query = $this->getQueryName($table);
        $localColumn = $this->toCamelCase($reference->getAttribute('local'));
        $foreignColumn = $this->toCamelCase($reference->getAttribute('foreign'));

        $rowClass->setMethods(array(
            array(
                'name' => "add{$refPhpName}",
                'parameters' => array(
                    array(
                        'name' => 'value',
                        'type' => $this->getFinalRowName($table)
                )),
                'body' => "if (\$this->isNew())\n" .
                          "    \$this->dependencies['$refPhpName'][] = \$value;\n" .
                          "else\n" .
                          "{\n" .
                          "    \$value->set$localColumn(\$this->get$foreignColumn());\n" .
                          "    \$value->save();\n" .
                          "}\n\n" .
                          "return \$this;"
            ),
            array(
                'docblock' => new Zend_CodeGenerator_Php_Docblock(array(
                    'tags' => array(
                        array(
                            'name' => 'return',
                            'description' => 'Nexus_Gateway_Rowset_Abstract',
                        )
                    ),
                )),
                'name' => "get" . Nexus_Generator_Tools_Inflector::pluralize($refPhpName),
                'body' => "return $query::create()\n" .
                          "       ->filterBy$localColumn(\$this->get$foreignColumn())\n" .
                          "       ->find();\n"
            ),
            array(
                'docblock' => new Zend_CodeGenerator_Php_Docblock(array(
                    'tags' => array(
                        array(
                            'name' => 'return',
                            'description' => 'int',
                        )
                    ),
                )),
                'name' => "clear" . Nexus_Generator_Tools_Inflector::pluralize($refPhpName),
                'body' => "return $query::create()\n" .
                          "     ->filterBy$localColumn(\$this->get$foreignColumn())\n" .
                          "     ->delete();"
            ),
            array(
                'docblock' => new Zend_CodeGenerator_Php_Docblock(array(
                    'tags' => array(
                        array(
                            'name' => 'return',
                            'description' => 'int',
                        )
                    ),
                )),
                'name' => "count" . Nexus_Generator_Tools_Inflector::pluralize($refPhpName),
                'body' => "return $query::create()\n" .
                          "     ->filterBy$localColumn(\$this->get$foreignColumn())\n" .
                          "     ->count();"
            )
        ));
    }

    protected function createManyToOneRowRelationMethod(DOMElement $table, DOMElement $foreignKey)
    {
        $foreignTable = $this->xpath->query("//table[@name=\"{$foreignKey->getAttribute('foreignTable')}\"]")->item(0);
        $reference = $foreignKey->getElementsByTagName('reference')->item(0);

        $rowClass = $this->entities['rows'][$this->getRowName($table)];

        $phpName = $foreignKey->getAttribute('phpName');
        $query = $this->getQueryName($foreignTable);
        $localColumn = $this->toCamelCase($reference->getAttribute('local'));
        $foreignColumn = $this->toCamelCase($reference->getAttribute('foreign'));

        $rowClass->setMethods(array(
            array(
                'docblock' => new Zend_CodeGenerator_Php_Docblock(array(
                    'tags' => array(
                        array(
                            'name' => 'return',
                            'description' => $this->getFinalRowName($foreignTable),
                        )
                    ),
                )),
                'name' => "get$phpName",
                'body' => "if (!array_key_exists('$phpName', \$this->references) || (!\$this->references['$phpName']->isModified() && !\$this->references['$phpName']->isNew()) )\n" .
                          "    \$this->references['$phpName'] = $query::create()->findPk(\$this->get$localColumn());\n\n" .
                          "return \$this->references['$phpName'];"
            ),
            array(
                'name' => "set$phpName",
                'parameters' => array(
                    array(
                        'name' => 'value',
                        'type' => $this->getFinalRowName($foreignTable)
                )),
                'body' => "if (\$value->isNew())\n" .
                          "    \$this->references['$phpName'] = \$value;\n" .
                          "else\n" .
                          "    \$this->set$localColumn(\$value->get$foreignColumn());\n\n" .
                          "return \$this;"
            )
        ));
    }

    protected function createManyToManyRowRelationMethod(DOMElement $foreignKey, DOMElement $crossForeignKey)
    {
        $table = $foreignKey->parentNode;
        $foreignTable = $this->xpath->query("//table[@name=\"{$foreignKey->getAttribute('foreignTable')}\"]")->item(0);
        $crossTable = $this->xpath->query("//table[@name=\"{$crossForeignKey->getAttribute('foreignTable')}\"]")->item(0);
        $reference = $foreignKey->getElementsByTagName('reference')->item(0);
        $crossReference = $crossForeignKey->getElementsByTagName('reference')->item(0);

        $rowClass = $this->entities['rows'][$this->getRowName($foreignTable)];

        echo $this->getRowName($foreignTable) . "\n";

        $crossPhpName = $crossForeignKey->getAttribute('phpName');
        $localColumn = $this->toCamelCase($reference->getAttribute('local'));
        $foreignColumn = $this->toCamelCase($reference->getAttribute('foreign'));
        $crossLocalColumn = $this->toCamelCase($crossReference->getAttribute('local'));
        $crossForeignColumn = $this->toCamelCase($crossReference->getAttribute('foreign'));

        /*
        $phpName = $foreignKey->getAttribute('phpName');
        $query = $this->getQueryName($table);
        */

        $rowClass->setMethods(array(
            array(
                'name' => "add{$crossPhpName}",
                'parameters' => array(
                    array(
                        'name' => 'value',
                        'type' => $this->getFinalRowName($crossTable)
                )),
                'body' => "if (\$this->isNew() || \$value->isNew())\n" .
                          "    throw new Zend_Exception('Objects must be saved before adding many to many relations.');\n\n" .
                          "\$relation = new {$this->getFinalRowName($table)}();\n" .
                          "\$relation->set{$localColumn}(\$this->get{$foreignColumn}())\n" .
                          "         ->set{$crossLocalColumn}(\$value->get{$crossForeignColumn}())\n" .
                          "         ->save();\n\n" .
                          "return \$this;"
            ),
            array(
                'name' => "remove{$crossPhpName}",
                'parameters' => array(
                    array(
                        'name' => 'value',
                        'type' => $this->getFinalRowName($crossTable)
                )),
                'body' => "if (\$this->isNew() || \$value->isNew())\n" .
                          "    throw new Zend_Exception('Objects must be saved before removing many to many relations.');\n\n" .
                          "{$this->getQueryName($table)}::create()\n" .
                          "         ->filterBy{$localColumn}(\$this->get{$foreignColumn}())\n" .
                          "         ->filterBy{$crossLocalColumn}(\$value->get{$crossForeignColumn}())\n" .
                          "         ->delete();\n\n" .
                          "return \$this;"
            ),
            array(
                'name' => "get" . Nexus_Generator_Tools_Inflector::pluralize($crossPhpName),
                'body' => "if (\$this->isNew())\n" .
                          "     throw new Zend_Exception('Object or tag must be saved before getting many to many relations.');\n\n" .
                          "return {$this->getQueryName($crossTable)}::create()\n" .
                          "        ->use{$table->getAttribute('phpName')}Query()\n" .
                          "            ->filterBy{$localColumn}(\$this->get{$foreignColumn}())\n" .
                          "            ->endUse()\n" .
                          "        ->find();"
            ),
            array(
                'name' => "count" . Nexus_Generator_Tools_Inflector::pluralize($crossPhpName),
                'body' => "if (\$this->isNew())\n" .
                          "    throw new Zend_Exception('Objecta must be saved before counting many to many relations.');\n\n" .
                          "return {$this->getQueryName($crossTable)}::create()\n" .
                          "        ->filterBy{$localColumn}(\$this->get{$foreignColumn}())\n" .
                          "        ->count();"
            ),
            array(
                'name' => "clear" . Nexus_Generator_Tools_Inflector::pluralize($crossPhpName),
                'body' => "if (\$this->isNew())\n" .
                          "    throw new Zend_Exception('Objects must be saved before deleteing many to many relations.');\n\n" .
                          "{$this->getQueryName($crossTable)}::create()\n" .
                          "        ->filterBy{$localColumn}(\$this->get{$foreignColumn}())\n" .
                          "        ->delete();\n\n" .
                          "return \$this;"
            )
        ));
    }

    protected function underscoreToCamelCase($name)
    {
        return implode('', array_map(function($n) {return ucfirst($n);}, explode('_', $name) ));
    }

    protected function toCamelCase($name)
    {
        return implode('', array_map(function($n) {return ucfirst($n);}, explode('_', $name) ));
    }

    protected function getTableName(DOMElement $table)
    {
        return "{$this->outputPrefix}_Table_{$table->getAttribute('phpName')}";
    }

    protected function getGatewayName(DOMElement $table)
    {
        return "{$this->outputPrefix}_Gateway_{$table->getAttribute('phpName')}";
    }

    protected function getRowName(DOMElement $table)
    {
        return "{$this->outputPrefix}_Row_{$table->getAttribute('phpName')}";
    }

    protected function getFinalRowName(DOMElement $table)
    {
        return "{$this->outputPrefix}_{$table->getAttribute('phpName')}";
    }

    protected function getQueryName(DOMElement $table)
    {
        return "{$this->outputPrefix}_Query_{$table->getAttribute('phpName')}";
    }

    protected function getFileName($className, $type = 'Row')
    {
        return str_replace("{$this->outputPrefix}_{$type}_", '', $className);
    }

    protected function getTablePk(DOMElement $table)
    {
        $pk = array();

        foreach ($table->getElementsByTagName('column') as $column)
            if ($column->hasAttribute('primaryKey'))
                $pk[] = $column->getAttribute('name');

        return count($pk) > 1 ? $pk : $pk[0];
    }

    protected function isAutoIncrement(DOMElement $table)
    {
        foreach ($table->getElementsByTagName('column') as $column)
            if ($column->hasAttribute('autoIncrement'))
                return true;

        return false;
    }


}