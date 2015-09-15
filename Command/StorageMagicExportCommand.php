<?php

namespace MagLoft\EzmagicBundle\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use MagLoft\EzmagicBundle\Composer\ScriptHandler as EzmagicHandler;
use MagLoft\EzmagicBundle\Service\EzMagicService;
use MagLoft\EzmagicBundle\Composer\ConsoleIO;

class StorageMagicExportCommand extends ContainerAwareCommand {
    
    protected function configure() {
        $this
            ->setName( 'ezmagic:storage:export' )
            ->setDescription( 'Export storage via storagemagic.' )
            ->setHelp("The command <info>%command.name%</info> exports the current storage snapshot to a remote location via storagemagic/rsync.\n");
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $io = new ConsoleIO($input, $output, $this->getHelperSet());
        $container = $this->getContainer();
        $ezMagicService = new EzMagicService($io, $container);
        $ezMagicService->validate();
        $ezMagicService->storageMagicExport();
    }

}
