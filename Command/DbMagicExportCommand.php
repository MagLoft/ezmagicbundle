<?php

namespace MagLoft\EzmagicBundle\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use MagLoft\EzmagicBundle\Composer\ScriptHandler as EzmagicHandler;
use MagLoft\EzmagicBundle\Service\EzMagicService;
use MagLoft\EzmagicBundle\Composer\ConsoleIO;

class DbMagicExportCommand extends ContainerAwareCommand {
    
    protected function configure() {
        $this
            ->setName( 'ezmagic:db:export' )
            ->setDescription( 'Export database via dbmagic.' )
            ->setHelp("The command <info>%command.name%</info> exports the current database snapshot to a remote location via dbmagic/rsync.\n");
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $io = new ConsoleIO($input, $output, $this->getHelperSet());
        $ezMagicService = new EzMagicService($io);
        $ezMagicService->setupDatabase();
        $ezMagicService->dbMagicExport();
    }

}
