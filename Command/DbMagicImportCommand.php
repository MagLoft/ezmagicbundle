<?php

namespace MagLoft\EzmagicBundle\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use MagLoft\EzmagicBundle\Composer\ScriptHandler as EzmagicHandler;
use MagLoft\EzmagicBundle\Service\EzMagicService;
use MagLoft\EzmagicBundle\Composer\ConsoleIO;

class DbMagicImportCommand extends ContainerAwareCommand {

    protected function configure() {
        $this
            ->setName( 'ezmagic:db:import' )
            ->setDescription( 'Import database via dbmagic.' )
            ->setHelp("The command <info>%command.name%</info> imports the current database snapshot from a remote location via dbmagic/rsync.\n");
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $io = new ConsoleIO($input, $output, $this->getHelperSet());
        $ezMagicService = new EzMagicService($io);
        $ezMagicService->setupDatabase();
        $ezMagicService->dbMagicImport();
    }

}
