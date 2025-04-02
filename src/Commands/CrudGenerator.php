<?php

namespace Ibex\CrudGenerator\Commands;

use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\select;

/**
 * Class CrudGenerator.
 *
 * @author  Awais <asargodha@gmail.com>
 */
class CrudGenerator extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:crud
                            {name : Table name}
                            {stack : The development stack that should be installed (bootstrap,tailwind,livewire,api)}
                            {--route= : Custom route name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Laravel CRUD operations';

    /**
     * Execute the console command.
     *
     * @throws FileNotFoundException
     */
    public function handle()
    {
        $this->info('Running Crud Generator ...');

        $this->table = $this->getNameInput();

        // If table not exist in DB return
        if (! $this->tableExists()) {
            $this->error("`$this->table` table not exist");

            return false;
        }

        // Build the class name from table name
        $this->name = $this->_buildClassName();

        // Generate the crud
        $this->buildOptions()
            ->buildController()
            ->buildModel()
            ->buildViews()
            ->writeRoute();

        $this->info('Created Successfully.');

        return true;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'stack' => fn() => select(
                label: 'Which stack would you like to install?',
                options: [
                    'bootstrap' => 'Blade with Bootstrap css',
                    'tailwind' => 'Blade with Tailwind css',
                    'livewire' => 'Livewire with Tailwind css',
                    'api' => 'API only',
                    'jetstream' => 'Jetstream inertia with Tailwind css',
                ],
                scroll: 5,
            ),
        ];
    }

    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        $this->options['stack'] = match ($input->getArgument('stack')) {
            'tailwind' => 'tailwind',
            'livewire' => 'livewire',
            'react' => 'react',
            'vue' => 'vue',
            'jetstream' => 'jetstream',
            default => 'bootstrap',
        };
    }

    protected function writeRoute(): static
    {
        $replacements = $this->buildReplacements();

        $this->info('Please add route below: i:e; web.php or api.php');

        $this->info('');

        $lines = match ($this->options['stack']) {
            'livewire' => [
                "Route::get('/{$this->_getRoute()}', \\$this->livewireNamespace\\{$replacements['{{modelNamePluralUpperCase}}']}\Index::class)->name('{$this->_getRoute()}.index');",
                "Route::get('/{$this->_getRoute()}/create', \\$this->livewireNamespace\\{$replacements['{{modelNamePluralUpperCase}}']}\Create::class)->name('{$this->_getRoute()}.create');",
                "Route::get('/{$this->_getRoute()}/show/{{$replacements['{{modelNameLowerCase}}']}}', \\$this->livewireNamespace\\{$replacements['{{modelNamePluralUpperCase}}']}\Show::class)->name('{$this->_getRoute()}.show');",
                "Route::get('/{$this->_getRoute()}/update/{{$replacements['{{modelNameLowerCase}}']}}', \\$this->livewireNamespace\\{$replacements['{{modelNamePluralUpperCase}}']}\Edit::class)->name('{$this->_getRoute()}.edit');",
            ],
            'api' => [
                "Route::apiResource('" . $this->_getRoute() . "', {$this->name}Controller::class);",
            ],
            'jetstream' => [
                "Route::middleware(['auth:sanctum', 'verified'])->group(function () {",
                "    Route::resource('{$this->_getRoute()}', {$replacements['{{modelName}}']}" . "Controller::class);",
                "});"
            ],
            default => [
                "Route::resource('" . $this->_getRoute() . "', {$this->name}Controller::class);",
            ]
        };

        foreach ($lines as $line) {
            $this->info('<bg=blue;fg=white>' . $line . '</>');
        }

        $this->info('');

        return $this;
    }

    /**
     * Build the Controller Class and save in app/Http/Controllers.
     *
     * @return $this
     * @throws FileNotFoundException
     */
    protected function buildController(): static
    {
        if ($this->options['stack'] == 'jetstream') {
            $this->buildJetstream();

            return $this;
        }

        if ($this->options['stack'] == 'livewire') {
            $this->buildLivewire();

            return $this;
        }

        $controllerPath = $this->options['stack'] == 'api'
            ? $this->_getApiControllerPath($this->name)
            : $this->_getControllerPath($this->name);

        if ($this->files->exists($controllerPath) && $this->ask('Already exist Controller. Do you want overwrite (y/n)?', 'y') == 'n') {
            return $this;
        }

        $this->info('Creating Controller ...');

        $replace = $this->buildReplacements();

        $stubFolder = match ($this->options['stack']) {
            'api' => 'api/',
            default => ''
        };

        $controllerTemplate = str_replace(
            array_keys($replace),
            array_values($replace),
            $this->getStub($stubFolder . 'Controller')
        );

        $this->write($controllerPath, $controllerTemplate);

        if ($this->options['stack'] == 'api') {
            $resourcePath = $this->_getResourcePath($this->name);

            $resourceTemplate = str_replace(
                array_keys($replace),
                array_values($replace),
                $this->getStub($stubFolder . 'Resource')
            );

            $this->write($resourcePath, $resourceTemplate);
        }

        return $this;
    }

    protected function buildLivewire(): void
    {
        $this->info('Creating Livewire Component ...');

        $folder = ucfirst(Str::plural($this->name));
        $replace = array_merge($this->buildReplacements(), $this->modelReplacements());

        foreach (['Index', 'Show', 'Edit', 'Create'] as $component) {
            $componentPath = $this->_getLivewirePath($folder . '/' . $component);

            $componentTemplate = str_replace(
                array_keys($replace),
                array_values($replace),
                $this->getStub('livewire/' . $component)
            );

            $this->write($componentPath, $componentTemplate);
        }

        // Form
        $formPath = $this->_getLivewirePath('Forms/' . $this->name . 'Form');

        $componentTemplate = str_replace(
            array_keys($replace),
            array_values($replace),
            $this->getStub('livewire/Form')
        );

        $this->write($formPath, $componentTemplate);
    }


    protected function buildJetstream(): void
    {
        $this->info('Creating Jetstream Inertia Components ...');

        $folder = ucfirst(Str::plural($this->name));

        // Get all filtered columns
        $columns = $this->getFilteredColumns();
        $tableHead = "";
        $tableBody = "";
        $formData = "";
        $formFields = "";
        $detailFields = "";
        $columnFields = "";
        $validationRules = "";
        $formEditData = "";
        $columnCount = count($columns) + 1; // +1 for actions column

        // Generate the necessary fields, validations, etc.
        foreach ($columns as $column) {
            $title = Str::title(str_replace('_', ' ', $column));

            // Table header and body
            $tableHead .= $this->_getSpace(10) . '<th scope="col" class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">' . $title . '</th>' . "\n";
            $tableBody .= $this->_getSpace(10) . '<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ ' . $this->name . '.' . $column . ' }}</td>' . "\n";

            // Form data and fields
            $formData .= $this->_getSpace(2) . $column . ': "",' . "\n";
            $formEditData .= $this->_getSpace(4) . $column . ': this.' . $this->name . '.' . $column . ',' . "\n";

            // Form field component
            $formField = str_replace(
                ['{{title}}', '{{column}}'],
                [$title, $column],
                $this->getStub('views/jetstream/form-field')
            );
            $formFields .= $formField . "\n";

            // Detail field for show view
            $detailField = str_replace(
                ['{{title}}', '{{column}}', '{{modelNameLowerCase}}'],
                [$title, $column, Str::camel($this->name)],
                $this->getStub('views/jetstream/view-field')
            );
            $detailFields .= $detailField . "\n";

            // Column fields for controller
            $columnFields .= $this->_getSpace(5) . '"' . $column . '" => $' . $this->name . '->' . $column . ',' . "\n";

            // Validation rules
            $validationRules .= $this->_getSpace(3) . '"' . $column . '" => "required",' . "\n";
        }

        // Create controller replacements
        $controllerReplacements = array_merge($this->buildReplacements(), $this->modelReplacements(), [
            '{{columnFields}}' => rtrim($columnFields),
            '{{validationRules}}' => rtrim($validationRules),
        ]);

        // Create Inertia component replacements
        $viewReplacements = array_merge($this->buildReplacements(), [
            '{{tableHeader}}' => rtrim($tableHead),
            '{{tableBody}}' => rtrim($tableBody),
            '{{formData}}' => rtrim($formData),
            '{{formEditData}}' => rtrim($formEditData),
            '{{formFields}}' => $formFields,
            '{{detailFields}}' => $detailFields,
            '{{columnCount}}' => $columnCount
        ]);

        // Generate components
        $componentPath = resource_path("js/Pages/{$folder}");
        if (!$this->files->isDirectory($componentPath)) {
            $this->files->makeDirectory($componentPath, 0755, true);
        }

        // Generate Vue components
        foreach (['Index', 'Create', 'Edit', 'Show'] as $component) {
            $content = str_replace(
                array_keys($viewReplacements),
                array_values($viewReplacements),
                $this->getStub("views/jetstream/{$component}")
            );

            $this->write("{$componentPath}/{$component}.vue", $content);
        }

        // Create Controller
        $controllerPath = $this->_getControllerPath($this->name);
        $controllerTemplate = str_replace(
            array_keys($controllerReplacements),
            array_values($controllerReplacements),
            $this->getStub('jetstream/Controller')
        );
        $this->write($controllerPath, $controllerTemplate);

        // Create Model (using the existing buildModel method)
        $this->buildModel();
    }

    /**
     * @return $this
     * @throws FileNotFoundException
     *
     */
    protected function buildModel(): static
    {
        $modelPath = $this->_getModelPath($this->name);

        if ($this->files->exists($modelPath) && $this->ask('Already exist Model. Do you want overwrite (y/n)?', 'y') == 'n') {
            return $this;
        }

        $this->info('Creating Model ...');

        // Make the models attributes and replacement
        $replace = array_merge($this->buildReplacements(), $this->modelReplacements());

        $modelTemplate = str_replace(
            array_keys($replace),
            array_values($replace),
            $this->getStub('Model')
        );

        $this->write($modelPath, $modelTemplate);

        // Make Request Class
        $requestPath = $this->_getRequestPath($this->name);

        $this->info('Creating Request Class ...');

        $requestTemplate = str_replace(
            array_keys($replace),
            array_values($replace),
            $this->getStub('Request')
        );

        $this->write($requestPath, $requestTemplate);

        return $this;
    }

    /**
     * @return $this
     * @throws FileNotFoundException
     *
     * @throws Exception
     */
    protected function buildViews(): static
    {
        if ($this->options['stack'] == 'api') {
            return $this;
        }

        $this->info('Creating Views ...');

        $tableHead = "\n";
        $tableBody = "\n";
        $viewRows = "\n";
        $form = "\n";

        foreach ($this->getFilteredColumns() as $column) {
            $title = Str::title(str_replace('_', ' ', $column));

            $tableHead .= $this->getHead($title);
            $tableBody .= $this->getBody($column);
            if ($this->options['stack'] != 'jetstream') {
                $viewRows .= $this->getField($title, $column, 'view-field');
            }
            $form .= $this->getField($title, $column);
        }

        $replace = array_merge($this->buildReplacements(), [
            '{{tableHeader}}' => $tableHead,
            '{{tableBody}}' => $tableBody,
            '{{viewRows}}' => $viewRows,
            '{{form}}' => $form,
        ]);

        $this->buildLayout();

        if ($this->options['stack'] === 'jetstream') {
            return $this;
        }

        foreach (['index', 'create', 'edit', 'form', 'show'] as $view) {
            $path = match ($this->options['stack']) {
                'livewire' => $this->isLaravel12() ? "views/{$this->options['stack']}/12/$view" : "views/{$this->options['stack']}/default/$view",
                default => "views/{$this->options['stack']}/$view"
            };

            $viewTemplate = str_replace(
                array_keys($replace),
                array_values($replace),
                $this->getStub($path)
            );

            $this->write($this->_getViewPath($view), $viewTemplate);
        }

        return $this;
    }

    /**
     * Make the class name from table name.
     *
     * @return string
     */
    private function _buildClassName(): string
    {
        return Str::studly(Str::singular($this->table));
    }
}
