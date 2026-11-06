<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Command;

use OCA\IEPhotoWatermark\Service\DesktopCodeStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DesktopCode extends Command {
    public function __construct(private DesktopCodeStore $codes) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('iephoto:desktop-code')
            ->setDescription('Create, list or revoke IE-PHOTO Uploader codes')
            ->addArgument('action', InputArgument::REQUIRED, 'create | list | revoke')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'event name')
            ->addOption('album', null, InputOption::VALUE_REQUIRED, 'bind to one registered album/sport', '')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'valid for N days (1-90)', '7')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'code id to revoke');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        switch ((string)$input->getArgument('action')) {
            case 'create':
                $made = $this->codes->create((string)$input->getOption('label'), (string)$input->getOption('album'), (int)$input->getOption('days'));
                $output->writeln('code: ' . $made['code']);
                $output->writeln('id:   ' . $made['record']['id']);
                $output->writeln('expires: ' . date('Y-m-d H:i', (int)$made['record']['expiresAt']));
                return 0;
            case 'list':
                foreach ($this->codes->list() as $r) {
                    $output->writeln(sprintf('%s  %-30s album=%-15s expires=%s%s', $r['id'], $r['label'], $r['album'] ?: '-', date('Y-m-d', (int)$r['expiresAt']), $r['revoked'] ? '  REVOKED' : ''));
                }
                return 0;
            case 'revoke':
                $ok = $this->codes->revoke((string)$input->getOption('id'));
                $output->writeln($ok ? 'revoked' : 'not found');
                return $ok ? 0 : 1;
        }
        $output->writeln('unknown action');
        return 1;
    }
}
