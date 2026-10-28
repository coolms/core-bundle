<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\DependencyInjection\Compiler;

use CoolMS\Core\Bundle\Console\InstallCommand;
use CoolMS\Core\Bundle\DependencyInjection\Compiler\CoreServicesPass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * InstallCommand's questions are handed by name, as its installers are. The constructor defaults them to none, so a
 * wiring that missed them would install without asking anything, silently: this is what makes it fail.
 */
final class CoreServicesPassInstallQuestionsTest extends TestCase
{
    #[Test]
    public function theInstallCommandIsHandedEveryTaggedQuestion(): void
    {
        $container = new ContainerBuilder();
        $container->register(InstallCommand::class, InstallCommand::class);

        new CoreServicesPass()->process($container);

        $questions = $container->getDefinition(InstallCommand::class)->getArgument('$questions');
        self::assertInstanceOf(TaggedIteratorArgument::class, $questions);
        self::assertSame('coolms.install_question', $questions->getTag());
    }
}
