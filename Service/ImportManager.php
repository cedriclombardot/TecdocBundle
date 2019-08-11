<?php

namespace Gweb\TecdocBundle\Service;

use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\DBAL\Connection;
use Gweb\TecdocBundle\Annotation\Column;
use Gweb\TecdocBundle\Annotation\Table;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Import tecdoc fixed width files to database
 *
 * @author Gerd Weitenberg <gweitenb@gmail.com>
 */
class ImportManager
{
    /**
     * @var string
     */
    private $dirReference;

    /**
     * @var string
     */
    private $dirSupplier;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * ImportService constructor.
     * @param ObjectManager $entityManager
     * @param string $dirReference
     * @param string $dirSupplier
     */
    public function __construct(ObjectManager $entityManager, string $dirReference, string $dirSupplier)
    {
        $this->entityManager = $entityManager;
        $this->dirReference = $dirReference;
        $this->dirSupplier = $dirSupplier;
    }

    /**
     * Import all entity files
     * @param string $entityClass
     * @return array
     * @throws \Exception
     */
    public function importEntity(string $entityClass): array
    {
        $fileAnnotation = new FileAnnotation($entityClass);

        $files = $this->getEntityFiles($fileAnnotation->getTable());
        $result = [];
        foreach ($files as $fileName) {
            try {
                $result[$fileName] = $this->importFile(
                    $fileName,
                    $this->entityManager->getClassMetadata($entityClass)->getTableName(),
                    $fileAnnotation->getColumns()
                );

                rename($fileName, $fileName.'.processed');
            } catch (FileNotFoundException $e) {
                $result[$fileName] = null;
            }
        }

        return $result;
    }

    /**
     * Get all tecdoc entities
     * @return array
     * @throws \Doctrine\ORM\ORMException
     */
    public function getEntities(): array
    {
        $entities = $this->entityManager->getConfiguration()->getMetadataDriverImpl()->getAllClassNames();
        sort($entities);

        return $entities;
    }

    /**
     * Get all tecdoc entity files
     * @param Table $table
     * @return array
     * @throws \Exception
     */
    public function getEntityFiles(Table $table): array
    {
        if (getenv('FILE')) {
            return [
                getenv('FILE'),
            ];
        }
        $files = [];

        $filesystem = new Filesystem();

        if ($table->reference) {
            $fileName = $this->dirReference.'/'.$table->name.'.dat';

            if ($filesystem->exists($fileName)) {
                $files[] = $fileName;
            }
        }

        if ($table->provider) {
            foreach ($this->getSuppliers() as $supplier) {
                if ($supplier == '9999') {
                    continue;
                }

                $fileName = $this->dirSupplier.'/'.$supplier.'/'.$table->name.'.'.$supplier;

                if ($filesystem->exists($fileName)) {
                    $files[] = $fileName;
                }
            }
        }

        return $files;
    }

    /**
     * Get all supplier ID's from directory names
     * @return array
     * @throws \Exception
     */
    public function getSuppliers(): array
    {
        $supplier = [];

        $finder = new Finder();
        $finder->directories()->in($this->dirSupplier)->depth(0)->sortByName();

        if (!$finder->hasResults()) {
            throw new \Exception('Supplier directories not found');
        }

        foreach ($finder as $dir) {
            $supplier[] = $dir->getBasename();
        }

        return $supplier;
    }

    /**
     * Import single file to database table
     * @param string $fileName
     * @param string $tableName
     * @param Column[] $columns
     * @return int
     * @throws \Doctrine\DBAL\ConnectionException
     * @throws \Doctrine\DBAL\DBALException
     */
    private function importFile(string $fileName, string $tableName, array $columns): int
    {
        $file = new FileFixedWidth($fileName);
        $fp = fopen('sql/'.basename($fileName).'.sql', 'w');
        foreach ($columns as $columnId => $column) {
            $file->addColumn($columnId, $column->start, $column->width);
        }

        $db = $this->getDatabaseConnection();

        $rowCount = 0;
        $fileRow = $file->getRow();

        $perCommit = 5000;

        while ($fileRow) {
            $rowCount++;

            $tableRow = [];
            foreach ($fileRow as $columnId => $data) {
                $columns[$columnId]->value = $this->formatColumn($columns[$columnId]->type, $data);
                $tableRow['`'.$columns[$columnId]->name.'`'] = $columns[$columnId];
            }

            $fileRow = $file->getRow();

            fputs($fp, $this->insertIgnore(
                $db,
                $tableName,
                $tableRow,
                $rowCount % $perCommit === 1,
                $rowCount % $perCommit === 0 || !$fileRow
            ));

        }

        fclose($fp);
       // $db->commit();

        return $rowCount;
    }

    public function insertIgnore($db, $tableExpression, array $data, $isFirst, $isLast) : string
    {
        $columns = [];
        $values  = [];
        $set     = [];

        foreach ($data as $columnName => $column) {
            $columns[] = $columnName;
            switch ($column->type) {
                case "integer":
                case 'smallint':
                case 'bigint':
                    $values[]  = (int) $column->value > 0 ? (int) $column->value : 'NULL';
                    break;

                case 'date':
                    $values[]  = $column->value == null ? 'NULL' : $column->value;
                default:
                    $values[]  = $db->quote($column->value);
            }

            $set[]     = '%s';
        }

        return sprintf(
            ($isFirst ? 'INSERT IGNORE INTO ' . $tableExpression . ' (' . implode(', ', $columns) . ') VALUES ' : '') .
            ' (' . implode(', ', $set) . ')'. ($isLast ? ';' : ','),
            ...$values
        )."\n";
    }

    /**
     * Get optimized database connection
     * @return \Doctrine\DBAL\Connection
     * @throws \Doctrine\DBAL\DBALException
     */
    private function getDatabaseConnection(): Connection
    {
        $db = $this->entityManager->getConnection();

        // without sql logging
        $db->getConfiguration()->setSQLLogger(null);

        $db->setAutoCommit(false);

        // if mysql
        if ($db->getDriver()->getName() == 'pdo_mysql') {

            // mysql insert performance options
            $db->exec('SET autocommit=0');
            $db->exec('SET unique_checks=0');
            $db->exec('SET foreign_key_checks=0');
        }

        return $db;
    }

    /**
     * Format file column to database column
     * @param string $type
     * @param string $data
     * @return null|string
     */
    private function formatColumn(string $type, string $data): ?string
    {
        switch ($type) {
            case 'boolean':
            case 'integer':
            case 'smallint':
            case 'bigint':
                if (trim($data) == '') {
                    $data = null;
                }
                break;

            case 'date':
                if (trim($data) == '') {
                    $data = null;
                    break;
                }
                preg_match('|([0-9]{4})([0-9]{2})([0-9]{2})?|', $data, $matches);
                $data = $matches[1].'-'.$matches[2];
                if (isset($matches[3])) {
                    $data .= '-'.$matches[3];
                } else {
                    $data .= '-00';
                }
                break;

            case 'string':
            default:
                $data = trim($data);
        }

        return $data;
    }
}
