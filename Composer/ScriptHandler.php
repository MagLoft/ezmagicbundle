<?php

namespace MagLoft\EzmagicBundle\Composer;

use Composer\Script\Event;
use MagLoft\EzmagicBundle\Service\EzMagicService;

class ScriptHandler {
    
    protected static $ezConfig;
    public static $io;

    public static function setupEzConfig(Event $event) {
        $io = $event->getIO();
        $ezMagicService = new EzMagicService($io);        
        $ezMagicService->setupEzConfig();
    }

    public static function setupDatabase(Event $event) {
        $io = $event->getIO();
        $ezMagicService = new EzMagicService($io);        
        $ezMagicService->setupDatabase();
    }
    
    public static function checkDatabase(Event $event) {
        $io = $event->getIO();
        $ezMagicService = new EzMagicService($io);        
        $ezMagicService->checkDatabase();
    }
    
    public static function dbMagicExport(Event $event) {
        $io = $event->getIO();
        $ezMagicService = new EzMagicService($io);        
        $ezMagicService->dbMagicExport();
    }
    
}
