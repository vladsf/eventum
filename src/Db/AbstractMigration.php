<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

namespace Eventum\Db;

use LazyProperty\LazyPropertiesTrait;
use PDO;
use Phinx;
use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Db\Table;
use Phinx\Migration\AbstractMigration as PhinxAbstractMigration;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractMigration extends PhinxAbstractMigration
{
    use LazyPropertiesTrait;

    // According to https://dev.mysql.com/doc/refman/5.0/en/blob.html BLOB sizes are the same as TEXT
    protected const BLOB_TINY = MysqlAdapter::BLOB_TINY;
    protected const BLOB_REGULAR = MysqlAdapter::BLOB_REGULAR;
    protected const BLOB_MEDIUM = MysqlAdapter::BLOB_MEDIUM;
    protected const BLOB_LONG = MysqlAdapter::BLOB_LONG;

    protected const INT_TINY = MysqlAdapter::INT_TINY;
    protected const INT_SMALL = MysqlAdapter::INT_SMALL;
    protected const INT_MEDIUM = MysqlAdapter::INT_MEDIUM;
    protected const INT_REGULAR = MysqlAdapter::INT_REGULAR;
    protected const INT_BIG = MysqlAdapter::INT_BIG;

    protected const TEXT_TINY = MysqlAdapter::TEXT_TINY;
    protected const TEXT_SMALL = MysqlAdapter::TEXT_SMALL;
    protected const TEXT_REGULAR = MysqlAdapter::TEXT_REGULAR;
    protected const TEXT_MEDIUM = MysqlAdapter::TEXT_MEDIUM;
    protected const TEXT_LONG = MysqlAdapter::TEXT_LONG;

    protected const PHINX_TYPE_BLOB = MysqlAdapter::PHINX_TYPE_BLOB;
    protected const PHINX_TYPE_STRING = MysqlAdapter::PHINX_TYPE_STRING;

    protected const ENCODING_ASCII = 'ascii';
    protected const COLLATION_ASCII = 'ascii_general_ci';

    /**
     * MySQL Engine
     *
     * @var $engine
     */
    protected $engine;

    /**
     * MySQL Charset
     *
     * @var $string
     */
    protected $charset;

    /**
     * MySQL Collation
     *
     * @var $string
     */
    protected $collation;

    public function init(): void
    {
        $this->initLazyProperties([
            /** @see getCharset */
            'charset',
            /** @see getCollation */
            'collation',
            /** @see getEngine */
            'engine',
        ]);
    }

    /**
     * Override until upstream adds support
     *
     * @see https://github.com/robmorgan/phinx/pull/810
     */
    public function table($tableName, $options = []): Table
    {
        $options['engine'] = $options['engine'] ?? $this->engine;
        $options['charset'] = $options['charset'] ?? $this->charset;
        $options['collation'] = $options['collation'] ?? $this->collation;

        return parent::table($tableName, $options);
    }

    protected function quoteColumnName(string $columnName): string
    {
        return $this->getAdapter()->quoteColumnName($columnName);
    }

    protected function quoteTableName(string $tableName): string
    {
        return $this->getAdapter()->quoteTableName($tableName);
    }

    /**
     * Quote field value.
     * As long as execute() does not take params, we need to quote values.
     *
     * @see https://github.com/robmorgan/phinx/pull/850
     */
    protected function quote(string $value, int $parameter_type = PDO::PARAM_STR): string
    {
        /** @var MysqlAdapter $adapter */
        $adapter = $this->getAdapter();

        return $adapter->getConnection()->quote($value, $parameter_type);
    }

    /**
     * Run SQL Query, return single result.
     */
    protected function queryOne(string $sql, $column = '0'): ?string
    {
        $rows = $this->queryColumn($sql, $column);

        if (!$rows) {
            return null;
        }

        return $rows[0];
    }

    /**
     * Run SQL Query, return single column.
     */
    protected function queryColumn(string $sql, string $column): array
    {
        $st = $this->query($sql);
        $rows = [];
        foreach ($st as $row) {
            $rows[] = $row[$column];
        }

        return $rows;
    }

    /**
     * Run SQL Query, return key => value pairs
     */
    protected function queryPair(string $sql, string $keyColumn, string $valueColumn): array
    {
        $rows = [];
        foreach ($this->query($sql) as $row) {
            $key = $row[$keyColumn];

            $rows[$key] = $row[$valueColumn];
        }

        return $rows;
    }

    /**
     * Return columns indexed by column names
     *
     * @param Table $table
     * @param string[] $columnNames
     * @return Table\Column[]
     */
    protected function getColumns(Table $table, array $columnNames = []): array
    {
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[$column->getName()] = $column;
        }

        if ($columnNames) {
            return array_intersect_key($columns, array_flip($columnNames));
        }

        return $columns;
    }

    protected function getColumn(string $tableName, string $columnName): Table\Column
    {
        $table = $this->table($tableName);
        $columns = $this->getColumns($table, [$columnName]);

        return $columns[$columnName];
    }

    /**
     * Writes a message to the output and adds a newline at the end.
     *
     * @param string|array $messages The message as an array of lines of a single string
     * @param int $options A bitmask of options (one of the OUTPUT or VERBOSITY constants)
     */
    protected function writeln($messages, $options = OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL): void
    {
        $this->output->writeln($messages, $options);
    }

    protected function createProgressBar(int $total): ProgressBar
    {
        $progressBar = new ProgressBar($this->output, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% | %message% ');
        $progressBar->setMessage('');

        return $progressBar;
    }

    private function getCharset(): string
    {
        return $this->getAdapter()->getOptions()['charset'];
    }

    private function getCollation(): string
    {
        return $this->getAdapter()->getOptions()['collation'];
    }

    private function getEngine(): string
    {
        return $this->getAdapter()->getOptions()['engine'];
    }
}
