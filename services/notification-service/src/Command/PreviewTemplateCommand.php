<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Notification\SmsSegmentCalculator;
use App\Domain\Notification\TemplateRenderer;
use App\Domain\Notification\TemplateSamples;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Перегляд шаблонів повідомлень з типовими даними.
 *
 * Інструмент приймання розділу 11.2: показує реальний текст кожного
 * шаблону і кількість SMS-сегментів (NOT-07).
 */
#[AsCommand(
    name: 'app:notifications:preview',
    description: 'Показує відрендерені тексти шаблонів сповіщень і довжину SMS',
)]
final class PreviewTemplateCommand extends Command
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly SmsSegmentCalculator $segments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'template',
            InputArgument::OPTIONAL,
            'Код шаблону, напр. NOT-T2. Без аргументу показуються всі.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = $input->getArgument('template');

        if (\is_string($code) && '' !== $code) {
            $template = NotificationTemplate::tryFrom($code);
            if (null === $template) {
                $io->error(\sprintf('Невідомий код шаблону «%s».', $code));

                return Command::INVALID;
            }
            $templates = [$template];
        } else {
            $templates = NotificationTemplate::cases();
        }

        foreach ($templates as $template) {
            $payload = TemplateSamples::for($template);
            $io->section($template->code().($template->isCritical() ? ' (критичне)' : ''));

            foreach ($template->channels() as $channel) {
                $rendered = $this->renderer->render($template, $channel, $payload);

                if (NotificationChannel::Sms === $channel) {
                    $io->writeln(\sprintf(
                        '<info>SMS</info> (%d символів, %d сегм.): %s',
                        mb_strlen($rendered->text, 'UTF-8'),
                        $this->segments->segments($rendered->text),
                        $rendered->text,
                    ));

                    continue;
                }

                $io->writeln(\sprintf('<info>E-mail</info> тема: %s', (string) $rendered->subject));
                $io->writeln('<info>E-mail</info> текст: '.$rendered->text);
            }
        }

        return Command::SUCCESS;
    }
}
