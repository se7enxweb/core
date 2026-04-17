<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\Core\EventListener;

use Ibexa\Bundle\Core\Command\BackwardCompatibleCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class BackwardCompatibleCommandListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => [
                ['onConsoleCommand', 128],
            ],
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if (!$command instanceof BackwardCompatibleCommand) {
            return;
        }

        $input = $event->getInput();

        $alias = $input->hasArgument('command') ? $input->getArgument('command') : null;
        if (in_array($alias, $command->getDeprecatedAliases(), true)) {
            $io = new SymfonyStyle($event->getInput(), $event->getOutput());
            $io->warning(sprintf(
                'Command alias "%s" is deprecated in Exponential Platform 4.6 and will be removed in a future major release. Use "%s" instead.',
                $alias,
                $event->getCommand()->getName()
            ));
        }
    }
}

class_alias(BackwardCompatibleCommandListener::class, 'eZ\Bundle\EzPublishCoreBundle\EventListener\BackwardCompatibleCommandListener');
