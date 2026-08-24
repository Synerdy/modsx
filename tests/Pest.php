<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Artisan;
use Modsx\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

uses(TestCase::class)->in('Feature');

/**
 * Run an artisan command and return its raw stdout.
 *
 * Commands with --json must emit nothing but JSON, so tests need the output
 * itself rather than the assertions the pending-command object offers.
 */
function artisanOutput(string $command): string
{
    $buffer = new BufferedOutput;

    Artisan::call($command, [], $buffer);

    return $buffer->fetch();
}
