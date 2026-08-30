<?php

declare(strict_types=1);

namespace Modsx\Console\Concerns;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

/**
 * The prompt shown before a command changes or removes something.
 *
 * Kept apart from InteractsWithModules, which every command uses, because this
 * belongs only to the ones that declare --force. A command without that option
 * has no way to answer the prompt when run from a script, so being unable to
 * use this method is the point rather than a limitation.
 *
 * @phpstan-require-extends Command
 */
trait ConfirmsDestructiveActions
{
    /**
     * Confirm a destructive action, unless --force was passed.
     */
    protected function confirmDestructive(string $question): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('This command changes files. Re-run it with --force in non-interactive mode.');

            return false;
        }

        return confirm(label: $question, default: false);
    }
}
