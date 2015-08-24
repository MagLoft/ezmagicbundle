<?php

namespace MagLoft\EzmagicBundle\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use MagLoft\EzmagicBundle\Composer\ScriptHandler as EzmagicHandler;
use MagLoft\EzmagicBundle\Service\EzMagicService;
use MagLoft\EzmagicBundle\Composer\ConsoleIO;

class EzmagicDoctorCommand extends ContainerAwareCommand {
    
    protected function configure() {
        $this
            ->setName( 'ezmagic:doctor' )
            ->setDescription( 'Analyze and fix current ezpublish setup.' )
            ->setHelp("The command <info>%command.name%</info> analyzes the current setup (database, configuration files) and fixes common problems.\n");
    }

    protected function execute( InputInterface $input, OutputInterface $output ) {
        $io = new ConsoleIO($input, $output, $this->getHelperSet());
        $ezMagicService = new EzMagicService($io);        

        // Run doctor commands
        $ezMagicService->setupEzConfig();
        $ezMagicService->setupDatabase();
        $ezMagicService->checkDatabase();
    }

}
