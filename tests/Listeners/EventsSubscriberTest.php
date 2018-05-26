<?php

namespace AvtoDev\EventsLogLaravel\Tests\Listeners;

use Illuminate\Support\Str;
use Illuminate\Log\LogManager;
use AvtoDev\EventsLogLaravel\Tests\AbstractTestCase;
use Illuminate\Config\Repository as ConfigRepository;
use AvtoDev\EventsLogLaravel\Listeners\EventsSubscriber;
use Psr\Log\LoggerInterface;

class EventsSubscriberTest extends AbstractTestCase
{
    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        parent::setUp();

        /** @var ConfigRepository $config */
        $config = $this->app->make('config');

        $config->set('logging.channels.test_events_channel', [
            'driver' => 'single',
            'path'   => storage_path('logs/events.log'),
            'level'  => 'debug',
        ]);

        $this->clearLog();
    }

    /**
     * Test constructor without passing log channel name.
     *
     * @return void
     */
    public function testConstructWithoutChannelName(): void
    {
        $subscriber = new EventsSubscriber($this->app->make(LogManager::class));

        $this->assertLogFileNotContains('Using emergency logger');
        $this->assertInstanceOf(LoggerInterface::class, $subscriber->logDriver());
    }

    /**
     * Test constructor with passing invalid log channel name.
     *
     * @return void
     */
    public function testConstructWithInvalidChannelName(): void
    {
        foreach ([Str::random(), ''] as $assert) {
            $this->clearLog();

            new EventsSubscriber($this->app->make(LogManager::class), $assert);

            $this->assertLogFileContains('Using emergency logger');
        }
    }

    /**
     * Test constructor with passing valid (default) log channel name.
     *
     * @return void
     */
    public function testConstructWithValidChannelName(): void
    {
        $default = $this->app->make('config')->get('logging.default');

        new EventsSubscriber($this->app->make(LogManager::class), $default);

        $this->assertLogFileNotContains('Using emergency logger');
    }

    /**
     * Test method, that called an any application events (event with needed interface).
     *
     * @return void
     */
    public function testListenOnlyEventsWithInterface(): void
    {
        $event = new Stubs\LoggableEventStub;

        $mock = $this
            ->getMockBuilder(EventsSubscriber::class)
            ->setConstructorArgs([$this->app->make(LogManager::class), 'test_events_channel'])
            ->setMethods([$write_log_method_name = 'writeEventIntoLog'])
            ->getMock();

        $mock
            ->expects($this->once())
            ->method($write_log_method_name);

        /* @var $mock EventsSubscriber */
        $mock->onAnyEvents(\get_class($event), [$event]);
    }

    /**
     * Test method, that called an any application events (event without needed interface).
     *
     * @return void
     */
    public function testListenOnlyEventsWithoutInterface(): void
    {
        $event = new Stubs\NotLoggableEventStub;

        $mock = $this
            ->getMockBuilder(EventsSubscriber::class)
            ->setConstructorArgs([$this->app->make(LogManager::class), 'test_events_channel'])
            ->setMethods([$write_log_method_name = 'writeEventIntoLog'])
            ->getMock();

        $mock
            ->expects($this->never())
            ->method($write_log_method_name);

        /* @var $mock EventsSubscriber */
        $mock->onAnyEvents(\get_class($event), [$event]);
    }

    /**
     * Test log writing method.
     *
     * @return void
     */
    public function testWriteEventIntoLog(): void
    {
        $instance = new EventsSubscriber($this->app->make(LogManager::class), 'test_events_channel');

        $instance->writeEventIntoLog($event = new Stubs\LoggableEventStub);

        $expects = [
            $event->logMessage(),
            $event->eventType(),
            $event->eventSource(),
            $this->app->environment() . '.' . Str::upper($event->logLevel()),
        ];

        $expects = array_merge($expects, array_keys($event->logEventExtraData()));
        $expects = array_merge($expects, array_values($event->logEventExtraData()));

        foreach ($expects as $expect) {
            $this->assertLogFileContains($expect, 'events.log');
        }
    }

    /**
     * Test throws exception with passing invalid log level keyword.
     *
     * @return void
     */
    public function testExceptionThrowsOnLogWritingWithPassingInvalidLogLevel(): void
    {
        $instance = new EventsSubscriber($this->app->make(LogManager::class), 'test_events_channel');

        $exceptions_counter = 0;

        foreach (($wrong_levels = ['foo', 'bar']) + ($good_levels = ['Debug', 'INFO', 'emeRgency']) as $level_name) {
            $mock = $this
                ->getMockBuilder(Stubs\LoggableEventStub::class)
                ->getMock();

            $mock
                ->expects($this->once())
                ->method('logLevel')
                ->will($this->returnValue($level_name));

            /* @var Stubs\LoggableEventStub $mock */
            try {
                $instance->writeEventIntoLog($mock);

                $this->assertLogFileContains(
                    $this->app->environment() . '.' . Str::upper(trim($level_name)),
                    'events.log'
                );
            } catch (\Error $e) {
                $this->assertStringStartsWith('Call to undefined method', $e->getMessage());

                $exceptions_counter++;
            }
        }

        $this->assertEquals(\count($wrong_levels), $exceptions_counter);
    }
}