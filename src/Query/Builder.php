<?php

namespace Max\Database\Query;

use Max\Database\Collection;
use Max\Database\Connector;
use Max\Database\Contracts\ConnectorInterface;
use Max\Database\Contracts\GrammarInterface;

class Builder
{
    /**
     * @var array|null
     */
    public ?array $where;

    /**
     * @var array
     */
    public array $select;

    /**
     * @var array
     */
    public array $from;

    /**
     * @var array
     */
    public array $order;

    /**
     * @var array
     */
    public array $group;

    /**
     * @var array
     */
    public array $having;

    /**
     * @var array
     */
    public array $join;

    /**
     * @var array
     */
    public array $bindings = [];

    /**
     * @var GrammarInterface
     */
    protected GrammarInterface $grammar;

    /**
     * @var ConnectorInterface
     */
    protected ConnectorInterface $connector;

    /**
     * @param ConnectorInterface $connector
     * @param GrammarInterface   $grammar
     */
    public function __construct(ConnectorInterface $connector, GrammarInterface $grammar)
    {
        $this->connector = $connector;
        $this->grammar   = $grammar;
    }

    /**
     * @param string $connection
     */
    public function setConnection(string $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param string $table
     * @param null   $alias
     *
     * @return $this
     */
    public function from(string $table, $alias = null)
    {
        $this->from = func_get_args();

        return $this;
    }

    /**
     * @param string $column
     * @param string $operator
     * @param null   $value
     *
     * @return $this
     */
    public function where(string $column, string $operator, $value = null)
    {
        $where = [$column, $operator];

        if (!is_null($value)) {
            $where[] = '?';
            $this->addBindings($value);
        }
        $this->where[] = $where;

        return $this;
    }

    /**
     * @param string $column
     *
     * @return $this
     */
    public function whereNull(string $column)
    {
        return $this->where($column, 'IS NULL');
    }

    /**
     * @param string $column
     *
     * @return $this
     */
    public function whereNotNull(string $column)
    {
        return $this->where($column, 'IS NOT NULL');
    }

    /**
     * @param $column
     * @param $value
     *
     * @return $this
     */
    public function whereLike($column, $value)
    {
        return $this->where($column, 'LIKE', $value);
    }

    /**
     * @param string $column
     * @param array  $in
     *
     * @return $this
     */
    public function whereIn(string $column, array $in)
    {
        $this->addBindings($in);
        $this->where($column, sprintf('IN (%s)', rtrim(str_repeat('?, ', count($in)), ' ,')));

        return $this;
    }

    /**
     * @param        $table
     * @param        $alias
     * @param string $league
     *
     * @return Join
     */
    public function join($table, $alias, $league = 'INNER JOIN')
    {
        return $this->join[] = new Join($this, $table, $alias, $league);
    }

    public function leftJoin($table, $alias)
    {
        return $this->join($this, $table, $alias, 'LEFT OUT JOIN');
    }

    public function rightJoin($table, $alias)
    {
        return $this->join($this, $table, $alias, 'RIGHT OUT JOIN');
    }

    public function whereBetween($column, $start, $end)
    {
        $this->addBindings([$start, $end]);

        return $this->where($column, 'BETWEEN(? and ?)');
    }

    protected function addBindings($value)
    {
        if (is_array($value)) {
            array_push($this->bindings, ...$value);
        } else {
            $this->bindings[] = $value;
        }
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function select(array $columns = ['*'])
    {
        $this->select = $columns;

        return $this;
    }

    public function order($column, $order = '')
    {
        $this->order[] = func_get_args();

        return $this;
    }

    public function group($column)
    {
        $this->group[] = $column;

        return $this;
    }

    public function having($first, $operator, $last)
    {
        $this->having[] = func_get_args();

        return $this;
    }

    public function toSql($columns = ['*']): string
    {
        $this->select($columns);

        return $this->grammar->generateSelectQuery($this);
    }

    public function get(array $columns = ['*'])
    {
        return Collection::make(
            $this->run($this->toSql($columns), $this->bindings)
                 ->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function count($column = '*'): int
    {
        return $this->aggregate("COUNT({$column})");
    }

    public function sum($column): int
    {
        return $this->aggregate("SUM($column)");
    }

    public function max($column): int
    {
        return $this->aggregate("MAX({$column})");
    }

    public function min($column): int
    {
        return $this->aggregate("MIN({$column})");
    }

    public function avg($column): int
    {
        return $this->aggregate("AVG({$column})");
    }

    protected function aggregate(string $expression): int
    {
        return (int)$this->run($this->toSql((array)($expression . 'AS AGGREGATE ')), $this->bindings)
                         ->fetchColumn(0);
    }

    /**
     * 事务
     *
     * @param \Closure $transaction
     *
     * @return mixed
     */
    public function transaction(\Closure $transaction)
    {
        $PDO = $this->connection->getPDO();
        try {
            $PDO->beginTransaction();
            $result = $transaction($this, $PDO);
            $PDO->commit();
            return $result;
        } catch (\PDOException $e) {
            $PDO->rollback();
            throw $e;
        }
    }

    public function exists(): bool
    {
        $query = sprintf('SELECT EXISTS(%s) AS MAX_EXIST', $this->toSql());

        return (bool)$this->run($query, $this->bindings)->fetchColumn(0);
    }

    public function column(string $column, string $key = null)
    {
        $result = $this->run($this->toSql(), $this->bindings)->fetchAll();

        return Collection::make($result ?: [])->pluck($column, $key);
    }

    public function find($id, array $columns = ['*'])
    {
        return $this->where('id', '=', $id)->first($columns);
    }

    public function first(array $columns = ['*'])
    {
        return $this->run($this->toSql($columns), $this->bindings)->fetch(\PDO::FETCH_ASSOC);
    }

    public function insert(array $data)
    {
        $this->column   = array_keys($data);
        $this->bindings = array_values($data);
        $this->run($this->grammar->generateInsertQuery($this), $this->bindings);

        return $this->connection->getPDO()->lastInsertId();
    }

    public function insertAll(array $data)
    {
        return array_map(function($item) {
            return $this->insert($item);
        }, $data);
    }

    //    public function update(array $data): string
    //    {
    //        $set = '';
    //        foreach ($data as $field => $value) {
    //            $set .= "{$field} = ? , ";
    //        }
    //        $set = substr($set, 0, -3);
    //        array_unshift($this->bindings, ...array_values($data));
    //        return sprintf(static::UPDATE,
    //            $this->getTable(),
    //            $set,
    //            $this->getWhere()
    //        );
    //    }

    public function run(string $query, array $bindings): \PDOStatement
    {
        $PDOStatement = $this->connector->statement($query, $bindings);

        $PDOStatement->execute();

        return $PDOStatement;
    }

}