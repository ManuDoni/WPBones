<?php

namespace WPKirk\WPBones\Database;

use  WPKirk\WPBones\Database\Support\Collection;
use ArrayObject;

/**
 * The Database Model provides a base class for all database models.
 *
 * @package WPKirk\WPBones\Database
 *
 * You should extend this class to create your own model.
 *
 * TODO: Improve column as property
 *
 * We have to improve the get column as property because a column can be
 *
 * - 'foo_bar'
 * - 'foo bar'
 * - 'foo-bar'
 *
 * Of course, currently you will be able to get just $record->foo_bar
 */

class DB extends ArrayObject
{
    /**
     * The database table name.
     *
     * @var string
     */
    protected $table;

    /**
     * The WordPress database object.
     *
     * @var \wpdb
     */
    protected $wpdb;

    /**
     * List of columns and their types.
     * That's is the desc of the table.
     *
     * @var array
     */
    private $columns = [];

    /**
     * The orderings for the query.
     *
     * @var array
     */
    private $orders = [];

    /**
     * The maximum number of records to return.
     *
     * @var int
     */
    private $limit;

    /**
     * The number of records to skip.
     *
     * @var int
     */
    private $offset;

    /**
     * The where conditions for the query.
     *
     * @var array
     */
    private $wheres = [];

    /**
     * All of the available clause operators.
     *
     * @var array
     */
    private $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'like binary', 'not like', 'ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'not rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to',
        'not similar to', 'not ilike', '~~*', '!~~*',
    ];

    public static $instances = [];

    /**
     * The collection of rows.
     *
     * @var \WPKirk\WPBones\Database\Support\Collection
     */
    private $collection = [];

    public function __construct($record = null, $table = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $table ?? $this->table;

        // init
        $this->getTableDescription();

        // init a single record
        if (!is_null($record)) {
            parent::__construct($record, ArrayObject::ARRAY_AS_PROPS);
        }
    }

    private static function getInstance($table)
    {
        if (!isset(self::$instances[$table])) {
            self::$instances[$table] = new static(null,$table);
        }
        return self::$instances[$table];
    }

    /*
    |--------------------------------------------------------------------------
    | Public methods
    |--------------------------------------------------------------------------
    |
    | Despite they are "private" they are the public methods available as by static or by instance.
    |
    */

    /**
     * The main constructor.
     *
     * @param string $table The table name.
     */
    public static function table($table)
    {
        $instance = self::getInstance($table);

        return $instance ;
    }

    /**
     * Retrun a collection of all the records.
     *
     * @return Collection
     */
    protected function all($columns = ['*'])
    {
        $column_string = is_array($columns) ? implode(',', $columns) : implode(',', func_get_args());

        $sql = "SELECT $column_string " .
               "FROM `{$this->getTableName()}`" .
               $this->getWhere() .
               $this->getOrderBy() .
               $this->getLimit() .
                $this->getOffset() ;
        
        logger()->info($sql);
                
        $results = $this->wpdb->get_results($sql, ARRAY_A);

        /**
         *     [0] => stdClass Object
         *      (
         *          [log_id] => 1
         *          [user_id] => 1
         *          [activity] => updated
         *          [object_id] => 0
         *          [object_type] => post
         *          [activity_date] => 2019-05-03 00:00:00
         *      )
         *      ...
         */

        //error_log(print_r($results, true));
         
        $collection = [];
        foreach ($results as $result) {
            $collection[] = new static($result);
        }

        $this->collection = new Collection($collection);

        //error_log(print_r($this->collection, true));

        // reset the where conditions
        $this->wheres = [];

        return $this->collection;
    }

    protected function get()
    {
        return $this->all();
    }

    /**
     * Build the "where" part of the query.
     *
     *
     */
    protected function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_array($column)) {
            foreach ($column as $value) {
                [$column, $operator, $value] = $value;
                $this->where($column, $operator, $value);
            }
            return $this;
        }

        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        $this->wheres[] = compact('column', 'operator', 'value', 'boolean');

        // error_log(print_r($this->wheres, true));
        // error_log(print_r($this->getWhere(), true));

        return $this;
    }

    /**
     * Build the "where" part of the query.
     */
    protected function orWhere($column, $operator = null, $value = null)
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->where($column, $operator, $value, 'or');
    }

    protected function prepareValueAndOperator($value, $operator, $useDefault = false)
    {
        if ($useDefault) {
            return [$operator, '='];
        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new \InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    /**
     * Return the "where" part of the query.
     *
     * @return string
     */
    protected function getWhere()
    {
        $where = ' WHERE 1 ';
        if (!empty($this->wheres)) {
            foreach ($this->wheres as $where_item) {
                $where .= strtoupper($where_item['boolean']) . ' ' . $where_item['column'] . ' ' . $this->getWhereOperator($where_item['operator']) . ' ' . $this->getWhereValue($where_item['value']) . ' ';
            }
        }
        return $where;
    }

    /**
     * Return the operator for the where clause.
     *
     * @param  string $operator
     */
    protected function getWhereOperator($operator)
    {
        if (in_array(strtolower($operator), $this->operators, true)) {
            return strtolower($operator);
        }
        return '=';
    }

    /**
     * Return the right format for the where value.
     *
     * @param  string $value
     */
    protected function getWhereValue($value)
    {
        if (is_string($value)) {
            return "'" . $value . "'";
        }
        return $value;
    }

    /**
     * Set the "order by" clause for the query.
     */
    protected function orderBy($column, $order = 'asc')
    {
        $order = strtolower($order);

        $this->orders[] = [$column, $order];

        return $this;
    }

    /**
     * Set the offset "limit" clause for the query.
     */
    protected function offset($value = 0)
    {
        $this->offset = max(0, (int) $value);

        return $this;
    }

    /**
     * Set the limit "limit" clause for the query.
     */
    protected function limit($value = 1)
    {
        $this->limit = max(1, (int) $value);

        return $this;
    }

    /*
     |--------------------------------------------------------------------------
     | Magic methods
     |--------------------------------------------------------------------------
     |
     |
     |
     */

    /**
     * We will use this megic method to return the value of the column.
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        //error_log(print_r($name, true));

        if (isset($this->offsetExists[$name])) {
            return $this->offsetGet[$name];
        }

        return null;
    }

    public function __isset($index)
    {
        return $this->offsetExists($index);
    }

    /**
     * We will use this magic mathod to call the private methods.
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this, $name)) {
            return call_user_func_array([$this, $name], $arguments);
        }
    }



    /**
     * Return the JSON representation of the object.
     */
    public function __toString()
    {
        return json_encode($this);
    }

    /**
     * Return a JSON pretty version of the collection.
     *
     * @return string
     */
    public function dump()
    {
        return json_encode(json_decode((string) $this), JSON_PRETTY_PRINT);
    }
     

    /*
     |--------------------------------------------------------------------------
     | Internal Methods
     |--------------------------------------------------------------------------
     |
     |
     |
     */

    /**
     * Retyurn the "order by" clause.
     *
     * @return string
     */
    protected function getOrderBy()
    {
        if (!empty($this->orders)) {
            $orders = [];
            foreach ($this->orders as $order) {
                $orders[] = $order[0] . ' ' . $order[1];
            }
            return ' ORDER BY ' . implode(',', $orders);
        }
        return '';
    }

    /**
     * Return the "limit" clause.
     *
     * @return string
     */
    protected function getLimit()
    {
        if (!empty($this->limit)) {
            return ' LIMIT ' . $this->limit;
        }
        
        return '';
    }

    /**
     * Return the "offset" clause.
     *
     * @return string
     */
    protected function getOffset()
    {
        if (!empty($this->offset)) {
            $offset =  max(0, (int) $this->offset);
            if ($offset > 0) {
                if (empty($this->limit)) {
                    return ' LIMIT 18446744073709551615 OFFSET ' . $offset;
                }
            }
            return ' OFFSET ' . $offset;
        }

        return '';
    }



    /**
     * Get the table description.
     *
     * @return void
     */
    protected function getTableDescription()
    {
        if (!empty($this->table)) {
            $desc = $this->wpdb->get_results("DESC `{$this->getWordPressTableName($this->table)}`");

            /**
             * [0] => stdClass Object
             *      (
             *          [Field] => ID
             *          [Type] => bigint(20) unsigned
             *          [Null] => NO
             *          [Key] => PRI
             *          [Default] =>
             *          [Extra] => auto_increment
             *      )
             *
             *  [1] => stdClass Object
             *      (
             *          [Field] => user_login
             *          [Type] => varchar(60)
             *          [Null] => NO
             *          [Key] => MUL
             *          [Default] =>
             *          [Extra] =>
             *      )
             */

            foreach ($desc as $column) {
                $this->columns[] = [
                    'name' => $column->Field,
                    'type' => $column->Type,
                    'null' => $column->Null,
                    'key' => $column->Key,
                    'default' => $column->Default,
                    'extra' => $column->Extra,
                ];
            }

            /**
             * [0] => Array
             *      (
             *          [name] => ID
             *          [type] => bigint(20) unsigned
             *          [null] => NO
             *          [key] => PRI
             *          [default] =>
             *          [extra] => auto_increment
             *      )
             *
             *  [1] => Array
             *      (
             *          [name] => user_login
             *          [type] => varchar(60)
             *          [null] => NO
             *          [key] => MUL
             *          [default] =>
             *          [extra] =>
             *      )
             */
        }
    }

    /**
     * Commodity method to get a WordPress table name.
     * Here we're going to add the prefix to the table name.
     *
     * @param  string $table_name
     * @return string
     */
    protected function getWordPressTableName($table)
    {
        return $this->wpdb->prefix . $table;
    }

    /*
     |--------------------------------------------------------------------------
     | Getters and Setters
     |--------------------------------------------------------------------------
     |
     | Special Getters and Setters for the model.
     |
     */

    /**
     * Get the table name.
     *
     * @return string
     */
    protected function getTableName()
    {
        return $this->getWordPressTableName($this->table);
    }

    /**
     * Set the table name.
     */
    public function setTableName($table)
    {
        $this->table = $table;
    }

    /**
     * Return the WordPress database object.
     *
     * @return object
     */
    protected function getWpdb()
    {
        return $this->wpdb;
    }
}
