<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Slot\Ramp;
use App\Domain\Slot\ReceivingWindow;
use App\Domain\Slot\ReservedSlotRule;
use App\Domain\Slot\SlotBlock;
use App\Domain\Slot\SlotGridGenerator;
use App\Domain\Slot\SlotOverlays;
use App\Domain\Slot\SlotState;
use App\Domain\Slot\StoreConfig;
use App\Domain\Slot\TimeInterval;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Наочний перегляд обчисленої сітки слотів без БД і HTTP — для перевірки
 * налаштувань магазину очима постачальника або співробітника мережі.
 */
#[AsCommand(
    name: 'yms:slots:preview',
    description: 'Показати сітку слотів магазину на дату',
)]
final class PreviewSlotGridCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Дата у форматі Y-m-d (за замовчуванням — завтра)')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Вікно прийому HH:MM-HH:MM', '08:00-14:00')
            ->addOption('size', null, InputOption::VALUE_REQUIRED, 'Розмір слота у хвилинах: 15, 20, 30 або 60', '30')
            ->addOption('ramps', null, InputOption::VALUE_REQUIRED, 'Кількість рамп', '2')
            ->addOption('lead', null, InputOption::VALUE_REQUIRED, 'Мінімальний lead time у хвилинах', '60')
            ->addOption('tons', null, InputOption::VALUE_REQUIRED, 'Максимальний тоннаж авто', '20')
            ->addOption('supplier', null, InputOption::VALUE_REQUIRED, 'Дивитися очима постачальника (порожньо — очима мережі)')
            ->addOption('block', null, InputOption::VALUE_REQUIRED, 'Блокування у вигляді rampId:HH:MM-HH:MM')
            ->addOption('reserve', null, InputOption::VALUE_REQUIRED, 'Резерв у вигляді supplierId:rampId:HH:MM');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tz = new DateTimeZone(StoreConfig::TIMEZONE);
        $now = new DateTimeImmutable('now', $tz);

        $date = (string) ($input->getOption('date') ?? '')
            ?: $now->modify('+1 day')->format('Y-m-d');

        [$from, $to] = explode('-', (string) $input->getOption('window'), 2);
        $rampCount = max(1, (int) $input->getOption('ramps'));

        $ramps = [];
        for ($i = 1; $i <= $rampCount; ++$i) {
            $ramps[] = new Ramp('ramp-'.$i, 'Рампа '.$i);
        }

        $dayOfWeek = (int) (new DateTimeImmutable($date, $tz))->format('N');

        $config = new StoreConfig(
            storeId: 'preview-store',
            receivingWindows: [new ReceivingWindow($dayOfWeek, [new TimeInterval($from, $to)])],
            slotSizeMinutes: (int) $input->getOption('size'),
            ramps: $ramps,
            maxVehicleWeightTons: (float) $input->getOption('tons'),
            leadTimeMinutes: (int) $input->getOption('lead'),
        );

        $overlays = new SlotOverlays(
            blocks: $this->parseBlock($input->getOption('block'), $date, $tz),
            reservedRules: $this->parseReserve($input->getOption('reserve'), $dayOfWeek),
        );

        $viewer = $input->getOption('supplier');
        $viewer = '' === $viewer ? null : $viewer;

        $grid = (new SlotGridGenerator())->generate($config, $date, $now, $viewer, $overlays);

        $io->title(\sprintf('Сітка слотів на %s (%s)', $date, $viewer ?? 'перегляд мережі'));

        if ([] === $grid->slots) {
            $io->warning('Цього дня магазин поставок не приймає.');

            return Command::SUCCESS;
        }

        $byTime = [];
        foreach ($grid->slots as $slot) {
            $byTime[$slot->localStartTime()][$slot->key->rampId] = $slot;
        }

        $rows = [];
        foreach ($byTime as $time => $slotsByRamp) {
            $row = [$time];
            foreach ($ramps as $ramp) {
                $slot = $slotsByRamp[$ramp->rampId] ?? null;
                $row[] = null === $slot ? '—' : $this->render($slot->state, $slot->reservedForViewer);
            }
            $rows[] = $row;
        }

        $io->table(array_merge(['Час'], array_map(static fn (Ramp $r) => $r->name, $ramps)), $rows);

        $io->definitionList(
            ['Вільних слотів' => \count($grid->selectableSlots()).' з '.\count($grid->slots)],
            ['Максимальний тоннаж' => $grid->maxVehicleWeightTons.' т'],
            ['Розмір слота' => $grid->slotSizeMinutes.' хв'],
            ['Lead time' => $grid->leadTimeMinutes.' хв'],
        );

        return Command::SUCCESS;
    }

    private function render(SlotState $state, bool $reservedForViewer): string
    {
        if ($reservedForViewer) {
            return '<fg=green>вільний (ваш резерв)</>';
        }

        return match ($state) {
            SlotState::Available => '<fg=green>вільний</>',
            SlotState::Held => '<fg=yellow>оформлюється</>',
            SlotState::Booked => '<fg=red>заброньований</>',
            SlotState::Reserved => '<fg=magenta>резерв</>',
            SlotState::Blocked => '<fg=red>заблокований</>',
            SlotState::Past => '<fg=gray>минув</>',
        };
    }

    /** @return list<SlotBlock> */
    private function parseBlock(mixed $option, string $date, DateTimeZone $tz): array
    {
        if (!\is_string($option) || '' === $option) {
            return [];
        }

        [$rampId, $range] = explode(':', $option, 2);
        [$from, $to] = explode('-', $range, 2);

        return [new SlotBlock(
            'preview-store',
            $rampId,
            new DateTimeImmutable($date.' '.$from, $tz),
            new DateTimeImmutable($date.' '.$to, $tz),
            'Демонстраційне блокування',
        )];
    }

    /** @return list<ReservedSlotRule> */
    private function parseReserve(mixed $option, int $dayOfWeek): array
    {
        if (!\is_string($option) || '' === $option) {
            return [];
        }

        [$supplierId, $rampId, $time] = explode(':', $option, 3);

        return [new ReservedSlotRule($supplierId, $rampId, $time, dayOfWeek: $dayOfWeek)];
    }
}
