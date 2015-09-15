<?php

namespace MagLoft\EzmagicBundle\Composer;

use Composer\Script\Event;
use MagLoft\EzmagicBundle\Service\EzMagicService;

class ScriptHandler {
    
    protected static $ezConfig;
    public static $io;

    public static function setupEzMagic(Event $event) {
        $io = $event->getIO();
        $container = self::getContainer($event);
        $ezMagicService = new EzMagicService($io, $container);
        $ezMagicService->validate();
        $ezMagicService->setupDatabase();
        $ezMagicService->checkDatabase();
    }

    public static function getContainer(Event $event) {
        $extra = $event->getComposer()->getPackage()->getExtra();
        $kernelrootdir = rtrim(getcwd(), '/') . '/' . trim($extra['symfony-app-dir'], '/');
        require_once "$kernelrootdir/EzPublishKernel.php";
        $kernel = new \EzPublishKernel("dev", FALSE);
        $kernel->boot();
        return $kernel->getContainer();
    }

}
