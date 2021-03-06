<?php

namespace ChrisAndChris\Common\RowMapperBundle\Services\Model;

use ChrisAndChris\Common\RowMapperBundle\Entity\Entity;
use ChrisAndChris\Common\RowMapperBundle\Entity\KeyValueEntity;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\Database\NoSuchRowFoundException;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\DatabaseException;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\ForeignKeyConstraintException;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\InvalidOptionException;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\NotCapableException;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\UniqueConstraintException;
use ChrisAndChris\Common\RowMapperBundle\Services\Pdo\PdoStatement;
use ChrisAndChris\Common\RowMapperBundle\Services\Query\SqlQuery;
use Psr\Log\LoggerInterface;

/**
 * @name ConcreteModel
 * @package    RowMapperBundle
 * @author     ChrisAndChris
 * @link       https://github.com/chrisandchris
 */
class ConcreteModel
{

    /** @var ModelDependencyProvider the dependency provider */
    protected $dependencyProvider;
    /** @var PdoStatement */
    private $lastStatement;
    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    function __construct(
        ModelDependencyProvider $dependencyProvider,
        LoggerInterface $logger
    ) {
        $this->dependencyProvider = $dependencyProvider;
        $this->logger = $logger;
    }

    public function getLogger() : LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Prepares the option array
     *
     * @param array $availableOptions
     * @param array $options
     * @throws InvalidOptionException
     */
    public function prepareOptions(array $availableOptions, array &$options)
    {
        foreach ($availableOptions as $option) {
            if (!isset($options[$option])) {
                $options[$option] = null;
            }
        }
        foreach (array_keys($options) as $name) {
            if (!in_array($name, $availableOptions)) {
                throw new InvalidOptionException(sprintf(
                    "Option '%s' is unknown to this method",
                    $name
                ));
            }
        }
    }

    /**
     * Checks whether $optionName is the only option which is not null, except
     * for keys of $allowAlways
     *
     * @param array $options
     * @param       $optionName
     * @param array $allowAlways
     * @return bool
     */
    public function isOnlyOption(
        array $options,
        $optionName,
        array $allowAlways = ['limit', 'offset']
    ) {
        foreach ($options as $name => $value) {
            if ($name != $optionName && $value !== null &&
                !in_array($name, $allowAlways)) {
                return false;
            }
        }

        return true && isset($options[$optionName]);
    }

    /**
     * Runs a query and maps the result to $entity
     *
     * @param SqlQuery      $query
     * @param Entity        $entity
     * @param \Closure|null $callAfter a closure to call after the mapping is
     *                                 done
     * @return entity[]
     */
    public function run(
        SqlQuery $query,
        Entity $entity,
        \Closure $callAfter = null
    ) {
        $stmt = $this->prepare($query);

        return $this->handle($stmt, $entity, $callAfter,
            $query->getMappingInfo());
    }

    /**
     * Prepares a statement including value binding
     *
     * @param SqlQuery $query
     * @return PdoStatement
     */
    public function prepare(SqlQuery $query)
    {
        $stmt = $this->createStatement(
            $query->getQuery(),
            $query->isReadOnly()
        );
        foreach ($query->getParameters() as $id => $parameter) {
            $bindType = \PDO::PARAM_STR;
            if ($parameter === true || $parameter === false) {
                $bindType = \PDO::PARAM_BOOL;
            } elseif ($parameter === null) {
                $bindType = \PDO::PARAM_NULL;
            } elseif (is_numeric($parameter)) {
                $bindType = \PDO::PARAM_INT;
            }
            $stmt->bindValue(++$id, $parameter, $bindType);
        }
        $stmt->requiresResult(
            $query->isResultRequired(),
            $query->getRequiresResultErrorMessage()
        );

        return $stmt;
    }

    /**
     * Create a new statement from SQL-Code
     *
     * @param      $sql
     * @param bool $readOnly
     * @return \ChrisAndChris\Common\RowMapperBundle\Services\Pdo\PdoStatement
     */
    private function createStatement($sql, bool $readOnly = false)
    {
        /** @var PDOStatement $stmt */
        $stmt = $this->getDependencyProvider()
                     ->getPdo(($readOnly) ? 'r' : 'w')
                     ->prepare($sql);

        $this->lastStatement = $stmt;

        return $stmt;
    }

    /**
     * Get the dependency provider
     *
     * @return ModelDependencyProvider
     */
    public function getDependencyProvider()
    {
        return $this->dependencyProvider;
    }

    /**
     * Handles a statement including mapping to entity (if given) and error
     * handling<br /> If no entity is given returns true on success, false
     * otherwise
     *
     * @param PdoStatement  $statement
     * @param Entity        $entity
     * @param \Closure|null $callAfter   callback to run after mapping is done
     * @param array         $mappingInfo the mapping info to use (typecast)
     * @return bool|\ChrisAndChris\Common\RowMapperBundle\Entity\Entity[]
     */
    private function handle(
        PdoStatement $statement,
        Entity $entity = null,
        \Closure $callAfter = null,
        array $mappingInfo = []
    ) {
        return $this->handleGeneric(
            $statement,
            function (PdoStatement $statement) use (
                $entity,
                $callAfter,
                $mappingInfo
            ) {
                if ((int)$statement->errorCode() != 0 ||
                    $statement->errorInfo()[1] != null) {
                    return $this->handleError($statement);
                }
                if ($entity === null) {
                    return true;
                }

                $mapping = $this->getMapper()
                                ->mapFromResult(
                                    $statement,
                                    $entity,
                                    null,
                                    $mappingInfo
                                );

                if ($callAfter instanceof \Closure) {
                    $callAfter($mapping);
                }

                return $mapping;
            }
        );
    }

    /**
     * Does low-level handling of the query
     *
     * @param PdoStatement $statement
     * @param \Closure     $mappingCallback callback to map results
     * @return mixed
     * @throws NoSuchRowFoundException
     */
    private function handleGeneric(
        PdoStatement $statement,
        \Closure $mappingCallback
    ) {
        if ($this->execute($statement)) {
            if ($statement->rowCount() === 0 &&
                $statement->isResultRequired()) {
                if (strlen($statement->getRequiresResultErrorMessage()) > 0) {
                    throw new NoSuchRowFoundException($statement->getRequiresResultErrorMessage());
                }
                throw new NoSuchRowFoundException("No row found with query");
            }

            return $mappingCallback($statement);
        }

        return $this->handleError($statement);
    }

    /**
     * Execute a PDOStatement and writes it to the log
     *
     * @param PdoStatement $statement
     * @return bool
     */
    private function execute(PdoStatement $statement)
    {
        return $statement->execute();
    }

    /**
     * Handles statement errors
     *
     * @param PdoStatement $statement
     * @return bool
     * @throws DatabaseException
     * @throws ForeignKeyConstraintException
     * @throws UniqueConstraintException
     */
    private function handleError(PdoStatement $statement)
    {
        return $this->getErrorHandler()
                    ->handle(
                        $statement->errorInfo()[1],
                        $statement->errorInfo()[2]
                    );
    }

    /**
     * Get the error handler
     *
     * @return ErrorHandler
     */
    public function getErrorHandler()
    {
        return $this->dependencyProvider->getErrorHandler();
    }


    /**
     * @return \ChrisAndChris\Common\RowMapperBundle\Services\Mapper\RowMapper
     */
    public function getMapper()
    {
        return $this->dependencyProvider->getMapper();
    }

    /**
     * Get the dependency provider (shortcut)
     *
     * @return ModelDependencyProvider
     */
    public function getDp()
    {
        return $this->dependencyProvider;
    }

    /** @noinspection PhpDocSignatureInspection */
    /**
     * Run a query with custom return
     *
     * @param SqlQuery       $query
     * @param mixed|\Closure $onSuccess on success
     * @param mixed|\Closure $onFailure on failure
     * @param null|\Closure  $onError   on exception, if null exception is
     *                                  thrown
     * @return mixed
     * @throws \Exception
     */
    public function runCustom(
        SqlQuery $query,
        $onSuccess,
        $onFailure,
        $onError = null
    ) {
        try {
            if ($this->runSimple($query)) {
                if ($onSuccess instanceof \Closure) {
                    return $onSuccess();
                }

                return $onSuccess;
            } else {
                if ($onFailure instanceof \Closure) {
                    return $onFailure();
                }

                return $onFailure;
            }
        } catch (\Exception $exception) {
            if ($onError === null) {
                throw $exception;
            } else {
                if ($onError instanceof \Closure) {
                    return $onError();
                }
            }

            return $onError;
        }
    }

    /**
     * Runs a simple query, just returning true on success
     *
     * @param SqlQuery $query
     * @return bool
     */
    public function runSimple(SqlQuery $query)
    {
        return $this->handle($this->prepare($query), null);
    }

    /**
     * Runs a simple query, returning the last insert id on success
     *
     * @param SqlQuery $query
     * @param string   $sequence the sequence to return the last insert id for
     * @return int
     */
    public function runWithLastId(SqlQuery $query, $sequence = null)
    {
        return $this->handleWithLastInsertId($this->prepare($query), $sequence);
    }

    /**
     * Handles a statement and returns the last insert id on success
     *
     * @param PdoStatement $statement
     * @param string       $sequence the sequence to return the last insert id
     *                               for
     * @return int
     */
    private function handleWithLastInsertId(
        PdoStatement $statement,
        $sequence = null
    ) {
        return $this->handleGeneric(
            $statement,
            function () use ($sequence) {

                if (strstr($sequence, ':')) {
                    $sequence = explode(':', $sequence);
                    array_push($sequence, 'seq');
                    $sequence = implode('_', $sequence);
                }

                return $this->getDependencyProvider()
                            ->getPdo('w')// always with write
                            ->lastInsertId($sequence);
            }
        );
    }

    /**
     * Handles an array query
     *
     * WARNING: does not preserve type casting during mapping
     *
     * @param SqlQuery $query
     * @param Entity   $entity
     * @param \Closure $closure
     * @return array
     */
    public function runArray(SqlQuery $query, Entity $entity, \Closure $closure)
    {
        return $this->handleGeneric(
            $this->prepare($query),
            function (PdoStatement $statement) use ($entity, $closure) {
                return $this->getMapper()
                            ->mapToArray($statement, $entity, $closure);
            }
        );
    }

    /**
     * Runs the query and maps it to an associative array
     *
     * @param SqlQuery $query
     * @return array
     */
    public function runAssoc(SqlQuery $query)
    {
        return $this->handleGeneric(
            $this->prepare($query),
            function (\PDOStatement $statement) use ($query) {
                return $this->getMapper()
                            ->mapFromResult($statement, null, null,
                                $query->getMappingInfo());
            }
        );
    }

    /**
     * Handles a key value query
     *
     * WARNING: does not preserve type casting during mapping
     *
     * @param SqlQuery $query
     * @return array
     */
    public function runKeyValue(SqlQuery $query)
    {
        $stmt = $this->prepare($query);

        return $this->handleGeneric(
            $stmt,
            function (PdoStatement $statement) {
                return $this->getMapper()
                            ->mapToArray(
                                $statement, new KeyValueEntity(),
                                function (KeyValueEntity $entity) {
                                    static $count = 0;
                                    if (empty($entity->key)) {
                                        $entity->key = $count++;
                                    }

                                    return [
                                        'key'   => $entity->key,
                                        'value' => $entity->value,
                                    ];
                                }
                            );
            }
        );
    }

    /**
     * Validates whether the given statement has a row count greater than zero
     *
     * @param SqlQuery $query
     * @return bool whether there is at least one result row or not
     * @deprecated use SqlQuery::requiresResult() instead and throw exception
     */
    public function handleHasResult(SqlQuery $query)
    {
        return $this->handleHas($query, false);
    }

    /**
     * Runs the query and returns whether the row count is equal to one or not
     *
     * @param SqlQuery $query      the query
     * @param bool     $forceEqual if set to true, only a row count of one and
     *                             only one returns true
     * @return bool whether there is a row or not
     * @deprecated use SqlQuery::requiresResult() instead and throw exception
     */
    public function handleHas(SqlQuery $query, $forceEqual = true)
    {
        $stmt = $this->prepare($query);

        return $this->handleGeneric(
            $stmt,
            function (PdoStatement $statement) use ($forceEqual) {
                if ($statement->rowCount() == 1 && $forceEqual) {
                    return true;
                } else {
                    if ($statement->rowCount() > 0 && !$forceEqual) {
                        return true;
                    }
                }

                return false;
            }
        );
    }

    /**
     * Returns the value of SQL_CALC_FOUND_ROWS
     *
     * @return int
     * @throws \ChrisAndChris\Common\RowMapperBundle\Exceptions\RowMapperException
     * @throws \ChrisAndChris\Common\RowMapperBundle\Exceptions\NotCapableException
     */
    public function getFoundRowCount()
    {
        if ($this->lastStatement->isCalcRowCapable() == false) {
            throw new NotCapableException(
                'Last executed query is not capable to run FOUND_ROWS() on it'
            );
        }

        // @formatter:off
        $query = $this->getDependencyProvider()->getBuilder()->select()
            ->f('FOUND_ROWS')->close()
            ->getSqlQuery();
        // @formatter:on

        return (int)$this->runWithFirstKeyFirstValue($query);
    }

    /**
     * Call query and get first column of first row
     *
     * @param SqlQuery $query
     * @param bool     $autoCast if true, result value will be casted
     * @return mixed
     * @throws \ChrisAndChris\Common\RowMapperBundle\Exceptions\Database\NoSuchRowFoundException
     */
    public function runWithFirstKeyFirstValue(
        SqlQuery $query,
        bool $autoCast = false
    ) {
        $stmt = $this->prepare($query);

        return $this->handleGeneric(
            $stmt, function (PdoStatement $statement) use ($query, $autoCast) {
            if ($statement->rowCount() > 1) {
                throw new DatabaseException(sprintf(
                    'Expected only a single result record, but got %d',
                    $statement->rowCount()
                ));
            }

            $result = $statement->fetch(\PDO::FETCH_NUM)[0];
            $values = array_values($query->getMappingInfo());
            if ($autoCast && count($values) > 0) {
                $result = $this->getMapper()->typeCaster->cast(
                    $values[0],
                    $result
                );
            }

            return $result;
        });
    }
}
