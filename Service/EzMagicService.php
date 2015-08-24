<?php

/* 
 *  KNOWN BUGS:
 *      - ezpublish/logs directory does not exist initially
 *      - requirements: node, lessc
 *
 *  COMPOSER SETUP:
 *      "post-install-cmd": [
 *          "MagLoft\\EzmagicBundle\\Composer\\ScriptHandler::setupEzConfig",
 *          "MagLoft\\EzmagicBundle\\Composer\\ScriptHandler::setupDatabase",
 *          "MagLoft\\EzmagicBundle\\Composer\\ScriptHandler::checkDatabase",
 *          ...
 *      ]
 */

namespace MagLoft\EzmagicBundle\Service;

use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Finder\Finder;
use PDO;
use PDOException;

class EzMagicService {
    protected $io;
    protected $config;
    
    public function __construct($io) {
        $this->io = $io;

        // load ezconfig
        $this->loadEzConfig();

    }
    
    // MAGIC METHODS
    
    public function storageMagicExport() {
        
        $this->info('Exporting ezpublish storage via rsync');
        
        // Security test to avoid unconsious override of published storage
        $this->info('Warning: Exporting the storage will override your currently stored storage.', true);
        $slug = $this->getConfig('database.slug');
        $confirmation = $this->ask("Type '$slug' to publish the storage directory to fsmagic", false);
        if($confirmation !== $slug) {
            $this->info('Aborting export...', true);
            die();
        }
        
        // Upload storage directory
        $dbConfig = $this->getConfig('database');
        if(($this->execute("rsync -avz --progress -e 'ssh' ezpublish_legacy/var/storage/ {$dbConfig["ssh_host"]}:~/fsmagic/{$dbConfig["slug"]}/")) === false) {
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
        
        // Security test to avoid unconsious override of published database
        $this->info('Warning: Exporting the Database will override your currently stored database.', true);
        $slug = $this->getConfig('database.slug');
        $confirmation = $this->ask("Type '$slug' to publish the database to dbmagic", false);
        if($confirmation !== $slug) {
            $this->info('Aborting export...', true);
            die();
        }
            
        // Check if database exists
        $dbConfig = $this->getConfig('database');
        $db = $this->getDatabaseHandle();
        if($db instanceof PDOException) {
            $this->error("Could not export database '{$dbConfig['name']}':");
            $this->error($db->getMessage());
            $this->info('Aborting export...', true);
            die();
        }
        
        // Dump database to temporary file
        if(($mysqlDumpContents = $this->execute("mysqldump -h {$dbConfig["host"]} -u {$dbConfig["username"]} --password='{$dbConfig["password"]}' {$dbConfig["name"]}")) === false) {
            $this->info('Aborting dbmagic export...');
            die();
        }else{
            file_put_contents("/tmp/{$dbConfig["slug"]}.sql", $mysqlDumpContents);
        }
        
        // Zip database dump
        if($this->execute("gzip -f /tmp/{$dbConfig["slug"]}.sql") === false) {
            $this->info('Aborting dbmagic export...');
            die();
        }
        
        // Encrypt database dump
        $command = "echo {$dbConfig["secret"]} | gpg --yes --output /tmp/{$dbConfig["slug"]}.sql.gz.enc --batch --passphrase-fd 0 -c --cipher-algo TWOFISH /tmp/{$dbConfig["slug"]}.sql.gz";
        if($this->execute($command) === false) {
            $this->info('Aborting dbmagic export...');
            die();
        }
        
        // Upload server dump
        if(($this->execute("rsync -avz --progress -e 'ssh' /tmp/{$dbConfig["slug"]}.sql.gz.enc {$dbConfig["ssh_host"]}:~/dbmagic/{$dbConfig["slug"]}.sql.gz.enc")) === false) {
            $this->info('Aborting dbmagic export...');
            die();
        }
        
        // Clean up temporary files
        $this->execute("rm -f /tmp/{$dbConfig["slug"]}.sql");
        $this->execute("rm -f /tmp/{$dbConfig["slug"]}.sql.gz");
        $this->execute("rm -f /tmp/{$dbConfig["slug"]}.sql.gz.enc");
        
        $this->success('Database dump was successfully exported!');
        
        return true;
    }
    
    public function dbMagicImport() {
        
        $this->info('Importing dbmagic database via rsync');
        $dbConfig = $this->getConfig('database');
        
        // Download server dump
        if($this->execute("rsync -avz --progress -e 'ssh' {$dbConfig["ssh_host"]}:~/dbmagic/{$dbConfig["slug"]}.sql.gz.enc /tmp/{$dbConfig["slug"]}.sql.gz.enc") === false) {
            $this->info('Aborting dbmagic import...');
            die();
        }
        
        // Decrypt database dump
        $command = "echo {$dbConfig["secret"]} | gpg --yes --output /tmp/{$dbConfig["slug"]}.sql.gz --batch --passphrase-fd 0 -d /tmp/{$dbConfig["slug"]}.sql.gz.enc";
        if($this->execute($command) === false) {
            $this->info('Aborting dbmagic import...');
            die();
        }
        
        // Unzip database dump
        if($this->execute("gunzip -f /tmp/{$dbConfig["slug"]}.sql.gz") === false) {
            $this->info('Aborting dbmagic import...');
            die();
        }
        
        // Import server dump
        if(($this->execute("mysql -h {$dbConfig["host"]} -u {$dbConfig["username"]} --password='{$dbConfig["password"]}' {$dbConfig["name"]} < /tmp/{$dbConfig["slug"]}.sql")) === false) {
            $this->info('Aborting dbmagic import...');
            die();
        }
        
        // Clean up unencrypted dumps
        $this->execute("rm -f /tmp/{$dbConfig["slug"]}.sql");
        $this->execute("rm -f /tmp/{$dbConfig["slug"]}.sql.gz");
        
        $this->success('Database dump was successfully imported!');
        
        return true;
    }
    
    public function setupEzConfig() {
        
        // Create necessary folders
        if(!file_exists('ezpublish_legacy/var/storage')) {
            mkdir('ezpublish_legacy/var/storage');
            $this->success("Successfully created storage directory!");
        }
        if(!file_exists('ezpublish/logs')) {
            mkdir('ezpublish/logs');
            $this->success("Successfully created logs directory!");
        }
        
        // Check if ezconfig already exists
        if(file_exists("ezpublish/config/ezconfig.yml")) {
            $this->success("Found a valid ezconfig.yml file!");
            return true;
        }
                
        // Load template config
        if(file_exists("ezpublish/config/ezconfig.yml.dist")) {
            $templatePath = "ezpublish/config/ezconfig.yml.dist";
        }else{
            $templatePath = __DIR__ . "/../Resources/templates/ezconfig.yml";
        }
        $parser = new Parser();
        $templateYamlArray = $parser->parse(file_get_contents($templatePath));
        
        // Ask for site config
        foreach($templateYamlArray as $section => $config) {
            $this->info("configuring $section settings:");
            foreach($config as $key => $defaultValue) {
                $default = Inline::dump($defaultValue);
                $value = $this->ask($key, $default);
                $value = Inline::parse($value);
                $templateYamlArray[$section][$key] = $value;
            }
        }
        
        // Write ezconfig file
        $dumper = new Dumper();
        $yamlContents = $dumper->dump($templateYamlArray, 7);
        file_put_contents("ezpublish/config/ezconfig.yml", $yamlContents);
        
        // Show success message
        $this->success('ezpublish/config/ezconfig.yml successfully created!');
    }
    
    public function setupDatabase() {

        // Loop through database tasks until handle is valid
        while(($result = $this->getDatabaseHandle()) instanceof PDOException) {
            $this->error('A database error occured!');
            if($this->confirm('Run diagnosis?')) {
                $this->runDatabaseDiagnosis();
            }else{
                $this->info('Update your ezpublish/config/ezconfig.yml.');
                $this->retry();
                $this->loadEzConfig();
            }
        }
        
        $this->success('Database test successful!');

    }
    
    private function getDatabaseConfig(){
        
        $doctrineConfig = false;
        
        // compatibility for pre 2014.03 versions
        $doctrineConfig = @ $this->getConfig("doctrine.dbal.connections.default");
        
        
        if($doctrineConfig){
            $databaseConfig = array(
                'type' => 'mysql',
                'user' => $doctrineConfig["user"],
                'password' => $doctrineConfig["password"],
                'server' => $doctrineConfig["host"],
                'database_name' => $doctrineConfig["dbname"]
            );
        }else{
            $databaseConfig = array(
                'type' => 'mysql',
                'user' => $this->getConfig('database.username'),
                'password' => $this->getConfig('database.password'),
                'server' => $this->getConfig('database.host'),
                'database_name' => $this->getConfig('database.name')
            );
        }
        
        return $databaseConfig;
    }
    
    public function checkDatabase() {
        
        $config = $this->getDatabaseConfig();        
        $dsn = "mysql:host={$config['server']};dbname={$config["database_name"]}";
        
        try{
            $db = new PDO($dsn, $config['user'], $config['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
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
    
    // HELPERS
    
    protected function getConfig($key=false) {
        if(!$this->config) {
            return false;
        }
        if(!$key) {
            return $this->config;
        }else{
            $exploded = explode('.', $key);
            $config = $this->config;
            foreach($exploded as $key) {
                $config = $config[$key];
            }
            return $config;
        }
    }
    
    protected function setupConfigFile($file, $replacements, $override=false) {
        $targetPath = "ezpublish/config/$file";
        
        // cancel if file already exists
        if($override === false && file_exists($targetPath)) {
            return true;
        }
        
        // read files
        if(file_exists("$targetPath.dist")) {
            $sourcePath = "$targetPath.dist";
        }else{
            $sourcePath = __DIR__ . "/../Resources/templates/$file.dist";
        }
        $parser = new Parser();
        $config = $parser->parse(file_get_contents($sourcePath));
        
        // loop through replacements
        foreach($replacements as $path => $value) {
            $exploded = explode('.', $path);
            $temp = &$config;
            foreach($exploded as $key) {
                $temp = &$temp[$key];
            }
            $temp = $value;
            unset($temp);
        }
        
        // write files
        $dumper = new Dumper();
        file_put_contents($targetPath, $dumper->dump($config, 7));
    }
    
    protected function getDatabaseHandle() {
        
        $config = $this->getDatabaseConfig();
        $dsn = "mysql:host={$config['server']};dbname={$config["database_name"]}";
        
        try{
            $db = new PDO($dsn, $config['user'], $config['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            return $db;
        }catch(PDOException $ex){
            return $ex;
        }
    }
    
    protected function loadEzConfig() {
        if(file_exists("ezpublish/config/ezconfig.yml")) {
            $parser = new Parser();
            $this->config = $parser->parse(file_get_contents("ezpublish/config/ezconfig.yml"));
            return true;
        }else{
            $this->config = null;
            return false;
        }
    }
    
    protected function createDatabase() {
        
        // fetch root credentials
        $rootUsername = $this->ask('MySQL root user:', $this->getConfig('database.username'));
        $rootPassword = $this->ask('MySQL root password:', $this->getConfig('database.password'));
        $username = $this->getConfig('database.username');
        $password = $this->getConfig('database.password');
        $database = $this->getConfig('database.name');
        $host = $this->getConfig('database.host');
        $port = $this->getConfig('database.port');
        
        // connect database
        $dsn = "mysql:host=$host;port=$port;";
        $db = new PDO($dsn, $rootUsername, $rootPassword, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        
        // execute statements
        $createSQL = "CREATE DATABASE `$database` DEFAULT CHARACTER SET `utf8`;";
        $db->exec($createSQL);
        $grantSQL = "GRANT CREATE ROUTINE, CREATE VIEW, ALTER, SHOW VIEW, CREATE, ALTER ROUTINE, EVENT, INSERT, SELECT, DELETE, TRIGGER, REFERENCES, UPDATE, DROP, EXECUTE, LOCK TABLES, CREATE TEMPORARY TABLES, INDEX ON `$database`.* TO '$username'@'$host'; FLUSH PRIVILEGES;";
        $db->exec($grantSQL);        
    }
    
    protected function runDatabaseDiagnosis($rootUsername=false, $rootPassword=false) {
        
        // fetch root credentials
        $rootUsername = $rootUsername ? $rootUsername : $this->ask('MySQL root user:', $this->getConfig('database.username'));
        $rootPassword = $rootPassword ? $rootPassword : $this->ask('MySQL root password:', $this->getConfig('database.password'));
        
        // step one: check root login
        $dsn = $this->buildDsn(array( 'host' => $this->getConfig('database.host'), 'port' => $this->getConfig('database.port') ));
        try{
            $db = new PDO($dsn, $rootUsername, $rootPassword, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        }catch(PDOException $ex) {
            $this->info('The system was not able to log in to your mysql server. Are you providing root user credentials?');
            $this->error($ex->getMessage());
            $this->retry();
            return $this->runDatabaseDiagnosis();
        }
        
        // step two: check privileges
        if(!$this->dbUserHasRootPrivileges($db)) {
            $this->error("The user '$rootUsername' does not have root privileges!");
            $this->retry();
            return $this->runDatabaseDiagnosis();
        }else{
            $this->success('Root user has sufficient privileges!');
        }
        
        // step three: check is user exists
        $username = $this->getConfig('database.username');
        if(!$this->dbUserExists($db, $username)) {
            $this->error("The user '$username' does not exist!");
            if($this->confirm('Create user?')) {
                $this->dbCreateUser($db, $username, $this->getConfig('database.password'));
                return $this->runDatabaseDiagnosis($rootUsername, $rootPassword);
            }else{
                $this->retry();
                return $this->runDatabaseDiagnosis($rootUsername, $rootPassword);
            }
        }else{
            $this->success("User '$username' exists!");
        }
        
        // step four: check if database exists
        $databaseName = $this->getConfig('database.name');
        if(!$this->dbDatabaseExists($db, $databaseName)) {
            $this->error("The database '$databaseName' does not exist!");
            if($this->confirm('Create database?')) {
                $this->dbCreateDatabase($db, $databaseName);
                return $this->runDatabaseDiagnosis($rootUsername, $rootPassword);
            }else{
                $this->retry();
                return $this->runDatabaseDiagnosis($rootUsername, $rootPassword);
            }
        }else{
            $this->success("Database '$databaseName' exists!");
        }
        
        // step five: check schema privileges
        $clientDsn = $this->buildDsn(array( 'host' => $this->getConfig('database.host'), 'port' => $this->getConfig('database.port'), 'dbname' => $this->getConfig('database.name') ));
        try {
            $clientDb = new PDO($clientDsn, $this->getConfig('database.username'), $this->getConfig('database.password'));
            $this->success("User '$username' has privileges to access database '$databaseName'!");
        }catch(PDOException $ex){
            // check for access denied error
            if($ex->getCode() == 1044) {
                $this->error("The user '$username' does not have privileges to access the database '$databaseName'!");
                if($this->confirm('Create privileges?')) {
                    $this->dbCreatePrivileges($db, $username, $databaseName);
                    return $this->runDatabaseDiagnosis($rootUsername, $rootPassword);
                }else{
                    $this->retry();
                    return $this->runDatabaseDiagnosis($rootUsername, $rootPassword);
                }
            }else{
                $this->error('An unexpected error occured:');
                $this->error($ex->getMessage());
                $this->retry();
                return $this->runDatabaseDiagnosis($rootUsername, $rootPassword);
            }
        }
        
        return true;
    }
    
    protected function dbCreatePrivileges($db, $username, $databaseName) {
        $host = $this->getConfig('database.host');
        $db->exec("GRANT CREATE ROUTINE, CREATE VIEW, ALTER, SHOW VIEW, CREATE, ALTER ROUTINE, EVENT, INSERT, SELECT, DELETE, TRIGGER, REFERENCES, UPDATE, DROP, EXECUTE, LOCK TABLES, CREATE TEMPORARY TABLES, INDEX ON `$databaseName`.* TO '$username'@'$host'; FLUSH PRIVILEGES;");
    }

    protected function dbCreateDatabase($db, $databaseName) {
        $db->exec("CREATE DATABASE `$databaseName` DEFAULT CHARACTER SET `utf8`;");
    }
    
    protected function dbDatabaseExists($db, $databaseName) {
        $host = $this->getConfig('database.host');
        $statement = $db->prepare("SELECT COUNT(*) AS count FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$databaseName';");
        $statement->execute();
        $count = (int) $statement->fetch(PDO::FETCH_COLUMN, 0);
        return $count > 0;
    }
    
    protected function dbCreateUser($db, $username, $password) {
        $host = $this->getConfig('database.host');
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
    
    protected function dbUserExists($db, $username) {
        $host = $this->getConfig('database.host');
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
