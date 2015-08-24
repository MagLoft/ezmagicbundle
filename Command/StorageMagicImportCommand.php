<?php

namespace MagLoft\EzmagicBundle\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use MagLoft\EzmagicBundle\Composer\ScriptHandler as EzmagicHandler;
use MagLoft\EzmagicBundle\Service\EzMagicService;
use MagLoft\EzmagicBundle\Composer\ConsoleIO;

class StorageMagicImportCommand extends ContainerAwareCommand {
    
    protected function configure() {
        $this
            ->setName( 'ezmagic:storage:import' )
            ->setDescription( 'Import storage via storagemagic.' )
            ->setHelp("The command <info>%command.name%</info> imports the current storage snapshot from a remote location via storagemagic/rsync.\n");
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $io = new ConsoleIO($input, $output, $this->getHelperSet());
        $ezMagicService = new EzMagicService($io);        
        $ezMagicService->storageMagicImport();
    }

}
