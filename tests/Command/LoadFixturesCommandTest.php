<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\LoadFixturesCommand;
use App\Service\DataWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class LoadFixturesCommandTest extends TestCase
{

    public function testRefusesToRunInProdWithoutForce(): void
    {
        $dataWriter = $this->createMock(DataWriter::class);
        $dataWriter->expects($this->never())->method('write');

        $tester = new CommandTester(new LoadFixturesCommand($dataWriter, 'prod'));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Refusing to load fixtures', $tester->getDisplay());
    }

    public function testRunsInProdWithForce(): void
    {
        $dataWriter = $this->createMock(DataWriter::class);
        $dataWriter->method('tableExists')->willReturn(true);
        $dataWriter->expects($this->exactly(480))->method('write');
        $dataWriter->expects($this->once())->method('flush');

        $tester = new CommandTester(new LoadFixturesCommand($dataWriter, 'prod'));
        $exitCode = $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function testCreatesTableWhenMissing(): void
    {
        $dataWriter = $this->createMock(DataWriter::class);
        $dataWriter->method('tableExists')->willReturn(false);
        $dataWriter->expects($this->once())->method('make')->with('froggit', null);
        $dataWriter->expects($this->exactly(480))->method('write');

        $tester = new CommandTester(new LoadFixturesCommand($dataWriter, 'dev'));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Loaded 480 sample readings', $tester->getDisplay());
    }

    public function testReportsFailureWhenFlushFails(): void
    {
        $dataWriter = $this->createMock(DataWriter::class);
        $dataWriter->expects($this->once())->method('tableExists')->willReturn(true);
        $dataWriter->expects($this->exactly(480))->method('write');
        $dataWriter->expects($this->once())->method('flush')->willThrowException(new RuntimeException('influx unreachable'));

        $tester = new CommandTester(new LoadFixturesCommand($dataWriter, 'test'));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('influx unreachable', $tester->getDisplay());
    }

}
