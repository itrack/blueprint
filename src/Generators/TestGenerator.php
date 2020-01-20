<?php


namespace Blueprint\Generators;


use Blueprint\Contracts\Generator;
use Blueprint\Models\Controller;
use Blueprint\Models\Statements\DispatchStatement;
use Blueprint\Models\Statements\EloquentStatement;
use Blueprint\Models\Statements\FireStatement;
use Blueprint\Models\Statements\QueryStatement;
use Blueprint\Models\Statements\RedirectStatement;
use Blueprint\Models\Statements\RenderStatement;
use Blueprint\Models\Statements\SendStatement;
use Blueprint\Models\Statements\SessionStatement;
use Blueprint\Models\Statements\ValidateStatement;
use Illuminate\Support\Str;

class TestGenerator implements Generator
{
    const INDENT = '        ';
    const TESTS_VIEW = 1;
    const TESTS_REDIRECT = 2;
    const TESTS_SAVE = 4;
    const TESTS_DELETE = 8;

    /** @var \Illuminate\Contracts\Filesystem\Filesystem */
    private $files;

    private $imports = [];
    private $stubs = [];
    private $traits = [];

    public function __construct($files)
    {
        $this->files = $files;
    }

    public function output(array $tree): array
    {
        $output = [];

        $stub = $this->files->get(STUBS_PATH . '/test/class.stub');

        /** @var \Blueprint\Models\Controller $controller */
        foreach ($tree['controllers'] as $controller) {
            $path = $this->getPath($controller);
            $this->files->put(
                $path,
                $this->populateStub($stub, $controller)
            );

            $output['created'][] = $path;
        }

        return $output;
    }

    protected function getPath(Controller $controller)
    {
        return 'tests/Feature/Http/Controllers/' . $controller->className() . 'Test.php';
    }

    protected function populateStub(string $stub, Controller $controller)
    {
        // TODO: nested controllers...
        $stub = str_replace('DummyNamespace', 'Tests\\Feature\\Http\\Controllers', $stub);
        $stub = str_replace('DummyClass', $controller->className(), $stub);
        $stub = str_replace('// test cases...', $this->buildTestCases($controller), $stub);
        $stub = str_replace('// imports...', $this->buildImports($controller), $stub);

        return $stub;
    }

    protected function buildTestCases(Controller $controller)
    {
        $template = $this->testCaseStub();
        $test_cases = '';

        foreach ($controller->methods() as $name => $statements) {
            $test_case = $template;
            $setup = [];
            $assertions = [];
            $request_data = [];
            $tested_bits = 0;

            $model = $controller->prefix();
            $context = Str::singular($controller->prefix());
            $variable = Str::camel($context);

            if (in_array($name, ['edit', 'update', 'show', 'destroy'])) {
                $setup[] = sprintf('$%s = factory(%s::class)->create();', $variable, $model);
            }

            foreach ($statements as $statement) {
                if ($statement instanceof SendStatement) {
                    $this->addImport($controller, 'Illuminate\\Support\\Facades\\Mail');
                    $this->addImport($controller, 'App\\Mail\\' . $statement->mail());

                    $setup[] = 'Mail::fake();';

                    $assertion = sprintf('Mail::assertSent(%s::class', $statement->mail());

                    if ($statement->data() || $statement->to()) {
                        $conditions = [];
                        $variables = [];
                        $assertion .= ', function ($mail)';

                        if ($statement->to()) {
                            $conditions[] = '$mail->hasTo($' . str_replace('.', '->', $statement->to()) . ')';
                        }

                        foreach ($statement->data() as $data) {
                            if (Str::studly(Str::singular($data)) === $context) {
                                $variables[] .= '$' . $data;
                                $conditions[] .= sprintf('$mail->%s->is($%s)', $data, $data);
                            } else {
                                [$model, $property] = explode('.', $data);
                                $variables[] .= '$' . $model;
                                $conditions[] .= sprintf('$mail->%s == $%s', $property ?? $model, str_replace('.', '->', $data()));
                            }
                        }

                        if ($variables) {
                            $assertion .= ' use (' . implode(', ', array_unique($variables)) . ')';
                        }

                        $assertion .= ' {' . PHP_EOL;
                        $assertion .= str_pad(' ', 12);
                        $assertion .= 'return ' . implode(' && ', $conditions) . ';';
                        $assertion .= PHP_EOL . str_pad(' ', 8) . '}';
                    }

                    $assertion .= '));';

                    $assertions[] = $assertion;

                } elseif ($statement instanceof ValidateStatement) {
                    $this->addTestAssertionsTrait($controller);

                    $class = $controller->name() . Str::studly($name) . 'Request';


                    // TODO: use FQCN
                    $test_case = $this->buildFormRequestTestCase($controller->className(), $name, '\\App\\Http\\Requests\\' . $class) . PHP_EOL . PHP_EOL . $test_case;

                    if ($statement->data()) {
                        $this->addFakerTrait($controller);

                        foreach ($statement->data() as $data) {
                            $setup[] = sprintf('$%s = $this->faker->%s;', $data, 'word()');
                            $request_data[$data] = '$' . $data;
                        }
                    }

                } elseif ($statement instanceof DispatchStatement) {
                    $this->addImport($controller, 'Illuminate\\Support\\Facades\\Queue');
                    $this->addImport($controller, 'App\\Jobs\\' . $statement->job());

                    $setup[] = 'Queue::fake();';

                    $assertion = sprintf('Queue::assertPushed(%s::class', $statement->job());

                    if ($statement->data()) {
                        $conditions = [];
                        $variables = [];
                        $assertion .= ', function ($job)';

                        foreach ($statement->data() as $data) {
                            if (Str::studly(Str::singular($data)) === $context) {
                                $variables[] .= '$' . $data;
                                $conditions[] .= sprintf('$job->%s->is($%s)', $data, $data);
                            } else {
                                [$model, $property] = explode('.', $data);
                                $variables[] .= '$' . $model;
                                $conditions[] .= sprintf('$job->%s == $%s', $property ?? $model, str_replace('.', '->', $data()));
                            }
                        }

                        if ($variables) {
                            $assertion .= ' use (' . implode(', ', array_unique($variables)) . ')';
                        }

                        $assertion .= ' {' . PHP_EOL;
                        $assertion .= str_pad(' ', 12);
                        $assertion .= 'return ' . implode(' && ', $conditions) . ';';
                        $assertion .= PHP_EOL . str_pad(' ', 8) . '}';
                    }

                    $assertion .= '));';

                    $assertions[] = $assertion;
                } elseif ($statement instanceof FireStatement) {
                    $this->addImport($controller, 'Illuminate\\Support\\Facades\\Event');

                    $setup[] = 'Event::fake();';

                    $assertion = 'Event::assertDispatched(';

                    if ($statement->isNamedEvent()) {
                        $assertion .= $statement->event();
                    } else {
                        $this->addImport($controller, 'App\\Events\\' . $statement->event());
                        $assertion .= $statement->event() . '::class';
                    }

                    if ($statement->data()) {
                        $conditions = [];
                        $variables = [];
                        $assertion .= ', function ($event)';

                        foreach ($statement->data() as $data) {
                            if (Str::studly(Str::singular($data)) === $context) {
                                $variables[] .= '$' . $data;
                                $conditions[] .= sprintf('$event->%s->is($%s)', $data, $data);
                            } else {
                                [$model, $property] = explode('.', $data);
                                $variables[] .= '$' . $model;
                                $conditions[] .= sprintf('$event->%s == $%s', $property ?? $model, str_replace('.', '->', $data()));
                            }
                        }

                        if ($variables) {
                            $assertion .= ' use (' . implode(', ', array_unique($variables)) . ')';
                        }

                        $assertion .= ' {' . PHP_EOL;
                        $assertion .= str_pad(' ', 12);
                        $assertion .= 'return ' . implode(' && ', $conditions) . ';';
                        $assertion .= PHP_EOL . str_pad(' ', 8) . '}';
                    }

                    $assertion .= '));';

                    $assertions[] = $assertion;
                } elseif ($statement instanceof RenderStatement) {
                    $tested_bits |= self::TESTS_VIEW;

                    $assertions[] = '$response->assertOk();';
                    $assertions[] = sprintf('$response->assertViewIs(\'%s\');', $statement->view());

                    foreach ($statement->data() as $data) {
                        // TODO: what if local eloquent/factory data
                        $assertions[] = sprintf('$response->assertViewHas(\'%s\');', $data);
                    }
                } elseif ($statement instanceof RedirectStatement) {
                    $tested_bits |= self::TESTS_REDIRECT;

                    $assertion = sprintf('$response->assertRedirect(route(\'%s\'', $statement->route());

                    if ($statement->data()) {
                        $assertion .= ', [' . $this->buildParameters($this->data()) . ']';
                    } elseif (Str::contains($statement->route(), '.')) {
                        [$model, $action] = explode('.', $statement->route());
                        if (in_array($action, ['edit', 'update', 'show', 'destroy'])) {
                            $assertion .= sprintf(", ['%s' => $%s]", $model, $model);
                        }
                    }

                    $assertion .= '));';

                    $assertions[] = $assertion;
                } elseif ($statement instanceof SessionStatement) {
                    $assertions[] = sprintf('$response->assertSessionHas(\'%s\', %s);', $statement->reference(), '$' . str_replace('.', '->', $statement->reference()));
                } elseif ($statement instanceof EloquentStatement) {
                    $this->addRefreshDatabaseTrait($controller);

                    $model = $this->determineModel($controller->prefix(), $statement->reference());
                    $this->addImport($controller, 'App\\' . $model);

                    if ($statement->operation() === 'save') {
                        $tested_bits |= self::TESTS_SAVE;

                        $assertion = '';
//                        '$posts = Post::where('title', $title)
//                            ->where('content', $content)
//                            ->get();
//                        $this->assertCount(1, $posts);
//                        $post = $posts->first();';

                        // TODO: prepend as first assertion
                        $assertions[] = $assertion;
                    } elseif ($statement->operation() === 'find') {
                        $setup[] = sprintf('$%s = factory(%s::class)->create();', $variable, $model);
                    } elseif ($statement->operation() === 'delete') {
                        $tested_bits |= self::TESTS_DELETE;
                        $setup[] = sprintf('$%s = factory(%s::class)->create();', $variable, $model);
                        $assertions[] = sprintf('$this->assertDeleted($%s);', $variable);
                    }
                } elseif ($statement instanceof QueryStatement) {
                    $this->addRefreshDatabaseTrait($controller);

                    $this->addImport($controller, 'App\\' . $this->determineModel($controller->prefix(), $statement->model()));
                }
            }

            $call = sprintf('$response = $this->%s(route(\'%s.%s\'', $this->httpMethodForAction($name), Str::lower($context), $name);

            if (in_array($name, ['edit', 'update', 'show', 'destroy'])) {
                $call .= ', $' . Str::camel($context);
            }
            $call .= ')';

            if ($request_data) {
                $output = var_export($request_data, true);
                $output = preg_replace('/^\s+/m', str_pad(' ', 12), $output);
                $output = preg_replace(['/^array\s\(/', "/\)$/"], ['[', str_pad(' ', 8) . ']'], $output);

                $call .= ', ' . trim($output);
            }
            $call .= ');';

            $test_case = str_replace('// setup...', implode(PHP_EOL . self::INDENT, $setup), $test_case);
            $test_case = str_replace('// call...', $call, $test_case);
            $test_case = str_replace('// verify...', implode(PHP_EOL . self::INDENT, $assertions), $test_case);

            $test_case = str_replace('dummy_test_case', $this->buildTestCaseName($name, $tested_bits), $test_case);

            $test_cases .= PHP_EOL . $test_case . PHP_EOL;
        }

        return trim($this->buildTraits($controller) . PHP_EOL . $test_cases);
    }

    protected function addTrait(Controller $controller, $trait)
    {
        $this->traits[$controller->name()][] = $trait;
    }

    private function buildTraits(Controller $controller)
    {
        $traits = array_unique($this->traits[$controller->name()]);
        sort($traits);

        return 'use ' . implode(', ', $traits) . ';';
    }

    private function testCaseStub()
    {
        if (empty($this->stubs['test-case'])) {
            $this->stubs['test-case'] = $this->files->get(STUBS_PATH . '/test/case.stub');
        }

        return $this->stubs['test-case'];
    }

    protected function addImport(Controller $controller, $class)
    {
        $this->imports[$controller->name()][] = $class;
    }

    protected function buildImports(Controller $controller)
    {
        $this->addImport($controller, 'Tests\\TestCase');

        $imports = array_unique($this->imports[$controller->name()]);
        sort($imports);

        return implode(PHP_EOL, array_map(function ($class) {
            return 'use ' . $class . ';';
        }, $imports));
    }

    private function determineModel(string $prefix, ?string $reference)
    {
        if (empty($reference) || $reference === 'id') {
            return Str::studly(Str::singular($prefix));
        }

        if (Str::contains($reference, '.')) {
            return Str::studly(Str::before($reference, '.'));
        }

        return Str::studly($reference);
    }

    private function buildFormRequestTestCase(string $controller, string $action, string $form_request)
    {
        return <<< END
    /** @test */
    public function ${action}_validates_using_form_request()
    {
        \$this->assertActionUsesFormRequest(
            ${controller}::class,
            '${action}',
            ${form_request}::class
        );
    }
END;
    }

    private function addFakerTrait(Controller $controller)
    {
        $this->addImport($controller, 'Illuminate\\Foundation\\Testing\\WithFaker');
        $this->addTrait($controller, 'WithFaker');
    }

    private function addTestAssertionsTrait(Controller $controller)
    {
        $this->addImport($controller, 'JMac\\Testing\\Traits\HttpTestAssertions');
        $this->addTrait($controller, 'HttpTestAssertions');
    }

    private function addRefreshDatabaseTrait(Controller $controller)
    {
        $this->addImport($controller, 'Illuminate\\Foundation\\Testing\\RefreshDatabase');
        $this->addTrait($controller, 'RefreshDatabase');
    }

    private function httpMethodForAction($action)
    {
        switch ($action) {
            case 'store':
                return 'post';
            case 'update':
                return 'put';
            case 'delete':
                return 'delete';
            default:
                return 'get';
        }
    }

    private function buildTestCaseName(string $name, int $tested_bits)
    {
        $verifications = [];

        if ($tested_bits & self::TESTS_SAVE) {
            $verifications[] = 'saves';
        }

        if ($tested_bits & self::TESTS_DELETE) {
            $verifications[] = 'deletes';
        }

        if ($tested_bits & self::TESTS_VIEW) {
            $verifications[] = 'displays_view';
        }

        if ($tested_bits & self::TESTS_REDIRECT) {
            $verifications[] = 'redirects';
        }

        if (empty($verifications)) {
            return $name . '_behaves_as_expected';
        }

        $final_verification = array_pop($verifications);

        if (empty($verifications)) {
            return $name . '_' . $final_verification;
        }

        return $name . '_' . implode('_', $verifications) . '_and_' . $final_verification;
    }
}