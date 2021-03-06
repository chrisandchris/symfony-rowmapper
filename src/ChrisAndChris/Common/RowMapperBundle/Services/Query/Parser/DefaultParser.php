<?php
namespace ChrisAndChris\Common\RowMapperBundle\Services\Query\Parser;

use ChrisAndChris\Common\RowMapperBundle\Events\RowMapperEvents;
use ChrisAndChris\Common\RowMapperBundle\Events\Transmitters\SnippetBagEvent;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\MalformedQueryException;
use ChrisAndChris\Common\RowMapperBundle\Exceptions\MissingParameterException;
use ChrisAndChris\Common\RowMapperBundle\Services\Query\Parser\Snippets\MySqlBag;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @name DefaultParser
 * @version   1.1.1
 * @since     v2.0.0
 * @package   RowMapperBundle
 * @author    ChrisAndChris
 * @link      https://github.com/chrisandchris
 */
class DefaultParser implements ParserInterface {

    /** @var array mapping info (typecast) */
    public $mappingInfo = [];
    /**
     * The statement array
     *
     * @var array
     */
    private $statement;
    /**
     * The generated query
     *
     * @var string
     */
    private $query = [];
    /**
     * An array of open braces
     *
     * @var array
     */
    private $braces = [];
    /**
     * An ordered array of parameters used in the query
     *
     * @var array
     */
    private $parameters = [];
    /** @var MySqlBag */
    private $snippetBag;
    /** @var EventDispatcherInterface */
    private $eventDispatcher;
    /** @var string */
    private $subsystem;

    /**
     * Initialize class
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param  string                  $subsystem the database system to use
     */
    function __construct(EventDispatcherInterface $eventDispatcher, $subsystem)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->subsystem = $subsystem;
    }

    function setStatement(array $statement) {
        $this->statement = $statement;
    }

    function getSqlQuery() {
        if (!is_array($this->query)) {
            $this->query = [];
        }

        return trim(implode(' ', $this->query));
    }

    function getMappingInfo()
    {
        return $this->mappingInfo;
    }

    function getParameters() {
        return $this->parameters;
    }

    /**
     * Run the parser
     *
     * @throws MalformedQueryException
     */
    public function execute() {
        $this->clear();
        if (is_string($this->query)) {
            $this->query = [];
        }
        foreach ($this->statement as $type) {
            $snippet = $this->getSnippet($type['type']);
            $this->query[] = $this->parseCode($type, $snippet);
        }

        if (count($this->braces) != 0) {
            throw new MalformedQueryException("There are still open braces.");
        }
    }

    /**
     * Clear and prepare builder for next query
     */
    private function clear() {
        $this->mappingInfo = [];
        $this->parameters = [];
        $this->query = [];
        $this->braces = [];
    }

    /**
     * Gets an instance of a snippet
     *
     * @param string $type the snippet name
     * @return \Closure
     */
    private function getSnippet($type) {
        if ($this->snippetBag === null) {
            $event = $this->eventDispatcher->dispatch(RowMapperEvents::SNIPPET_COLLECTOR, new SnippetBagEvent());
            $this->snippetBag = $event->getBag(
                $this->detectSubsystem($this->subsystem)
            );
        }

        return $this->snippetBag->get($type);
    }

    /**
     * Detects the current subsystem or returns false
     *
     * @param $subsystem
     * @return bool|mixed
     */
    private function detectSubsystem($subsystem)
    {
        $tests = [
            'mysql',
            'pgsql',
            'sqlite',
        ];
        foreach ($tests as $test) {
            if (strstr($subsystem, $test) !== false) {
                return $test;
            }
        }

        return false;
    }

    /**
     * Parses the code
     *
     * @param array    $type    the type interface to use
     * @param \Closure $snippet the snippet interface to use
     * @return string the generated query
     * @throws \ChrisAndChris\Common\RowMapperBundle\Exceptions\MalformedQueryException
     * @throws \ChrisAndChris\Common\RowMapperBundle\Exceptions\MissingParameterException
     */
    private function parseCode($type, \Closure $snippet) {
        if (!array_key_exists('params', $type)) {
            throw new MissingParameterException(
                'Missing parameters for type "' . $type['type'] . '"'
            );
        }
        $result = $snippet($type['params']);

        if (!isset($result['code'])) {
            throw new MalformedQueryException(
                'Invalid result of snippet named "' . $type['type'] . '"'
            );
        }
        if (!is_array($result['params'])) {
            $result['params'] = [$result['params']];
        }

        if ($result['code'] == '/@close') {
            $result['code'] = $this->minimizeBrace();
        }

        $this->checkForParameters($type, $result['code'], $result['params']);
        $result['code'] = $this->checkForMethodChaining($result['code']);

        if (isset($result['mapping_info'])) {
            $this->addMappingInfo($result['mapping_info']);
        }

        return $result['code'];
    }

    /**
     * Closes a brace
     *
     * @return string
     * @throws MalformedQueryException
     */
    private function minimizeBrace() {
        if (count($this->braces) === 0) {
            throw new MalformedQueryException(
                'You must open braces before closing them.'
            );
        }

        $maxKey = max(array_keys($this->braces));
        $merges = [
            $this->braces[$maxKey]['query'],
            $this->braces[$maxKey]['before'],
            $this->query,
            $this->braces[$maxKey]['after'],
        ];

        $this->query = [];
        foreach ($merges as $merge) {
            if (is_array($merge)) {
                foreach ($merge as $entry) {
                    $this->query[] = $entry;
                }
            } else {
                $this->query[] = $merge;
            }
        }
        $code = '';
        unset($this->braces[max(array_keys($this->braces))]);

        return $code;
    }

    /**
     * Checks for parameters used in the code
     *
     * @param array  $type
     * @param string $code
     * @param        $params
     * @throws MissingParameterException
     */
    private function checkForParameters($type, $code, $params) {
        // detect parameters
        $offset = 0;
        $idx = 0;
        while (false !== ($pos = mb_strpos($code, '?', $offset))) {
            $offset = $pos + 1;
            if (!array_key_exists($idx, $params)) {
                throw new MissingParameterException(
                    'Missing parameter of type "' . $type['type'] . '"'
                );
            }
            $this->addParameter($params[$idx++]);
        }
    }

    /**
     * Add a used parameter
     *
     * @param $parameter
     */
    private function addParameter($parameter) {
        $this->parameters[] = $parameter;
    }

    /**
     * Checks for method chains (braces)
     *
     * @param $code
     * @return string
     */
    private function checkForMethodChaining($code) {
        // collect method chaining
        $matches = [];
        $match = preg_match('/(.*)\/@brace\(([a-z]+)\)(.*)/s', $code, $matches);
        if ($match > 0) {
            /*
             * $match:
             * 0        complete match
             * 1        before
             * 2        brace name
             * 3        after
             */
            $this->braces[] = [
                'query'  => $this->query,
                'before' => $matches[1],
                'after'  => $matches[3],
                'key'    => $matches[2],
            ];
            // empty query
            $this->query = [];
            // empty code
            $code = '';

            return $code;
        }

        return $code;
    }

    private function addMappingInfo($mappingInfo)
    {
        $this->mappingInfo = array_merge(
            $this->mappingInfo,
            $mappingInfo
        );
    }
}
