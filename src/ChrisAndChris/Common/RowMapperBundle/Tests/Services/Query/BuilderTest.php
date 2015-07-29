<?php
namespace ChrisAndChris\Common\RowMapperBundle\Tests\Services\Query;

use ChrisAndChris\Common\RowMapperBundle\Exceptions\MalformedQueryException;
use ChrisAndChris\Common\RowMapperBundle\Services\Query\Builder;
use ChrisAndChris\Common\RowMapperBundle\Services\Query\Parser\DefaultParser;
use ChrisAndChris\Common\RowMapperBundle\Services\Query\Parser\SnippetBag;
use ChrisAndChris\Common\RowMapperBundle\Services\Query\Parser\TypeBag;
use ChrisAndChris\Common\RowMapperBundle\Services\Query\SqlQuery;
use ChrisAndChris\Common\RowMapperBundle\Tests\TestKernel;

/**
 * @name BuilderTest
 * @version   2
 * @since     v2.0.0
 * @package   RowMapperBundle
 * @author    ChrisAndChris
 * @link      https://github.com/chrisandchris
 */
class BuilderTest extends TestKernel {

    function testSimpleQuery() {
        // @formatter:off
        $Builder = $this->getBuilder();
        $Builder->select()
            ->fieldlist([
                'field1' => 'aliasName',
                'field2'
            ])
            ->table('foobar')
            ->join('nexttable')
                ->using('usingField')
            ->join('newtable', 'left')
                ->on()
                    ->field(['lefttable', 'leftfield'])
                    ->equals()->value('1')
                ->close()
            ->where()
                ->brace()
                    ->select()
                    ->fieldlist([
                        'subfield1'
                    ])
                    ->table('subtable')
                    ->limit(1)
                ->close()
                ->equals()->value(13)
                ->connect('&')->field('field1')
                ->equals()->value('value1')
                ->connect('&')
                ->brace()
                    ->field('field2')
                    ->equals()->value(-1)
                    ->connect('|')->field('field2')
                    ->equals()->field('field3')
                ->close()
            ->close()
            ->orderBy([
                'field1',
                'field2'
            ])
            ->limit(10)
        ;
        // @formatter:on

        $query = $Builder->getSqlQuery();

        $this->assertTrue($query instanceof SqlQuery);
        $this->assertEquals(4, count($query->getParameters()));
    }

    private function getBuilder() {
        $typeBag = new TypeBag();
        $snippetBag = new SnippetBag();

        return new Builder(new DefaultParser($typeBag, $snippetBag), $typeBag);
    }

    public function testSelect() {
        $Builder = $this->getBuilder();
        $Builder->select();

        $this->equals('SELECT', $Builder);
    }

    private function equals($expected, Builder $Builder) {
        $this->assertEquals(
            $expected, $this->minify(
            $Builder->getSqlQuery()
                    ->getQuery()
        )
        );
    }

    private function minify($query) {
        while (strstr($query, '  ') !== false) {
            $query = str_replace('  ', ' ', $query);
        }

        return $query;
    }

    public function testUpdate() {
        $B = $this->getBuilder();
        $B->update('table1');
        $this->equals('UPDATE `table1` SET', $B);
    }

    public function testInsert() {
        $B = $this->getBuilder();
        $B->insert('table1');
        $this->equals('INSERT INTO `table1`', $B);
    }

    public function testDelete() {
        $B = $this->getBuilder();
        $B->delete('table1');
        $this->equals('DELETE FROM `table1`', $B);
    }

    public function testTable() {
        $Builder = $this->getBuilder();
        $Builder->table('table');

        $this->equals('FROM `table`', $Builder);
    }

    public function testFieldlist() {
        $B = $this->getBuilder();
        $B->fieldlist(
            [
                'field1',
            ]
        );
        $this->equals('`field1`', $B);

        $B = $this->getBuilder();
        $B->fieldlist(
            [
                'field1',
                'field2',
            ]
        );
        $this->equals('`field1`, `field2`', $B);

        $B = $this->getBuilder();
        $B->fieldlist(
            [
                'field1' => 'alias1',
            ]
        );
        $this->equals('`field1` as `alias1`', $B);

        $B = $this->getBuilder();
        $B->fieldlist(
            [
                'field1' => 'alias1',
                'field2' => 'alias2',
            ]
        );
        $this->equals('`field1` as `alias1`, `field2` as `alias2`', $B);

        $B = $this->getBuilder();
        $B->fieldlist(
            [
                'field1' => 'alias1',
                'field2',
            ]
        );
        $this->equals('`field1` as `alias1`, `field2`', $B);
    }

    public function testWhere() {
        $B = $this->getBuilder();
        $B->where()
          ->field('field1')
          ->equals()
          ->value('1')
          ->close();
        // be careful, two whitespaces after WHERE
        $this->equals('WHERE `field1` = ?', $B);

        $B = $this->getBuilder();
        $B->where()
          ->field('field1')
          ->equals()
          ->value('1')
          ->connect()
          ->field('field2')
          ->equals()
          ->field('field3')
          ->close();

        $this->equals('WHERE `field1` = ? AND `field2` = `field3`', $B);
    }

    public function testField() {
        $B = $this->getBuilder();
        $B->field('field1');
        $this->equals('`field1`', $B);

        $B = $this->getBuilder();
        $B->field(['table', 'field']);
        $this->equals('`table`.`field`', $B);

        $B = $this->getBuilder();
        $B->field(['database', 'table', 'field']);
        $this->equals('`database`.`table`.`field`', $B);
    }

    public function testEquals() {
        $B = $this->getBuilder();
        $B->equals();
        $this->equals('=', $B);
    }

    public function testValue() {
        $B = $this->getBuilder();
        $B->value('value1');
        $Query = $B->getSqlQuery();

        $this->assertEquals('?', $Query->getQuery());
        $this->assertEquals('value1', $Query->getParameters()[0]);
        $this->assertEquals(1, count($Query->getParameters()));

        // builder is empty after parsing
        $Query = $B->getSqlQuery();
        $this->assertEquals(0, strlen($Query->getQuery()));
        $this->assertEquals(0, count($Query->getParameters()));
    }

    public function testBrace() {
        $B = $this->getBuilder();
        $B->brace()
          ->close();
        $this->equals('( )', $B);
    }

    public function testLimit() {
        $B = $this->getBuilder();
        $B->limit(1);
        $this->equals('LIMIT 1', $B);

        $B = $this->getBuilder();
        $B->limit(123);
        $this->equals('LIMIT 123', $B);

        $B = $this->getBuilder();
        $B->limit(-1);
        $this->equals('LIMIT 1', $B);
    }

    public function testJoin() {
        $B = $this->getBuilder();
        $B->join('table1');
        $this->equals('INNER JOIN `table1`', $B);

        $B = $this->getBuilder();
        $B->join('table1', 'left');
        $this->equals('LEFT JOIN `table1`', $B);

        $B = $this->getBuilder();
        $B->join('table1', 'right');
        $this->equals('RIGHT JOIN `table1`', $B);
    }

    public function testUsing() {
        $B = $this->getBuilder();
        $B->using('field1');
        $this->equals('USING(`field1`)', $B);
    }

    public function testOn() {
        $B = $this->getBuilder();
        $B->on()
          ->field('field1')
          ->equals()
          ->field('field2')
          ->close();
        $this->equals('ON ( `field1` = `field2` )', $B);

        $B = $this->getBuilder();
        $B->on()
          ->field(['t1', 'field1'])
          ->equals()
          ->field(['t2', 'field2'])
          ->close();
        $this->equals('ON ( `t1`.`field1` = `t2`.`field2` )', $B);
    }

    public function testGroupBy() {
        $B = $this->getBuilder();
        $B->groupBy('field1');
        $this->equals('GROUP BY `field1`', $B);

        $B = $this->getBuilder();
        $B->groupBy()
          ->field('field1')
          ->c()
          ->field('field2')
          ->close();
        $this->equals('GROUP BY `field1` , `field2`', $B);
    }

    public function testOrder() {
        $B = $this->getBuilder();
        $B->order()
          ->by('field1')
          ->close();
        $this->equals('ORDER BY `field1` DESC', $B);

        $B = $this->getBuilder();
        $B->order()
          ->by('field1', 'asc')
          ->close();
        $this->equals('ORDER BY `field1` ASC', $B);

        $B = $this->getBuilder();
        $B->order()
          ->by('field1', 'asc')
          ->c()
          ->by('field2')
          ->close();
        $this->equals('ORDER BY `field1` ASC , `field2` DESC', $B);
    }

    public function testOderBy() {
        $B = $this->getBuilder();
        $B->orderBy(
            [
                'field1',
            ]
        );
        $this->equals('ORDER BY `field1` DESC', $B);

        $B = $this->getBuilder();
        $B->orderBy(
            [
                'field1' => 'asc',
            ]
        );
        $this->equals('ORDER BY `field1` ASC', $B);

        $B = $this->getBuilder();
        $B->orderBy(
            [
                'field1' => 'asc',
                'field2',
            ]
        );
        $this->equals('ORDER BY `field1` ASC , `field2` DESC', $B);
    }

    public function testConnect() {
        $B = $this->getBuilder();
        $B->connect('&');
        $this->equals('AND', $B);

        $B = $this->getBuilder();
        $B->connect('&&');
        $this->equals('AND', $B);

        $B = $this->getBuilder();
        $B->connect('aNd');
        $this->equals('AND', $B);

        $B = $this->getBuilder();
        $B->connect('|');
        $this->equals('OR', $B);

        $B = $this->getBuilder();
        $B->connect('||');
        $this->equals('OR', $B);

        $B = $this->getBuilder();
        $B->connect('oR');
        $this->equals('OR', $B);

        try {
            $B = $this->getBuilder();
            $B->connect('123');
            $this->fail('Must fail due to unknown connection type');
        } catch (\Exception $e) {
            // ignore
        }
    }

    public function testC() {
        $B = $this->getBuilder();
        $B->c();
        $this->equals(',', $B);
    }

    public function testGetSqlQuery() {
        $Builder = $this->getBuilder();
        $this->assertTrue($Builder->getSqlQuery() instanceof SqlQuery);
    }

    public function testComparison() {
        $tests = [
            '<=',
            '<',
            '>=',
            '>',
            '<>',
            '!=',
            '=',
        ];
        foreach ($tests as $test) {
            $builder = $this->getBuilder();
            $builder->compare($test)
                    ->getSqlQuery();
        }

        $tests = [
            '>>',
            '<<',
            '1',
            'a',
            '()',
        ];
        foreach ($tests as $test) {
            try {
                $builder = $this->getBuilder();
                $builder->compare($test)
                        ->getSqlQuery();
                $this->fail('Must fail due to unknown comparison type');
            } catch (MalformedQueryException $e) {
                // ignore
            }
        }
    }
}
