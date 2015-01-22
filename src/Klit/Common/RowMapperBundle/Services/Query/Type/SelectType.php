<?php
namespace Klit\Common\RowMapperBundle\Services\Query\Type;
/**
 * @name SelectType
 * @version 1.0.0-dev
 * @package CommonRowMapper
 * @author Christian Klauenbösch <christian@klit.ch>
 * @copyright Klauenbösch IT Services
 * @link http://www.klit.ch
 */
class SelectType implements TypeInterface {
    /**
     * @inheritdoc
     */
    function getTypeName() {
        return 'select';
    }

    /**
     * @inheritdoc
     */
    function getAllowedChildren() {
        return array(
            new FieldlistType()
        );
    }

    /**
     * @inheritdoc
     */
    function call($data) {
        // ignore
    }
}
