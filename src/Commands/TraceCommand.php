<?php

namespace Blueprint\Commands;

use Blueprint\Blueprint;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class TraceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blueprint:trace';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create definitions for existing models to reference in new drafts';

    /** @var Filesystem $files */
    protected $files;

    /**
     * @param Filesystem $files
     * @param \Illuminate\Contracts\View\Factory $view
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $definitions = [];
        foreach ($this->appClasses() as $class) {
            $model = $this->loadModel($class);
            if (is_null($model)) {
                // TODO: output warning
                continue;
            }

            $definitions[class_basename($model)] = $this->mapColumns($this->extractColumns($model));
        }

        // TODO: output YAML
//        $this->files->get('.blueprint');
//
        $blueprint = new Blueprint();
        $yaml = $blueprint->dump(['models' => $definitions]);
        echo $yaml, PHP_EOL;
//        print_r($blueprint->parse($yaml));
    }

    private function appClasses()
    {
        $dir = config('blueprint.app_path');

        if (config('blueprint.models_namespace')) {
            $dir .= DIRECTORY_SEPARATOR . str_replace('\\', '/', config('blueprint.models_namespace'));
        }

        if (!$this->files->exists($dir)) {
            return [];
        }

        return array_map(function (\SplFIleInfo $file) {
            return str_replace(
                [config('blueprint.app_path') . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
                [config('blueprint.namespace') . '\\', '\\'],
                $file->getPath() . DIRECTORY_SEPARATOR . $file->getBasename('.php')
            );
        }, $this->files->allFiles($dir));
    }

    private function loadModel(string $class)
    {
        if (!class_exists($class)) {
            return null;
        }

        $reflectionClass = new \ReflectionClass($class);
        if (!$reflectionClass->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
            return null;
        }

        return $this->laravel->make($class);
    }

    private function extractColumns($model)
    {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema = $model->getConnection()->getDoctrineSchemaManager($table);
        $databasePlatform = $schema->getDatabasePlatform();
//        $databasePlatform->registerDoctrineTypeMapping('enum', 'customEnum');

        $database = null;
        if (strpos($table, '.')) {
            list($database, $table) = explode('.', $table);
        }

        $columns = $schema->listTableColumns($table, $database);

        return $columns;
//        if ($columns) {
//            foreach ($columns as $column) {
//                $name = $column->getName();
//                if (in_array($name, $model->getDates())) {
//                    $type = 'datetime';
//                } else {
//                    $type = $column->getType()->getName();
//                }
//                if (!($model->incrementing && $model->getKeyName() === $name) &&
//                    $name !== $model::CREATED_AT &&
//                    $name !== $model::UPDATED_AT
//                ) {
//                    if (!method_exists($model, 'getDeletedAtColumn') || (method_exists($model, 'getDeletedAtColumn') && $name !== $model->getDeletedAtColumn())) {
//                        $this->setProperty($name, $type, $table);
//                    }
//                }
//            }
//        }
    }

    /**
     * @param \Doctrine\DBAL\Schema\Column[] $columns
     */
    private function mapColumns($columns)
    {
        // TODO: handle special cases
        // id, timestamps, softdeletes
        return collect($columns)->map([self::class, 'columns'])->toArray();
    }

    public static function columns(\Doctrine\DBAL\Schema\Column $column, string $key)
    {
        $attributes = [];

        $type = self::translations($column->getType()->getName());

        if (in_array($type, ['decimal', 'float'])) {
            if ($column->getPrecision()) {
                $type .= ':' . $column->getPrecision();
            }
            if ($column->getScale()) {
                $type .= ',' . $column->getScale();
            }
        } elseif ($type === 'string' && $column->getLength()) {
            if ($column->getLength() !== 255) {
                $type .= ':' . $column->getLength();
            }
        }

        // update text types based on length...
        // enums, guid, etc?

        $attributes[] = $type;

        if ($column->getUnsigned()) {
            $attributes[] = 'unsigned';
        }

        if (!$column->getNotnull()) {
            $attributes[] = 'nullable';
        }

        if (!is_null($column->getDefault())) {
            $attributes[] = 'default:' . $column->getDefault();
        }

        return implode(' ', $attributes);
    }

    private static function translations(string $type)
    {
        static $mappings = [
            'array' => 'string',
            'bigint' => 'bigInteger',
            'binary' => 'binary',
            'blob' => 'binary',
            'boolean' => 'boolean',
            'date' => 'date',
            'date_immutable' => 'date',
            'dateinterval' => 'date',
            'datetime' => 'dateTime',
            'datetime_immutable' => 'dateTime',
            'datetimetz' => 'dateTimeTz',
            'datetimetz_immutable' => 'dateTimeTz',
            'decimal' => 'decimal',
            'float' => 'float',
            'guid' => 'string',
            'integer' => 'integer',
            'json' => 'json',
            'object' => 'string',
            'simple_array' => 'string',
            'smallint' => 'smallInteger',
            'string' => 'string',
            'text' => 'text',
            'time' => 'time',
            'time_immutable' => 'time',
        ];

        return $mappings[$type] ?? 'string';
    }
}
