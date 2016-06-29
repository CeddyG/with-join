<?php

namespace SleepingOwl\WithJoin;

use Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Collection;

class WithJoinEloquentBuilder extends Builder
{

    /**
     * @var string
     */
    public static $prefix = '__f__';

    /**
     * @var array
     */
    protected $references = [];

    /**
     * @param array $columns
     * @return \Illuminate\Database\Eloquent\Model[]
     */
    public function getModels($columns = ['*'])
    {
        $aListRelations = [];

        foreach($this->eagerLoad as $name => $constraints)
        {
            if($this->isNestedRelation($name))
            {
                continue;
            }

            $aListRelations[] = $name;
            $relation = $this->getRelation($name);

            if($this->isReferencedInQuery($name))
            {
                $this->disableEagerLoad($name);
                $this->addJoinToQuery($name, $this->model->getTable(), $relation);
                $this->addNestedRelations($name, $relation);

                $query = $relation->getQuery()->getQuery();
                $wheres = $query->wheres;
                $bindings = $query->getBindings();
                $this->mergeWheres($wheres, $bindings);
            }
        }

        $this->query->orderBy($this->model->getKeyName());
        $this->selectFromQuery($this->model->getTable(), '*');
        $models = parent::getModels($columns);
        $models = $this->mergeModels($models, $aListRelations);

        return $models;
    }

    /**
     * 
     * @param array $aModels 
     * @param array $aListRelations
     * 
     * @return array $aModels
     */
    protected function mergeModels($aModels, $aListRelations)
    {
        $this->formateRelations($aModels[0], $aListRelations);

        for($i = 0; $i < count($aModels); $i++)
        {
            $this->formateRelations($aModels[$i], $aListRelations);
            if($i == 0)
            {
                continue;
            }

            if($aModels[$i]->getKey() == $aModels[$i - 1]->getKey())
            {
                $modelRelations1 = $aModels[$i - 1]->getRelations();
                $modelRelations2 = $aModels[$i]->getRelations();

                $aNewRelations = $this->mergeRelations($aListRelations, $modelRelations1, $modelRelations2);

                $aModels[$i - 1]->setRelations($aNewRelations);
                array_splice($aModels, $i, 1);
                $i--;
            }
        }

        return $aModels;
    }

    /**
     * 
     * @param Illuminate\Database\Eloquent\Model $oModel
     * @param array $aListRelations
     * 
     * @return void
     */
    protected function formateRelations($oModel, $aListRelations)
    {
        $aModelRelation = $oModel->getRelations();

        foreach($aListRelations as $sEagerLoadRelation)
        {
            $oTypeRelation = $this->getRelation($sEagerLoadRelation);

            if(!$oTypeRelation instanceof BelongsTo && array_key_exists($sEagerLoadRelation, $aModelRelation) && !$aModelRelation[$sEagerLoadRelation] instanceof Collection)
            {
                $oNewCollection = new Collection;
                $oNewCollection->add($aModelRelation[$sEagerLoadRelation]);

                $oModel->setRelation($sEagerLoadRelation, $oNewCollection);
            }
        }
    }

    /**
     * 
     * @param array $aListRelations
     * @param array $aRelation1
     * @param array $aRelation2
     * 
     * @return Collection $aNewRelations
     */
    protected function mergeRelations($aListRelations, $aRelation1, $aRelation2)
    {
        $aNewRelations = [];

        foreach($aListRelations as $sEagerLoadRelation)
        {
            $oRelation = $this->getRelation($sEagerLoadRelation);

            if($oRelation instanceof BelongsTo)
            {
                continue;
            }

            if(array_key_exists($sEagerLoadRelation, $aRelation1) && array_key_exists($sEagerLoadRelation, $aRelation2) && $aRelation1[$sEagerLoadRelation] instanceof Collection)
            {
                $oNewCollection = $aRelation1[$sEagerLoadRelation]->merge($aRelation2[$sEagerLoadRelation]);
            }

            $aNewRelations[$sEagerLoadRelation] = $oNewCollection;
        }

        return $aNewRelations;
    }

    /**
     * @param $relation
     * @return bool
     */
    protected function isNestedRelation($relation)
    {
        return strpos($relation, '.') !== false;
    }

    /**
     * @param $name
     * @param Relation $relation
     */
    protected function addNestedRelations($name, Relation $relation)
    {
        $nestedRelations = $this->nestedRelations($name);
        if(count($nestedRelations) <= 0)
            return;

        $class = $relation->getRelated();
        foreach($nestedRelations as $nestedName => $nestedConstraints)
        {
            $relation = $class->$nestedName();
            $this->addJoinToQuery($nestedName, $name, $relation, $name . '---' . static::$prefix);
        }
    }

    /**
     * @param $joinTableAlias
     * @param $currentTableAlias
     * @param BelongsTo|BelongsToMany|HasMany|Relation $relation
     * @param string $columnsPrefix
     */
    protected function addJoinToQuery($joinTableAlias, $currentTableAlias, Relation $relation, $columnsPrefix = '')
    {
        $joinTableName = $relation->getRelated()->getTable();

        switch(true)
        {
            case $relation instanceof BelongsTo:
                $this->addJoinToQueryBelongsTo($joinTableAlias, $currentTableAlias, $relation, $joinTableName);
                break;

            case $relation instanceof BelongsToMany:
                $this->addJoinToQueryBelongsToMany($joinTableAlias, $currentTableAlias, $relation, $joinTableName);
                break;

            case $relation instanceof HasMany:
                $this->addJoinToQueryHasMany($joinTableAlias, $currentTableAlias, $relation, $joinTableName);
                break;
        }

        $columns = $this->getColumns($joinTableName);
        $prefix = static::$prefix . $columnsPrefix . $joinTableAlias . '---';
        foreach($columns as $column)
        {
            $this->selectFromQuery($joinTableAlias, $column, $prefix . $column);
        }
    }

    /**
     * 
     * @param $joinTableAlias
     * @param $currentTableAlias
     * @param BelongsTo $relation
     * @param $joinTableName
     */
    protected function addJoinToQueryBelongsTo($joinTableAlias, $currentTableAlias, BelongsTo $relation, $joinTableName)
    {
        $joinTable = implode(' as ', [
            $joinTableName,
            $joinTableAlias
        ]);
        $joinLeftCondition = implode('.', [
            $joinTableAlias,
            $relation->getOtherKey()
        ]);
        $joinRightCondition = implode('.', [
            $currentTableAlias,
            $relation->getForeignKey()
        ]);

        $this->query->leftJoin($joinTable, $joinLeftCondition, '=', $joinRightCondition);
    }

    /**
     * 
     * @param $joinTableAlias
     * @param $currentTableAlias
     * @param BelongsToMany $relation
     * @param $joinTableName
     */
    protected function addJoinToQueryBelongsToMany($joinTableAlias, $currentTableAlias, BelongsToMany $relation, $joinTableName)
    {
        $this->addJoinToQueryPivot($currentTableAlias, $relation);

        $otherkey = explode('.', $relation->getOtherKey());

        $joinTable = implode(' as ', [
            $joinTableName,
            $joinTableAlias
        ]);

        $joinLeftCondition = implode('.', [
            $relation->getTable(),
            $otherkey[1]
        ]);
        $joinRightCondition = implode('.', [
            $joinTableAlias,
            $relation->getParent()->getKeyName()
        ]);

        $this->query->leftJoin($joinTable, $joinLeftCondition, '=', $joinRightCondition);
    }

    /**
     * 
     * @param $currentTableAlias
     * @param BelongsToMany $relation
     */
    protected function addJoinToQueryPivot($currentTableAlias, BelongsToMany $relation)
    {
        $foreignKey = explode('.', $relation->getForeignKey());

        $joinTable = $relation->getTable();

        $joinLeftCondition = implode('.', [
            $joinTable,
            $foreignKey[1]
        ]);
        $joinRightCondition = implode('.', [
            $currentTableAlias,
            $relation->getParent()->getKeyName()
        ]);

        $this->query->leftJoin($joinTable, $joinLeftCondition, '=', $joinRightCondition);
    }

    /**
     * 
     * @param $joinTableAlias
     * @param $currentTableAlias
     * @param HasMany $relation
     * @param $joinTableName
     */
    protected function addJoinToQueryHasMany($joinTableAlias, $currentTableAlias, HasMany $relation, $joinTableName)
    {
        $otherkey = explode('.', $relation->getForeignKey());

        $joinTable = implode(' as ', [
            $joinTableName,
            $joinTableAlias
        ]);
        $joinLeftCondition = implode('.', [
            $joinTableAlias,
            $otherkey[1]
        ]);
        $joinRightCondition = implode('.', [
            $currentTableAlias,
            $relation->getParent()->getKeyName()
        ]);

        $this->query->leftJoin($joinTable, $joinLeftCondition, '=', $joinRightCondition);
    }

    /**
     * @param $name
     * @return bool
     */
    protected function isReferencedInQuery($name)
    {
        if(in_array($name, $this->references))
        {
            return true;
        }
        foreach($this->references as $reference)
        {
            if(strpos($reference, $name . '.') === 0)
            {
                return true;
            }
        }
        return false;
    }

    /**
     * @param $relation
     * @return bool
     */
    public function isRelationSupported($relation)
    {
        return $relation instanceof BelongsTo;
    }

    /**
     * @param $name
     */
    protected function disableEagerLoad($name)
    {
        unset($this->eagerLoad[$name]);
    }

    /**
     * @param $table
     * @param $column
     * @param null $as
     */
    protected function selectFromQuery($table, $column, $as = null)
    {
        $string = implode('.', [
            $table,
            $column
        ]);
        if(!is_null($as))
        {
            $string .= ' as ' . $as;
        }
        $this->query->addSelect($string);
    }

    /**
     * @param array $relations
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder|static
     */
    public function references($relations)
    {
        if(!is_array($relations))
        {
            $relations = func_get_args();
        }
        $this->references = $relations;
        return $this;
    }

    /**
     * @return array
     */
    public function getReferences()
    {
        return $this->references;
    }

    /**
     * @param $table
     * @return array
     */
    protected function getColumns($table)
    {
        $cacheKey = '_columns_' . $table;
        if($columns = Cache::get($cacheKey))
        {
            return $columns;
        }
        $columns = $this->model->getConnection()->getSchemaBuilder()->getColumnListing($table);
        Cache::put($cacheKey, $columns, 1440);
        return $columns;
    }

    /**
     * Find a model by its primary key.
     *
     * @param  mixed $id
     * @param  array $columns
     * @return \Illuminate\Database\Eloquent\Model|static|null
     */
    public function find($id, $columns = ['*'])
    {
        if(is_array($id))
        {
            return $this->findMany($id, $columns);
        }

        $this->query->where($this->model->getQualifiedKeyName(), '=', $id);

        return $this->first($columns);
    }

    /**
     * @param array $relations
     * @return \Illuminate\Database\Query\Builder
     */
    public function with($relations)
    {
        //if passing the relations as arguments, pass on to eloquents with
        if(is_string($relations))
            $relations = func_get_args();

        $includes = null;
        try
        {
            $includes = $this->getModel()->getIncludes();
        }
        catch(\BadMethodCallException $e)
        {
            
        }
        if(is_array($includes))
        {
            $relations = array_merge($relations, $includes);
            $this->references(array_keys($this->parseRelations($relations)));
        }

        parent::with($relations);

        return $this;
    }

}
