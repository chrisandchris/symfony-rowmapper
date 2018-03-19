<?php

namespace ChrisAndChris\Common\RowMapperBundle\Services\Mapper;

use ChrisAndChris\Common\RowMapperBundle\Entity\EmptyEntity;
use ChrisAndChris\Common\RowMapperBundle\Entity\Entity;
use ChrisAndChris\Common\RowMapperBundle\Entity\PopulateEntity;
use ChrisAndChris\Common\RowMapperBundle\Entity\StrictEntity;
use ChrisAndChris\Common\RowMapperBundle\Entity\WeakEntity;
use ChrisAndChris\Common\RowMapperBundle\Events\Mapping\PopulationEvent;
use ChrisAndChris\Common\RowMapperBundle\Events\MappingEvents;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\DatabaseException;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\InvalidOptionException;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\Mapping\InsufficientPopulationException;
use ChrisAndChris\Common\RowMapperBundle\Services\Mapper\Encryption\EncryptionServiceInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @name RowMapper
 * @version    2.0.1
 * @since      v1.0.0
 * @package    RowMapperBundle
 * @author     ChrisAndChris
 * @link       https://github.com/chrisandchris
 */
class RowMapper
{

    /** @var \ChrisAndChris\Common\RowMapperBundle\Services\Mapper\TypeCaster */
    public $typeCaster;
    /**
     * @var EncryptionServiceInterface[]
     */
    private $encryptionServices = [];
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * RowMapper constructor.
     *
     * @param EventDispatcherInterface                                         $eventDispatcher
     * @param \ChrisAndChris\Common\RowMapperBundle\Services\Mapper\TypeCaster $typeCaster
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        TypeCaster $typeCaster
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->typeCaster = $typeCaster;
    }

    /**
     * Add a new encryption ability
     *
     * @param EncryptionServiceInterface $encryptionService
     */
    public function addEncryptionAbility(
        EncryptionServiceInterface $encryptionService
    ) {
        $this->encryptionServices[] = $encryptionService;
    }

    /**
     * Map a single result from a statement
     *
     * @param \PDOStatement $statement the statement to map
     * @param Entity        $entity    the entity to map into
     * @return Entity
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function mapSingleFromResult(
        \PDOStatement $statement,
        Entity $entity
    ) {
        $list = $this->mapFromResult($statement, $entity, 1);
        if (count($list) == 0) {
            throw new NotFoundHttpException;
        }

        return $list[0];
    }

    /**
     * Maps a result from a statement into an entity
     *
     * @param \PDOStatement $statement   the statement to map
     * @param Entity        $entity      the entity to map to
     * @param int           $limit       max amount of rows to map
     * @param array         $mappingInfo mapping information (typecaset e.g.)
     * @return Entity[] list of entities
     */
    public function mapFromResult(
        \PDOStatement $statement,
        Entity $entity = null,
        $limit = null,
        array $mappingInfo = []
    ) {
        $return = [];
        $count = 0;
        while (false !== ($row = $statement->fetch(\PDO::FETCH_ASSOC)) &&
            (++$count <= $limit || $limit === null)) {
            if ($entity === null) {
                $return[] = $this->mapRow($row, null, $mappingInfo);
            } else {
                $return[] = $this->mapRow($row, clone $entity, $mappingInfo);
            }
        }

        return $return;
    }

    /**
     * Map a single row by calling setter if possible or
     * accessing the properties directly if no setter available<br />
     * <br />
     * The setter name is generated by a key of $row, by following rules:<br />
     * <ul>
     *  <li>underscores are removed, next letter is uppercase</li>
     *  <li>first letter goes uppercase</li>
     *  <li>a "set" string is added to the beginning</li>
     * </ul>
     *
     * @param array $row         the row to map
     * @param       $entity      entity to map to
     * @param array $mappingInfo mapping info (typecast e.g.)
     * @return array|Entity $entity the mapped entity
     * @throws InvalidOptionException if EmptyEntity is given
     */
    public function mapRow(
        array $row,
        Entity $entity = null,
        array $mappingInfo = []
    ) {
        if ($entity instanceof EmptyEntity) {
            throw new InvalidOptionException(
                'You are not allowed to map rows to an EmptyEntity instance'
            );
        }
        if (!isset($entity)) {
            $entity = new EmptyEntity();
        }
        $this->populateFields($row, $entity, $mappingInfo);

        $entity = $this->checkForDecryption($entity);

        return $entity;
    }

    /**
     * @param array  $row                      the row to map
     * @param Entity $entity                   entity to map to
     * @param array  $mappingInfo              mapping info (typecast e.g.)
     * @throws InsufficientPopulationException if strict entity is not fully
     *                                         populated
     */
    public function populateFields(
        array $row,
        Entity $entity,
        array $mappingInfo
    ) {
        $entityFiller = $this->getEntityFiller();

        $count = 0;
        foreach ($row as $field => $value) {
            $count += $entityFiller($entity, $field, $value, $mappingInfo);
        }

        if ($entity instanceof PopulateEntity) {
            $event = $this->eventDispatcher->dispatch(
                MappingEvents::POST_MAPPING_ROW_POPULATION,
                new PopulationEvent($entity, $entityFiller)
            );
            $count += $event->getWrittenFieldCount();
        }

        if ($entity instanceof StrictEntity && count($row) != $count) {
            throw new InsufficientPopulationException(
                sprintf(
                    'Requires entity "%s" to get populated for %d fields, but did only %d',
                    get_class($entity),
                    count($row),
                    $count
                )
            );
        }
    }

    /**
     * @return \Closure
     */
    private function getEntityFiller() : \Closure
    {
        return
            function (
                Entity &$entity,
                $field,
                $value,
                $mappingInfo = [],
                $weak = false
            ) {
                $methodName = $this->buildMethodName($field);

                // cast if necessary
                if (isset($mappingInfo[$field]) &&
                    ($value !== null || $mappingInfo[$field] === 'bool')) {
                    $value = $this->getTypeCaster()
                                  ->cast($mappingInfo[$field], $value);
                }

                if ($weak) {
                    // dash-case to camelCase when we're using weak mode
                    $field = str_replace('_', '', ucwords($field, '_'));
                    $field = lcfirst($field);
                }

                if (method_exists($entity, $methodName)) {
                    // set using method set{property}
                    $entity->$methodName($value);

                    return 1;
                } elseif (property_exists($entity, $field) ||
                    $entity instanceof EmptyEntity
                ) {
                    // set direct to property
                    $entity->$field = $value;

                    return 1;
                } elseif (!($entity instanceof WeakEntity) && !$weak) {
                    throw new DatabaseException(
                        sprintf('No property %s found for Entity',
                            $field)
                    );
                }

                return 0;
            };
    }

    /**
     * Build a method name
     *
     * @param $key
     * @return string
     */
    public function buildMethodName($key)
    {
        $partials = explode('_', $key);
        foreach ($partials as $idx => $part) {
            $partials[$idx] = ucfirst($part);
        }

        return 'set' . implode('', $partials);
    }

    private function getTypeCaster()
    {
        return $this->typeCaster;
    }

    /**
     * @param Entity $entity
     * @return array|EmptyEntity|Entity
     */
    private function checkForDecryption(Entity $entity)
    {
        $entity = $this->runDecryption($entity);

        if ($entity instanceof EmptyEntity) {
            $fields = [];
            foreach (get_object_vars($entity) as $property => $value) {
                $fields[$property] = $value;
            }
            $entity = $fields;

            return $entity;
        }

        return $entity;
    }

    /**
     * Run the decryption process
     *
     * @param Entity $entity
     * @return Entity
     */
    private function runDecryption(Entity $entity)
    {
        foreach ($this->encryptionServices as $encryptionService) {
            if ($encryptionService->isResponsible($entity)) {
                return $encryptionService->decrypt($entity);
            }
        }

        return $entity;
    }

    /**
     * Maps a statement to an associative array<br />
     * <br />
     * The closure is used to map any row, it must give back an array.<br />
     * The array <i>may</i> contain an index "key" with the desired key value
     * of the returned array and it <i>must</i> contain an index "value" with
     * the value to map
     *
     *
     * @param \PDOStatement $statement the statement to map
     * @param Entity        $entity    the entity to map from
     * @param \Closure      $callable  the callable to use to map any row
     * @return array
     * @throws InvalidOptionException if invalid input is given
     */
    public function mapToArray($statement, Entity $entity, \Closure $callable)
    {
        $array = $this->mapFromResult($statement, $entity);
        $return = [];
        foreach ($array as $row) {
            $a = $callable($row);
            if (!is_array($a)) {
                throw new InvalidOptionException('Callable must return array with at least index "value"');
            }
            if (isset($a['key']) && !empty($a['key'])) {
                $return[$a['key']] = $a['value'];
            } else {
                $return[] = $a['value'];
            }
        }

        return $return;
    }
}
