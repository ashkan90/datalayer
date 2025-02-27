<?php

namespace CoffeeCode\DataLayer;

use Exception;
use PDO;
use PDOException;
use stdClass;

/**
 * Class DataLayer
 * @package CoffeeCode\DataLayer
 */
abstract class DataLayer
{
    use CrudTrait;

    /** @var string $entity database table */
    protected $entity;

    /** @var string $primary table primary key field */
    protected $primary;

    /** @var array $required table required fields */
    protected $required;

    /** @var string $timestamps control created and updated at */
    protected $timestamps;

    /** @var string */
    protected $statement;

    /** @var string */
    protected $params;

    /** @var int */
    protected $order;

    /** @var int */
    protected $limit;

    /** @var string */
    protected $offset;

    /** @var \PDOException|null */
    protected $fail;

    /** @var object|null */
    protected $data;

//    /**
//     * DataLayer constructor.
//     * @param string $entity
//     * @param array $required
//     * @param string $primary
//     * @param bool $timestamps
//     */
//    public function __construct(string $entity, array $required, string $primary = 'id', bool $timestamps = true)
//    {
//        $this->entity = $entity;
//        $this->primary = $primary;
//        $this->required = $required;
//        $this->timestamps = $timestamps;
//    }

    /**
     * DataLayer constructor.
     */
    public function __construct()
    {
        $this->resolve();
    }

    /**
     * @param $name
     * @param $value
     */
    public function __set($name, $value)
    {
        if (empty($this->data)) {
            $this->data = new stdClass();
        }

        $this->data->$name = $value;
    }

    /**
     * @param $name
     * @return bool
     */
    public function __isset($name)
    {
        return isset($this->data->$name);
    }

    /**
     * @param $name
     * @return string|null
     */
    public function __get($name)
    {
        return ($this->data->$name ?? null);
    }

    /**
     * @return object|null
     */
    public function data(): ?object
    {
        return $this->data;
    }

    /**
     * @return PDOException|null
     */
    public function fail(): ?PDOException
    {
        return $this->fail;
    }

    /**
     * @param string|null $terms
     * @param string|null $params
     * @param string $columns
     * @return DataLayer
     */
    public function find(?string $terms = null, ?string $params = null, string $columns = "*"): DataLayer
    {
        if ($terms) {
            $this->statement = "SELECT {$columns} FROM {$this->entity} WHERE {$terms}";
            parse_str($params, $this->params);
            return $this;
        }

        $this->statement = "SELECT {$columns} FROM {$this->entity}";
        return $this;
    }

    /**
     * @param int $id
     * @param string $columns
     * @return DataLayer|null
     */
    public function findById(int $id, string $columns = "*"): ?DataLayer
    {
        $find = $this->find($this->primary . " = :id", "id={$id}", $columns);
        return $find->fetch();
    }

    /**
     * @param string $columnOrder
     * @return DataLayer|null
     */
    public function order(string $columnOrder): ?DataLayer
    {
        $this->order = " ORDER BY {$columnOrder}";
        return $this;
    }

    /**
     * @param int $limit
     * @return DataLayer|null
     */
    public function limit(int $limit): ?DataLayer
    {
        $this->limit = " LIMIT {$limit}";
        return $this;
    }

    /**
     * @param int $offset
     * @return DataLayer|null
     */
    public function offset(int $offset): ?DataLayer
    {
        $this->offset = " OFFSET {$offset}";
        return $this;
    }

    /**
     * @param bool $all
     * @return array|mixed|null
     */
    public function fetch(bool $all = false)
    {
        try {
            $stmt = Connect::getInstance()->prepare($this->statement . $this->order . $this->limit . $this->offset);
            $stmt->execute($this->params);

            if (!$stmt->rowCount()) {
                return null;
            }

            if ($all) {
                return $stmt->fetchAll(PDO::FETCH_CLASS, static::class);
            }

            return $stmt->fetchObject(static::class);
        } catch (PDOException $exception) {
            $this->fail = $exception;
            return null;
        }
    }

    /**
     * @return int
     */
    public function count(): int
    {
        $stmt = Connect::getInstance()->prepare($this->statement);
        $stmt->execute($this->params);
        return $stmt->rowCount();
    }

    /**
     * @return bool
     */
    public function save(): bool
    {
        $primary = $this->primary;
        $id = null;

        try {
            if (!$this->required()) {
                throw new Exception("Preencha os campos necessários");
            }

            /** Update */
            if (!empty($this->data->$primary)) {
                $id = $this->data->$primary;
                $this->update($this->safe(), $this->primary . " = :id", "id={$id}");
            }

            /** Create */
            if (empty($this->data->$primary)) {
                $id = $this->create($this->safe());
            }

            if (!$id) {
                return false;
            }

            $this->data = $this->findById($id)->data();
            return true;
        } catch (Exception $exception) {
            $this->fail = $exception;
            return false;
        }
    }

    /**
     * @return bool
     */
    public function destroy(): bool
    {
        $primary = $this->primary;
        $id = $this->data->$primary;

        if (empty($id)) {
            return false;
        }

        $destroy = $this->delete($this->primary . " = :id", "id={$id}");
        return $destroy;
    }

    /**
     * @return bool
     */
    protected function required(): bool
    {
        $data = (array)$this->data();
        foreach ($this->required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }
        return true;
    }

    /**
     * General resolve worker.
     * @return void
     */
    protected function resolve(): void
    {
        $this->resolveEntity();
        $this->resolveRequired();
        $this->resolveTimestamps();
        $this->resolvePK();
    }

    /**
     * Checking for default 'entity(table name)' property.
     * @return void
     */
    protected function resolveEntity(): void
    {
        // Entity name which comes from model as property,
        // could not be converted to lower case because
        // user registered it manually.
        if (empty($this->entity)) {
            // Getting called class name which is always Model.
            $segments = explode("\\", get_called_class());
            $len = count($segments);
            if ($len > 0) {
                // model(entity) name is default lower case.
                $name = strtolower($segments[$len - 1]);

                $this->entity = $name;
            }
        }
    }

    /**
     * Checking for default 'required' property.
     * @return void
     */
    protected function resolveRequired(): void
    {
        // Type of string is not handled for now.
        // But it can be done soon.
        if (empty($this->required)) {
            $this->required = [];
        }
    }

    /**
     * Checking for default 'primary' property.
     * @return void
     */
    protected function resolvePK(): void
    {
        if (empty($this->primary)) {
            $this->primary = "id";
        }
    }

    /**
     * Checking for default 'timestamps' property.
     * @return void
     */
    protected function resolveTimestamps(): void
    {
        if (empty($this->timestamps)) {
            $this->timestamps = true;
        }
    }

    /**
     * @return array|null
     */
    protected function safe(): ?array
    {
        $safe = (array)$this->data;
        unset($safe[$this->primary]);

        return $safe;
    }
}
