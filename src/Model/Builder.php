<?php

namespace Max\Database\Model;

use Max\Database\Collection;
use Max\Database\Model;

class Builder extends \Max\Database\Query\Builder
{
    /**
     * @var Model
     */
    protected Model $model;

    /**
     * @var
     */
    protected $class;

    /**
     * @param Model $model
     *
     * @return $this
     */
    public function setModel(Model $model)
    {
        $this->model = $model;
        $this->class = get_class($model);
        $this->from  = [$model->getTable(), ''];

        return $this;
    }

    /**
     * @param array $columns
     *
     * @return Collection
     */
    public function get(array $columns = ['*'])
    {
        return Collection::make(
            $this->run($this->toSql($columns))
                 ->fetchAll(\PDO::FETCH_CLASS, $this->class)
        );
    }

    /**
     * @param array $columns
     *
     * @return false|mixed|object
     */
    public function first(array $columns = ['*'])
    {
        return $this->run($this->toSql($columns))->fetchObject($this->class);
    }

    /**
     * @param       $id
     * @param array $columns
     *
     * @return false|mixed|object
     */
    public function find($id, array $columns = ['*'])
    {
        return $this->where($this->model->getKey(), '=', $id)->first($columns);
    }
}