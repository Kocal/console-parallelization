<?php

/*
 * This file is part of the Webmozarts Console Parallelization package.
 *
 * (c) Webmozarts GmbH <office@webmozarts.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Webmozarts\Console\Parallelization;

use function array_fill;
use Error;
use function func_get_args;
use function getcwd;
use function implode;
use InvalidArgumentException;
use const PHP_EOL;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozarts\Console\Parallelization\ErrorHandler\DummyErrorHandler;
use Webmozarts\Console\Parallelization\ErrorHandler\FakeErrorHandler;
use Webmozarts\Console\Parallelization\ErrorHandler\ItemProcessingErrorHandler;
use Webmozarts\Console\Parallelization\Input\ParallelizationInput;
use Webmozarts\Console\Parallelization\Logger\DummyLogger;
use Webmozarts\Console\Parallelization\Logger\FakeLogger;
use Webmozarts\Console\Parallelization\Process\FakeProcessLauncherFactory;
use Webmozarts\Console\Parallelization\Process\ProcessLauncher;
use Webmozarts\Console\Parallelization\Process\ProcessLauncherFactory;
use Webmozarts\Console\Parallelization\Process\StandardSymfonyProcessFactory;

/**
 * @covers \Webmozarts\Console\Parallelization\ParallelExecutor
 */
final class ParallelExecutorTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @dataProvider childProcessProvider
     */
    public function test_it_can_execute_a_child_process(
        ParallelizationInput $parallelizationInput,
        InputInterface $input,
        BufferedOutput $output,
        string $sourceStreamString,
        int $batchSize,
        string $progressSymbol,
        string $expectedOutput,
        int $expectedExitCode,
        array $expectedCalls
    ): void {
        $calls = [];
        $errorHandler = new DummyErrorHandler();
        $sourceStream = StringStream::fromString($sourceStreamString);

        $createCallable = static function (string $name) use (&$calls) {
            return static function () use ($name, &$calls): void {
                $calls[] = [$name, func_get_args()];
            };
        };

        $executor = self::createChildProcessExecutor(
            $createCallable('runSingleCommand'),
            $errorHandler,
            $sourceStream,
            $batchSize,
            $createCallable('runBeforeFirstCommand'),
            $createCallable('runAfterLastCommand'),
            $createCallable('runBeforeBatch'),
            $createCallable('runAfterBatch'),
            $progressSymbol,
        );

        $exitCode = $executor->execute(
            $parallelizationInput,
            $input,
            $output,
            new FakeLogger(),
        );

        self::assertSame($expectedOutput, $output->fetch());
        self::assertSame($expectedCalls, $calls);
        self::assertSame([], $errorHandler->calls);
        self::assertSame($expectedExitCode, $exitCode);
    }

    public static function childProcessProvider(): iterable
    {
        $progressSymbol = '👉';

        $createExpectedOutput = static fn (int $numberOfItemsProcessed) => implode(
            '',
            array_fill(0, $numberOfItemsProcessed, $progressSymbol),
        );

        yield 'more items than batch size' => (static function () use (
            $progressSymbol,
            $createExpectedOutput
        ) {
            $input = new StringInput('');
            $output = new BufferedOutput();

            $items = [
                'item1',
                'item2',
                'item3',
            ];

            return [
                new ParallelizationInput(
                    true,
                    5,
                    null,
                    true,
                ),
                $input,
                $output,
                implode(PHP_EOL, $items),
                2,
                $progressSymbol,
                $createExpectedOutput(3),
                0,
                [
                    [
                        'runBeforeBatch',
                        [$input, $output, [$items[0], $items[1]]],
                    ],
                    [
                        'runSingleCommand',
                        [$items[0], $input, $output],
                    ],
                    [
                        'runSingleCommand',
                        [$items[1], $input, $output],
                    ],
                    [
                        'runAfterBatch',
                        [$input, $output, [$items[0], $items[1]]],
                    ],
                    [
                        'runBeforeBatch',
                        [$input, $output, [$items[2]]],
                    ],
                    [
                        'runSingleCommand',
                        [$items[2], $input, $output],
                    ],
                    [
                        'runAfterBatch',
                        [$input, $output, [$items[2]]],
                    ],
                ],
                [],
            ];
        })();

        yield 'less items than batch size' => (static function () use (
            $progressSymbol,
            $createExpectedOutput
        ) {
            $input = new StringInput('');
            $output = new BufferedOutput();

            return [
                new ParallelizationInput(
                    true,
                    5,
                    null,
                    true,
                ),
                $input,
                $output,
                'item1'.PHP_EOL,
                2,
                $progressSymbol,
                $createExpectedOutput(1),
                0,
                [
                    [
                        'runBeforeBatch',
                        [$input, $output, ['item1']],
                    ],
                    [
                        'runSingleCommand',
                        ['item1', $input, $output],
                    ],
                    [
                        'runAfterBatch',
                        [$input, $output, ['item1']],
                    ],
                ],
                [],
            ];
        })();

        yield 'no item' => (static function () use (
            $progressSymbol,
            $createExpectedOutput
        ) {
            $input = new StringInput('');
            $output = new BufferedOutput();

            return [
                new ParallelizationInput(
                    true,
                    5,
                    'item1',
                    true,
                ),
                $input,
                $output,
                '',
                2,
                $progressSymbol,
                $createExpectedOutput(0),
                0,
                [],
                [],
            ];
        })();
    }

    public function test_it_handles_processing_failures_in_child_processes(): void
    {
        $parallelizationInput = new ParallelizationInput(
            true,
            5,
            null,
            true,
        );
        $input = new StringInput('');
        $output = new BufferedOutput();
        $items = [
            'item1',
            'item2',
            'item3',
        ];
        $sourceStreamString = implode(PHP_EOL, $items);
        $batchSize = 2;
        $progressSymbol = '👉';
        $calls = [];
        $errorHandler = new DummyErrorHandler();
        $sourceStream = StringStream::fromString($sourceStreamString);
        $error = new Error('Processing failed.');
        $logger = new FakeLogger();

        $createCallable = static function (string $name) use (&$calls) {
            return static function () use ($name, &$calls): void {
                $calls[] = [$name, func_get_args()];
            };
        };

        $executor = self::createChildProcessExecutor(
            static function (string $item) use ($error, &$calls): void {
                $calls[] = ['runSingleCommand', func_get_args()];

                if ('item2' === $item) {
                    throw $error;
                }
            },
            $errorHandler,
            $sourceStream,
            $batchSize,
            $createCallable('runBeforeFirstCommand'),
            $createCallable('runAfterLastCommand'),
            $createCallable('runBeforeBatch'),
            $createCallable('runAfterBatch'),
            $progressSymbol,
        );

        $expectedOutput = $progressSymbol.$progressSymbol.$progressSymbol;
        $expectedExitCode = 0;  // TODO: since a child failed shouldn't it fail?
        $expectedCalls = [
            [
                'runBeforeBatch',
                [$input, $output, ['item1', 'item2']],
            ],
            [
                'runSingleCommand',
                ['item1', $input, $output],
            ],
            [
                'runSingleCommand',
                ['item2', $input, $output],
            ],
            [
                'runAfterBatch',
                [$input, $output, ['item1', 'item2']],
            ],
            [
                'runBeforeBatch',
                [$input, $output, ['item3']],
            ],
            [
                'runSingleCommand',
                ['item3', $input, $output],
            ],
            [
                'runAfterBatch',
                [$input, $output, ['item3']],
            ],
        ];
        $expectedErrors = [
            [
                'item2',
                $error,
                $logger,
            ],
        ];

        $exitCode = $executor->execute(
            $parallelizationInput,
            $input,
            $output,
            $logger,
        );

        self::assertSame($expectedOutput, $output->fetch());
        self::assertSame($expectedCalls, $calls);
        self::assertSame($expectedErrors, $errorHandler->calls);
        self::assertSame($expectedExitCode, $exitCode);
    }

    public function test_it_can_launch_configured_child_processes(): void
    {
        $numberOfProcesses = 3;
        $segmentSize = 2;

        $parallelizationInput = new ParallelizationInput(
            true,
            $numberOfProcesses,
            null,
            false,
        );

        $input = new ArrayInput([
            'item' => 'item3',
            'groupId' => 'group2',
            '--child' => null,
            '--processes' => '2',
            '--opt' => 'val',
        ]);

        $commandDefinition = new InputDefinition([
            new InputArgument(
                'item',
                InputArgument::REQUIRED,
            ),
            new InputArgument(
                'groupId',
                InputArgument::REQUIRED,
            ),
            new InputArgument(
                'optArg',
                InputArgument::OPTIONAL,
                '',
                '',
            ),
            new InputOption(
                'opt',
                null,
                InputOption::VALUE_REQUIRED,
            ),
            new InputOption(
                'child',
                null,
                InputOption::VALUE_NONE,
            ),
            new InputOption(
                'processes',
                null,
                InputOption::VALUE_REQUIRED,
            ),
        ]);
        $input->bind($commandDefinition);

        $output = new NullOutput();
        $errorHandler = new DummyErrorHandler();
        $logger = new DummyLogger();

        $noop = static function () {};

        $items = ['item0', 'item1', 'item2'];
        $commandName = 'import:something';
        $phpExecutable = __FILE__;
        $scriptPath = __DIR__.'/../bin/console';
        $workingDirectory = __DIR__;
        $extraEnvironmentVariables = ['EXTRA_ENV' => '1'];

        $processLauncherProphecy = $this->prophesize(ProcessLauncher::class);
        $processLauncherProphecy
            ->run($items)
            ->shouldBeCalled();

        $processLauncherFactoryProphecy = $this->prophesize(ProcessLauncherFactory::class);
        $processLauncherFactoryProphecy
            ->create(
                [
                    $phpExecutable,
                    $scriptPath,
                    $commandName,
                    'group2',
                    '--child',
                    '--opt=val',
                ],
                $workingDirectory,
                $extraEnvironmentVariables,
                $numberOfProcesses,
                $segmentSize,
                $logger,
                Argument::type('callable'),
                Argument::type('callable'),
                Argument::type(StandardSymfonyProcessFactory::class),
            )
            ->willReturn($processLauncherProphecy->reveal());

        $executor = new ParallelExecutor(
            static fn () => $items,
            $noop,
            static fn (int $itemCount) => 0 === $itemCount ? 'item' : 'items',
            $commandName,
            $commandDefinition,
            $errorHandler,
            StringStream::fromString(''),
            1,
            $segmentSize,
            $noop,
            $noop,
            $noop,
            $noop,
            'ø',
            $phpExecutable,
            $scriptPath,
            $workingDirectory,
            $extraEnvironmentVariables,
            $processLauncherFactoryProphecy->reveal(),
        );

        $executor->execute(
            $parallelizationInput,
            $input,
            $output,
            $logger,
        );

        $processLauncherProphecy
            ->run(Argument::cetera())
            ->shouldHaveBeenCalledTimes(1);
        $processLauncherFactoryProphecy
            ->create(Argument::cetera())
            ->shouldHaveBeenCalledTimes(1);
    }

    /**
     * @dataProvider childProcessSpawnerProvider
     */
    public function test_it_can_can_launch_child_processes_or_process_within_the_main_process(
        ParallelizationInput $parallelizationInput,
        int $segmentSize,
        array $items,
        bool $expected
    ): void {
        $noop = static function () {};

        $processLauncherFactory = $this->createProcessLauncherFactory($expected);

        $executor = self::createMainProcessExecutor(
            $items,
            $noop,
            new DummyErrorHandler(),
            2,
            $segmentSize,
            $noop,
            $noop,
            $noop,
            $noop,
            'ø',
            $processLauncherFactory,
        );

        $executor->execute(
            $parallelizationInput,
            new StringInput(''),
            new NullOutput(),
            new DummyLogger(),
        );

        $this->addToAssertionCount(1);
    }

    public static function childProcessSpawnerProvider(): iterable
    {
        $createSet = static fn (
            int $itemCount,
            bool $numberOfProcessesDefined,
            int $segmentSize,
            int $numberOfProcesses,
            bool $expectedChildProcessesSpawned
        ) => [
            new ParallelizationInput(
                $numberOfProcessesDefined,
                $numberOfProcesses,
                null,
                false,
            ),
            $segmentSize,
            array_fill(0, $itemCount, 'itemX'),
            $expectedChildProcessesSpawned,
        ];

        yield 'more items than segment size; more than one process; number of processes defined' => $createSet(
            3,
            true,
            2,
            2,
            true,
        );

        yield 'more items than segment size; more than one process; number of processes NOT defined' => $createSet(
            3,
            false,
            2,
            2,
            true,
        );

        yield 'more items than segment size; one process; number of processes defined' => $createSet(
            3,
            true,
            2,
            1,
            true,
        );

        yield 'more items than segment size; one process; number of processes NOT defined' => $createSet(
            3,
            false,
            2,
            1,
            false,
        );

        yield 'more items than segment size; two processes; number of processes NOT defined' => $createSet(
            3,
            false,
            2,
            2,
            true,
        );

        yield 'as many items as segment size; more than one process; number of processes defined' => $createSet(
            3,
            true,
            3,
            2,
            false,
        );

        yield 'as many items as segment size; more than one process; number of processes NOT defined' => $createSet(
            3,
            false,
            3,
            2,
            false,
        );

        yield 'as many items as segment size; one process; number of processes defined' => $createSet(
            3,
            true,
            3,
            1,
            false,
        );

        yield 'as many items as segment size; one process; number of processes NOT defined' => $createSet(
            3,
            false,
            3,
            1,
            false,
        );
    }

    /**
     * @dataProvider mainProcessProvider
     */
    public function test_it_can_execute_a_main_process(
        ParallelizationInput $parallelizationInput,
        InputInterface $input,
        BufferedOutput $output,
        array $items,
        int $batchSize,
        int $segmentSize,
        string $progressSymbol,
        string $expectedOutput,
        int $expectedExitCode,
        array $expectedCalls,
        array $expectedLogRecords,
        bool $expectedChildProcessesSpawned
    ): void {
        $calls = [];
        $errorHandler = new DummyErrorHandler();
        $logger = new DummyLogger();

        $createCallable = static function (string $name) use (&$calls) {
            return static function () use ($name, &$calls): void {
                $calls[] = [$name, func_get_args()];
            };
        };

        $processLauncherFactory = $this->createProcessLauncherFactory(
            $expectedChildProcessesSpawned,
        );

        $executor = self::createMainProcessExecutor(
            $items,
            $createCallable('runSingleCommand'),
            $errorHandler,
            $batchSize,
            $segmentSize,
            $createCallable('runBeforeFirstCommand'),
            $createCallable('runAfterLastCommand'),
            $createCallable('runBeforeBatch'),
            $createCallable('runAfterBatch'),
            $progressSymbol,
            $processLauncherFactory,
        );

        $exitCode = $executor->execute(
            $parallelizationInput,
            $input,
            $output,
            $logger,
        );

        self::assertSame($expectedOutput, $output->fetch());
        self::assertSame($expectedCalls, $calls);
        self::assertSame($expectedLogRecords, $logger->records);
        self::assertSame([], $errorHandler->calls);
        self::assertSame($expectedExitCode, $exitCode);
    }

    public static function mainProcessProvider(): iterable
    {
        yield from PHPUnitProviderUtil::prefixWithLabel(
            '[withChild] ',
            self::mainProcessWithChildProcessLaunchedProvider(),
        );

        yield from PHPUnitProviderUtil::prefixWithLabel(
            '[withoutChild] ',
            self::mainProcessWithoutChildProcessLaunchedProvider(),
        );
    }

    public function test_it_processes_the_child_processes_output(): void
    {
        $parallelizationInput = new ParallelizationInput(
            true,
            2,
            null,
            false,
        );
        $batchSize = 2;
        $segmentSize = 2;
        $input = new StringInput('');
        $output = new BufferedOutput();
        $items = [
            'item1',
            'item2',
            'item3',
            'item4',
            'item5',
        ];
        $progressSymbol = '👉';
        $calls = [];
        $errorHandler = new DummyErrorHandler();
        $logger = new DummyLogger();

        $createCallable = static function (string $name) use (&$calls) {
            return static function () use ($name, &$calls): void {
                $calls[] = [$name, func_get_args()];
            };
        };

        $processCallback = FakeCallable::create();

        $processLauncherProphecy = $this->prophesize(ProcessLauncher::class);
        $processLauncherProphecy
            ->run(Argument::cetera())
            ->will(static function () use ($progressSymbol, &$processCallback): void {
                $processCallback('test', $progressSymbol);
                $processCallback('test', 'FOO');    // unexpected output
                $processCallback('test', $progressSymbol);
                $processCallback('test', $progressSymbol.$progressSymbol.$progressSymbol);  // multi-step
                $processCallback('test', $progressSymbol);
            });

        $processLauncherFactoryProphecy = $this->prophesize(ProcessLauncherFactory::class);
        $processLauncherFactoryProphecy
            ->create(Argument::cetera())
            ->will(static function (array $arguments) use ($processLauncherProphecy, &$processCallback) {
                $processCallback = $arguments[6];

                return $processLauncherProphecy->reveal();
            });

        $executor = self::createMainProcessExecutor(
            $items,
            $createCallable('runSingleCommand'),
            $errorHandler,
            $batchSize,
            $segmentSize,
            $createCallable('runBeforeFirstCommand'),
            $createCallable('runAfterLastCommand'),
            $createCallable('runBeforeBatch'),
            $createCallable('runAfterBatch'),
            $progressSymbol,
            $processLauncherFactoryProphecy->reveal(),
        );

        $expectedOutput = '';
        $expectedCalls = [
            [
                'runBeforeFirstCommand',
                [$input, $output],
            ],
            [
                'runAfterLastCommand',
                [$input, $output],
            ],
        ];
        $expectedLogRecords = [
            [
                'logConfiguration',
                [
                    $segmentSize,
                    $batchSize,
                    5,
                    3,
                    3,
                    2,
                    'items',
                ],
            ],
            [
                'startProgress',
                [5],
            ],
            [
                'advance',
                [1],
            ],
            [
                'logUnexpectedOutput',
                ['FOO', $progressSymbol],
            ],
            [
                'advance',
                [0],
            ],
            [
                'advance',
                [1],
            ],
            [
                'advance',
                [3],
            ],
            [
                'advance',
                [1],
            ],
            [
                'finish',
                ['items'],
            ],
        ];
        $expectedExitCode = 0;  // TODO: should return since a child process failed

        $exitCode = $executor->execute(
            $parallelizationInput,
            $input,
            $output,
            $logger,
        );

        self::assertSame($expectedOutput, $output->fetch());
        self::assertSame($expectedCalls, $calls);
        self::assertSame($expectedLogRecords, $logger->records);
        self::assertSame([], $errorHandler->calls);
        self::assertSame($expectedExitCode, $exitCode);
    }

    /**
     * @dataProvider invalidExecutorProvider
     */
    public function test_it_cannot_create_an_executor_with_an_invalid_value(
        ParallelExecutorFactory $factory,
        string $expectedExceptionMessage
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $factory->build();
    }

    public static function invalidExecutorProvider(): iterable
    {
        $createFactory = static fn () => ParallelExecutorFactory::create(
            FakeCallable::create(),
            FakeCallable::create(),
            FakeCallable::create(),
            'test:command',
            new InputDefinition(),
            new FakeErrorHandler(),
        );

        yield 'invalid batch size' => [
            // @phpstan-ignore-next-line
            $createFactory()->withBatchSize(0),
            'Expected the batch size to be 1 or greater. Got "0".',
        ];

        yield 'invalid segment size' => [
            // @phpstan-ignore-next-line
            $createFactory()->withSegmentSize(0),
            'Expected the segment size to be 1 or greater. Got "0".',
        ];

        yield 'invalid script path' => [
            $createFactory()->withScriptPath('/path/to/nowhere'),
            'The script file could not be found at the path "/path/to/nowhere" (working directory: '.getcwd().')',
        ];

        yield 'invalid progress symbol' => [
            $createFactory()->withProgressSymbol('foo'),
            'Expected the progress symbol length to be 1. Got "3" for "foo".',
        ];

        yield 'invalid progress symbol (emoji)' => [
            $createFactory()->withProgressSymbol('👹👹'),
            'Expected the progress symbol length to be 1. Got "2" for "👹👹".',
        ];
    }

    private static function mainProcessWithChildProcessLaunchedProvider(): iterable
    {
        $batchSize = 2;
        $segmentSize = 2;
        $numberOfProcesses = 5;
        $numberOfProcessesDefined = true;
        $numberOfSegments = 2;
        $numberOfBatches = 2;

        $input = new StringInput('');
        $output = new BufferedOutput();

        $items = [
            'item1',
            'item2',
            'item3',
        ];

        yield [
            new ParallelizationInput(
                $numberOfProcessesDefined,
                $numberOfProcesses,
                null,
                false,
            ),
            $input,
            $output,
            $items,
            $batchSize,
            $segmentSize,
            '👉',
            '',
            0,
            [
                [
                    'runBeforeFirstCommand',
                    [$input, $output],
                ],
                [
                    'runAfterLastCommand',
                    [$input, $output],
                ],
            ],
            [
                [
                    'logConfiguration',
                    [
                        $segmentSize,
                        $batchSize,
                        3,
                        $numberOfSegments,
                        $numberOfBatches,
                        $numberOfProcesses,
                        'items',
                    ],
                ],
                [
                    'startProgress',
                    [3],
                ],
                [
                    'finish',
                    ['items'],
                ],
            ],
            true,
        ];
    }

    /** @noinspection NestedTernaryOperatorInspection */
    private static function mainProcessWithoutChildProcessLaunchedProvider(): iterable
    {
        $batchSize = 2;
        $segmentSize = 3;
        $numberOfProcesses = 1;
        $numberOfSegments = 1;
        $numberOfBatches = 2;

        $input = new StringInput('');
        $output = new BufferedOutput();

        yield [
            new ParallelizationInput(
                false,
                $numberOfProcesses,
                null,
                false,
            ),
            $input,
            $output,
            ['item1', 'item2', 'item3'],
            $batchSize,
            $segmentSize,
            '👉',
            '',
            0,
            [
                [
                    'runBeforeFirstCommand',
                    [$input, $output],
                ],
                [
                    'runBeforeBatch',
                    [$input, $output, ['item1', 'item2']],
                ],
                [
                    'runSingleCommand',
                    ['item1', $input, $output],
                ],
                [
                    'runSingleCommand',
                    ['item2', $input, $output],
                ],
                [
                    'runAfterBatch',
                    [$input, $output, ['item1', 'item2']],
                ],
                [
                    'runBeforeBatch',
                    [$input, $output, ['item3']],
                ],
                [
                    'runSingleCommand',
                    ['item3', $input, $output],
                ],
                [
                    'runAfterBatch',
                    [$input, $output, ['item3']],
                ],
                [
                    'runAfterLastCommand',
                    [$input, $output],
                ],
            ],
            [
                [
                    'logConfiguration',
                    [
                        $segmentSize,
                        $batchSize,
                        3,
                        $numberOfSegments,
                        $numberOfBatches,
                        $numberOfProcesses,
                        'items',
                    ],
                ],
                [
                    'startProgress',
                    [3],
                ],
                [
                    'advance',
                    [],
                ],
                [
                    'advance',
                    [],
                ],
                [
                    'advance',
                    [],
                ],
                [
                    'finish',
                    ['items'],
                ],
            ],
            false,
        ];
    }

    /**
     * @param callable(string, InputInterface, OutputInterface):void       $runSingleCommand
     * @param resource                                                     $childSourceStream
     * @param positive-int                                                 $batchSize
     * @param callable(InputInterface, OutputInterface):void               $runBeforeFirstCommand
     * @param callable(InputInterface, OutputInterface):void               $runAfterLastCommand
     * @param callable(InputInterface, OutputInterface, list<string>):void $runBeforeBatch
     * @param callable(InputInterface, OutputInterface, list<string>):void $runAfterBatch
     */
    private static function createChildProcessExecutor(
        callable $runSingleCommand,
        ItemProcessingErrorHandler $errorHandler,
        $childSourceStream,
        int $batchSize,
        callable $runBeforeFirstCommand,
        callable $runAfterLastCommand,
        callable $runBeforeBatch,
        callable $runAfterBatch,
        string $progressSymbol
    ): ParallelExecutor {
        return new ParallelExecutor(
            FakeCallable::create(),
            $runSingleCommand,
            FakeCallable::create(),
            '',
            new InputDefinition(),
            $errorHandler,
            $childSourceStream,
            $batchSize,
            10,
            $runBeforeFirstCommand,
            $runAfterLastCommand,
            $runBeforeBatch,
            $runAfterBatch,
            $progressSymbol,
            __FILE__,
            __FILE__,
            __DIR__,
            null,
            new FakeProcessLauncherFactory(),
        );
    }

    /**
     * @param list<string>                                                 $items
     * @param callable(string, InputInterface, OutputInterface):void       $runSingleCommand
     * @param positive-int                                                 $batchSize
     * @param positive-int                                                 $segmentSize
     * @param callable(InputInterface, OutputInterface):void               $runBeforeFirstCommand
     * @param callable(InputInterface, OutputInterface):void               $runAfterLastCommand
     * @param callable(InputInterface, OutputInterface, list<string>):void $runBeforeBatch
     * @param callable(InputInterface, OutputInterface, list<string>):void $runAfterBatch
     */
    private static function createMainProcessExecutor(
        array $items,
        callable $runSingleCommand,
        ItemProcessingErrorHandler $errorHandler,
        int $batchSize,
        int $segmentSize,
        callable $runBeforeFirstCommand,
        callable $runAfterLastCommand,
        callable $runBeforeBatch,
        callable $runAfterBatch,
        string $progressSymbol,
        ProcessLauncherFactory $processLauncherFactory
    ): ParallelExecutor {
        return new ParallelExecutor(
            static fn () => $items,
            $runSingleCommand,
            static fn (int $itemCount) => 0 === $itemCount ? 'item' : 'items',
            'import:something',
            new InputDefinition([
                new InputArgument(
                    'groupId',
                    InputArgument::REQUIRED,
                ),
            ]),
            $errorHandler,
            StringStream::fromString(''),
            $batchSize,
            $segmentSize,
            $runBeforeFirstCommand,
            $runAfterLastCommand,
            $runBeforeBatch,
            $runAfterBatch,
            $progressSymbol,
            __FILE__,
            __FILE__,
            __DIR__,
            null,
            $processLauncherFactory,
        );
    }

    private function createProcessLauncherFactory(bool $spawnChildProcesses): ProcessLauncherFactory
    {
        if (!$spawnChildProcesses) {
            return new FakeProcessLauncherFactory();
        }

        $processLauncherProphecy = $this->prophesize(ProcessLauncher::class);
        $processLauncherProphecy
            ->run(Argument::cetera())
            ->shouldBeCalled();

        $processLauncherFactoryProphecy = $this->prophesize(ProcessLauncherFactory::class);
        $processLauncherFactoryProphecy
            ->create(Argument::cetera())
            ->willReturn($processLauncherProphecy->reveal());

        return $processLauncherFactoryProphecy->reveal();
    }
}
