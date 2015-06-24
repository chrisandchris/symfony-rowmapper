<?php
namespace ChrisAndChris\Common\RowMapperBundle\Services\Query\Parser;

use ChrisAndChris\Common\RowMapperBundle\Services\Query\Type\TypeInterface;

/**
 * @name SnippetInterface
 * @version   1.0.0
 * @since     v2.0.0
 * @package   RowMapperBundle
 * @author    ChrisAndChris
 * @link      https://github.com/chrisandchris
 */
interface SnippetInterface {

    /**
     * Set the type interface
     *
     * @param TypeInterface $type
     */
    function setType(TypeInterface $type);

    /**
     * Get the code
     *
     * @return string
     */
    function getCode();
}