<?php

/* 
 *  KNOWN BUGS:
 *      - ezpublish/logs directory does not exist initially
 *      - requirements: node, lessc
 *
 *  COMPOSER SETUP:
 *      "post-install-cmd": [
 *          "Incenteev\\ParameterHandler\\ScriptHandler::buildParameters",
 *          "Sensio\\Bundle\\DistributionBundle\\Composer\\ScriptHandler::buildBootstrap",
 *          "MagLoft\\EzmagicBundle\\Composer\\ScriptHandler::setupEzMagic",
 *          ...
 *      ]
 */

namespace MagLoft\EzmagicBundle\Service;

use Symfony\Component\DependencyInjection\Container;
use PDO;
use PDOException;

class EzMagicService {
    protected $io;
    protected $container;
    protected $config;
    
    public function __construct($io, Container $container) {
        $this->io = $io;
        $this->container = $container;
    }
    
    // EzMagic Bootstrap

    public function validate() {
        $config = $this->getConfig();

        // Validate configuration
        if(!$config["ezmagic_slug"]) {
            $this->error("Missing parameter: ezmagic_slug");
            die();
        }

        if(!$config["ezmagic_bucket"]) {
            $this->error("Missing parameter: ezmagic_bucket");
            die();
        }

        // Create necessary folders
        if(!file_exists('ezpublish_legacy/var/storage')) {
            mkdir('ezpublish_legacy/var/storage');
            $this->success("Successfully created storage directory!");
        }
        if(!file_exists('ezpublish/logs')) {
            mkdir('ezpublish/logs');
            $this->success("Successfully created logs directory!");
        }
    }

    // Database Setup

    public function setupDatabase() {

        // Loop through database tasks until handle is valid
        while(($result = $this->getDatabaseHandle()) instanceof PDOException) {
            $this->error('A database error occured!');
            if($this->confirm('Run diagnosis?')) {
                $this->runDatabaseDiagnosis();
            }else{
                $this->info('Update your ezpublish/parameters.yml and try again.');
                die();
            }
        }

        $this->success('Database test successful!');

    }

    public function checkDatabase() {
        $config = $this->getConfig();
        $dsn = "mysql:host={$config['database_host']};dbname={$config["database_name"]}";

        try{
            $db = new PDO($dsn, $config['database_user'], $config['database_password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            $sql = "SELECT `value` FROM `ezsite_data` WHERE `name` = 'ezpublish-version' LIMIT 1;";
            $statement = $db->prepare($sql);
            $statement->execute();
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            $this->success("Found installed eZPublish Database (version {$result['value']})!");
        }catch(PDOException $ex){
            $this->info('It seems like you have not yet imported a database dump.');
            $confirmation = $this->confirm('Import dump with dbmagic?', true);
            if($confirmation === true) {
                $this->dbMagicImport();
            }else{
                $this->info('Database not ready -> exiting...');
                die();
            }
        }
    }

    public function storageMagicExport() {
        $this->info('Exporting ezpublish storage via rsync');
        $config = $this->getConfig();

        // Security check if directory is empty
        if (!is_readable("ezpublish_legacy/var/storage/") || count(scandir("ezpublish_legacy/var/storage/")) == 2) {
            $this->error('Storage directory (ezpublish_legacy/var/storage) is empty');
            $this->info('Aborting export...', true);
            die();
        }

        // Security test to avoid unconsious override of published storage
        $this->info('Warning: Exporting the storage will override your currently stored storage.', true);
        $confirmation = $this->ask("Type '{$config["ezmagic_slug"]}' to publish the storage directory to fsmagic", false);
        if($confirmation !== $config["ezmagic_slug"]) {
            $this->info('Aborting export...', true);
            die();
        }

        // Upload storage directory
        if($this->execute("gsutil -m rsync -d ezpublish_legacy/var/storage/ gs://{$config["ezmagic_bucket"]}/fsmagic/{$config["ezmagic_slug"]}/") === false) {
            $this->info('Aborting fsmagic export...');
            die();
        }

        $this->success('Storage was successfully exported!');

        return true;
    }

    public function storageMagicImport() {

        $this->info('Importing ezpublish storage via rsync');

        // Download storage directory
        $dbConfig = $this->getConfig('database');
        if(($this->execute("rsync -avz --progress -e 'ssh' {$dbConfig["ssh_host"]}:~/fsmagic/{$dbConfig["slug"]}/ ezpublish_legacy/var/storage/")) === false) {
            $this->info('Aborting fsmagic import...');
            die();
        }

        $this->success('Storage was successfully imported!');

        return true;
    }

    public function dbMagicExport() {
        $this->info('Exporting dbmagic database via rsync');
        $config = $this->getConfig();

        // Security test to avoid unconsious override of published database
        $this->info('Warning: Exporting the Database will override your currently stored database.', true);
        $confirmation = $this->ask("Type '{$config["ezmagic_slug"]}' to PUBLISH the database TO dbmagic", false);
        if($confirmation !== $config["ezmagic_slug"]) {
            $this->info('Aborting export...', true);
            die();
        }

        // Check if database exists
        $db = $this->getDatabaseHandle();
        if($db instanceof PDOException) {
            $this->error("Could not export database '{$config["database_name"]}':");
            $this->error($db->getMessage());
            $this->info('Aborting export...', true);
            die();
        }

        // Dump database to temporary file
        if(($mysqlDumpContents = $this->execute("mysqldump -h {$config["database_host"]} -u {$config['database_user']} --password='{$config['database_password']}' {$config["database_name"]}")) === false) {
            $this->info('Aborting dbmagic export...');
            die();
        }else{
            file_put_contents("/tmp/{$config["ezmagic_slug"]}.sql", $mysqlDumpContents);
        }

        // Zip database dump
        if($this->execute("gzip -f /tmp/{$config["ezmagic_slug"]}.sql") === false) {
            $this->info('Aborting dbmagic export...');
            die();
        }

        // Encrypt database dump
        $command = "echo {$config["secret"]} | gpg --yes --output /tmp/{$config["ezmagic_slug"]}.sql.gz.enc --batch --passphrase-fd 0 -c --cipher-algo TWOFISH /tmp/{$config["ezmagic_slug"]}.sql.gz";
        if($this->execute($command) === false) {
            $this->info('Aborting dbmagic export...');
            die();
        }

        // Upload server dump
        if($this->execute("gsutil cp /tmp/{$config["ezmagic_slug"]}.sql.gz.enc gs://{$config["ezmagic_bucket"]}/dbmagic/{$config["ezmagic_slug"]}.sql.gz.enc") === false) {
            $this->info('Aborting dbmagic export...');
            die();
        }

        // Clean up temporary files
        $this->execute("rm -f /tmp/{$config["ezmagic_slug"]}.sql");
        $this->execute("rm -f /tmp/{$config["ezmagic_slug"]}.sql.gz");
        $this->execute("rm -f /tmp/{$config["ezmagic_slug"]}.sql.gz.enc");

        $this->success('Database dump was successfully exported!');

        return true;
    }

    public function dbMagicImport() {

        $this->info('Importing dbmagic database via rsync');
        $config = $this->getConfig();

        // Security test to avoid unconsious override of published database
        $this->info('Warning: Importing the Database will override your local database.', true);
        $confirmation = $this->ask("Type '{$config["ezmagic_slug"]}' to IMPORT the database FROM dbmagic", false);
        if($confirmation !== $config["ezmagic_slug"]) {
            $this->info('Aborting export...', true);
            die();
        }

        // Download server dump
        if($this->execute("gsutil cp gs://{$config["ezmagic_bucket"]}/dbmagic/{$config["ezmagic_slug"]}.sql.gz.enc /tmp/{$config["ezmagic_slug"]}.sql.gz.enc") === false) {
            $this->info('Aborting dbmagic import...');
            die();
        }

        // Decrypt database dump
        $command = "echo {$config["secret"]} | gpg --yes --output /tmp/{$config["ezmagic_slug"]}.sql.gz --batch --passphrase-fd 0 -d /tmp/{$config["ezmagic_slug"]}.sql.gz.enc";
        if($this->execute($command) === false) {
            $this->info('Aborting dbmagic import...');
            die();
        }

        // Unzip database dump
        if($this->execute("gunzip -f /tmp/{$config["ezmagic_slug"]}.sql.gz") === false) {
            $this->info('Aborting dbmagic import...');
            die();
        }

        // Import server dump
        if(($this->execute("mysql -h {$config["database_host"]} -u {$config["database_user"]} --password='{$config["database_password"]}' {$config["database_name"]} < /tmp/{$config["ezmagic_slug"]}.sql")) === false) {
            $this->info('Aborting dbmagic import...');
            die();
        }

        // Clean up unencrypted dumps
        $this->execute("rm -f /tmp/{$config["ezmagic_slug"]}.sql");
        $this->execute("rm -f /tmp/{$config["ezmagic_slug"]}.sql.gz");

        $this->success('Database dump was successfully imported!');

        return true;
    }
    
    // HELPERS

    private function getConfig() {
        if(!$this->config) {
            $this->config = array(
                "secret" => $this->container->getParameter("secret"),
                "database_driver" => $this->container->getParameter("database_driver"),
                "database_host" => $this->container->getParameter("database_host"),
                "database_port" => $this->container->getParameter("database_port"),
                "database_name" => $this->container->getParameter("database_name"),
                "database_user" => $this->container->getParameter("database_user"),
                "database_password" => $this->container->getParameter("database_password"),
                "ezmagic_slug" => $this->container->getParameter("ezmagic_slug"),
                "ezmagic_bucket" => $this->container->getParameter("ezmagic_bucket")
            );
        }
        return $this->config;
    }

    protected function getDatabaseHandle() {
        $config = $this->getConfig();
        $dsn = "mysql:host={$config['database_host']};dbname={$config["database_name"]}";
        
        try{
            $db = new PDO($dsn, $config['database_user'], $config['database_password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            return $db;
        }catch(PDOException $ex){
            return $ex;
        }
    }
    
    protected function createDatabase() {
        $config = $this->getConfig();
        
        // fetch root credentials
        $rootUser = $this->ask('MySQL root user:', $config["database_user"]);
        $rootPassword = $this->ask('MySQL root password:', $config["database_password"]);
        
        // connect database
        $dsn = $this->buildDsn(array( 'host' => $config["database_host"], 'port' => $config["database_port"] ));
        $db = new PDO($dsn, $rootUser, $rootPassword, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        
        // execute statements
        $createSQL = "CREATE DATABASE `{$config["database_name"]}` DEFAULT CHARACTER SET `utf8`;";
        $db->exec($createSQL);
        $grantSQL = "GRANT CREATE ROUTINE, CREATE VIEW, ALTER, SHOW VIEW, CREATE, ALTER ROUTINE, EVENT, INSERT, SELECT, DELETE, TRIGGER, REFERENCES, UPDATE, DROP, EXECUTE, LOCK TABLES, CREATE TEMPORARY TABLES, INDEX ON `{$config["database_name"]}`.* TO '{$config["database_user"]}'@'{$config["database_host"]}'; FLUSH PRIVILEGES;";
        $db->exec($grantSQL);        
    }
    
    protected function runDatabaseDiagnosis($rootUser=false, $rootPassword=false) {
        $config = $this->getConfig();

        // fetch root credentials
        $rootUser = $rootUser ? $rootUser : $this->ask('MySQL root user:', $config["database_user"]);
        $rootPassword = $rootPassword ? $rootPassword : $this->ask('MySQL root password:', $config["database_password"]);
        
        // step one: check root login
        $dsn = $this->buildDsn(array( 'host' => $config["database_host"], 'port' => $config["database_port"] ));
        try{
            $db = new PDO($dsn, $rootUser, $rootPassword, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        }catch(PDOException $ex) {
            $this->info('The system was not able to log in to your mysql server. Are you providing root user credentials?');
            $this->error($ex->getMessage());
            $this->retry();
            return $this->runDatabaseDiagnosis();
        }
        
        // step two: check privileges
        if(!$this->dbUserHasRootPrivileges($db)) {
            $this->error("The user '$rootUser' does not have root privileges!");
            $this->retry();
            return $this->runDatabaseDiagnosis();
        }else{
            $this->success('Root user has sufficient privileges!');
        }
        
        // step three: check is user exists
        if(!$this->dbUserExists($db, $config["database_user"], $config["database_host"])) {
            $this->error("The user '{$config["database_user"]}' does not exist!");
            if($this->confirm('Create user?')) {
                $this->dbCreateUser($db, $config["database_user"], $config["database_password"], $config["database_host"]);
                return $this->runDatabaseDiagnosis($rootUser, $rootPassword);
            }else{
                $this->retry();
                return $this->runDatabaseDiagnosis($rootUser, $rootPassword);
            }
        }else{
            $this->success("User '{$config["database_user"]}' exists!");
        }
        
        // step four: check if database exists
        if(!$this->dbDatabaseExists($db, $config["database_name"], $config["database_host"])) {
            $this->error("The database '{$config["database_name"]}' does not exist!");
            if($this->confirm('Create database?')) {
                $this->dbCreateDatabase($db, $config["database_name"]);
                return $this->runDatabaseDiagnosis($rootUser, $rootPassword);
            }else{
                $this->retry();
                return $this->runDatabaseDiagnosis($rootUser, $rootPassword);
            }
        }else{
            $this->success("Database '{$config["database_name"]}' exists!");
        }
        
        // step five: check schema privileges
        $clientDsn = $this->buildDsn(array( 'host' => $config["database_host"], 'port' => $config["database_port"], 'dbname' => $config["database_name"] ));
        try {
            new PDO($clientDsn, $config["database_user"], $config["database_password"]);
            $this->success("User '{$config["database_user"]}' has privileges to access database '{$config["database_name"]}'!");
        }catch(PDOException $ex){
            // check for access denied error
            if($ex->getCode() == 1044) {
                $this->error("The user '{$config["database_user"]}' does not have privileges to access the database '{$config["database_name"]}'!");
                if($this->confirm('Create privileges?')) {
                    $this->dbCreatePrivileges($db, $config["database_user"], $config["database_name"], $config["database_host"]);
                    return $this->runDatabaseDiagnosis($rootUser, $rootPassword);
                }else{
                    $this->retry();
                    return $this->runDatabaseDiagnosis($rootUser, $rootPassword);
                }
            }else{
                $this->error('An unexpected error occured:');
                $this->error($ex->getMessage());
                $this->retry();
                return $this->runDatabaseDiagnosis($rootUser, $rootPassword);
            }
        }
        
        return true;
    }
    
    protected function dbCreatePrivileges($db, $username, $databaseName, $host) {
        $db->exec("GRANT CREATE ROUTINE, CREATE VIEW, ALTER, SHOW VIEW, CREATE, ALTER ROUTINE, EVENT, INSERT, SELECT, DELETE, TRIGGER, REFERENCES, UPDATE, DROP, EXECUTE, LOCK TABLES, CREATE TEMPORARY TABLES, INDEX ON `$databaseName`.* TO '$username'@'$host'; FLUSH PRIVILEGES;");
    }

    protected function dbCreateDatabase($db, $databaseName) {
        $db->exec("CREATE DATABASE `$databaseName` DEFAULT CHARACTER SET `utf8`;");
    }
    
    protected function dbDatabaseExists($db, $databaseName, $host) {
        $statement = $db->prepare("SELECT COUNT(*) AS count FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$databaseName';");
        $statement->execute();
        $count = (int) $statement->fetch(PDO::FETCH_COLUMN, 0);
        return $count > 0;
    }
    
    protected function dbCreateUser($db, $username, $password, $host) {
        $statement = $db->prepare("CREATE USER '$username'@'$host' IDENTIFIED BY '$password';");
        $statement->execute();
    }
    
    protected function dbUserHasRootPrivileges($db) {
        $statement = $db->prepare('SHOW GRANTS;');
        $statement->execute();
        $privileges = $statement->fetchAll(PDO::FETCH_COLUMN, 0);
        foreach($privileges as $privilege) {
            if(stripos($privilege, 'GRANT ALL PRIVILEGES ON *.* TO ') !== false) {
                return true;
            }
        }
        return false;
    }
    
    protected function dbUserExists($db, $username, $host) {
        $statement = $db->prepare("SELECT COUNT(*) AS count FROM mysql.user WHERE User = '$username' AND Host = '$host';");
        $statement->execute();
        $count = (int) $statement->fetch(PDO::FETCH_COLUMN, 0);
        return $count > 0;
    }
    
    protected function buildDsn($params) {
        $paramsStringArray = array();
        foreach($params as $key => $value) {
            $paramsStringArray[] = "$key=$value";
        }
        return 'mysql:' . implode(';', $paramsStringArray);
    }
    
    // INPUT/OUTPUT
    
    protected function ask($question, $default) {
        if($default) {
            return $this->io->ask(sprintf(' 🔶  <comment>%s</comment> (<comment>%s</comment>): ', $question, $default), $default); 
        }else{
            return $this->io->ask(sprintf(' 🔶  <comment>%s</comment>: ', $question, $default), $default);
        }
    }
    
    protected function confirm($question, $default=true) {
        return $this->io->askConfirmation(" 🔶  <comment>$question</comment> (<comment>y/n</comment>): ", $default);
    }
    
    protected function retry() {
        return $this->io->askConfirmation(" 🔶  <comment>(press return to retry)</comment> ", true);
    }

    protected function write($message) {
        $this->io->write( "$message" );
    }

    protected function info($message) {
        $this->io->write( "    $message" );
    }
    
    protected function success($message) {
        $this->io->write( " ✅  <info>$message</info>" );
    }
    
    protected function error($message) {
        $this->io->write( " 🆘 \033[31m $message\033[0m" );
    }
    
    protected function complete($message) {
        $this->io->write( " 🌟 🌟 🌟  <info>$message</info> 🌟 🌟 🌟" );
    }
    
    protected function execute($command, $log=true) {
        $origCommand = $command;
        
        if($log === true) { $command .= " 2> ezpublish/logs/ezmagic.log"; }
        
        // Run command
        exec($command, $output, $return);
        if($return) {
            $this->error('An error occured while executing a system commend:');
            $this->error("\$ $origCommand");
            if($log === true) {
                $this->info("check ezpublish/logs/ezmagic.log for details");
            }
            return false;
        }else{
            return implode("\n", $output);
        }
    }
        
}
