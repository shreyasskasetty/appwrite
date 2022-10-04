<?php

global $cli, $register;

use Utopia\CLI\Console;
use Appwrite\Migration\Migration;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Text;

$cli
    ->task('migrate')
    ->param('version', APP_VERSION_STABLE, new Text(32), 'Version to migrate to.', true)
    ->action(function ($version) use ($register) {
        Authorization::disable();
        if (!array_key_exists($version, Migration::$versions)) {
            Console::error("Version {$version} not found.");
            Console::exit(1);
            return;
        }

        $app = new App('UTC');

        Console::success('Starting Data Migration to version ' . $version);

        $db = $register->get('db', true);
        $redis = $register->get('cache', true);
        $redis->flushAll();
        $cache = new Cache(new RedisCache($redis));

        $projectDB = new Database(new MySQL($db), $cache);
        $projectDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));

        $consoleDB = new Database(new MySQL($db), $cache);
        $consoleDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
        $consoleDB->setNamespace('_project_console');

        $console = $app->getResource('console');

        $limit = 30;
        $sum = 30;
        $offset = 0;
        /**
         * @var \Utopia\Database\Document[] $projects
         */
        $projects = [$console];
        $count = 0;

        try {
            $totalProjects = $consoleDB->count('projects') + 1;
        } catch (\Throwable $th) {
            $consoleDB->setNamespace('_console');
            $totalProjects = $consoleDB->count('projects') + 1;
        }

        $class = 'Appwrite\\Migration\\Version\\' . Migration::$versions[$version];
        $migration = new $class();

        while (!empty($projects)) {
            foreach ($projects as $project) {
                /**
                 * Skip user projects with id 'console'
                 */
                if ($project->getId() === 'console' && $project->getInternalId() !== 'console') {
                    continue;
                }

                try {
                    $migration
                        ->setProject($project, $projectDB, $consoleDB)
                        ->execute();
                } catch (\Throwable $th) {
                    throw $th;
                    Console::error('Failed to update project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
                }
            }

            $sum = \count($projects);
            $projects = $consoleDB->find('projects', [Query::limit($limit), Query::offset($offset)]);

            $offset = $offset + $limit;
            $count = $count + $sum;

            Console::log('Migrated ' . $count . '/' . $totalProjects . ' projects...');
        }

        Swoole\Event::wait(); // Wait for Coroutines to finish
        $redis->flushAll();
        Console::success('Data Migration Completed');
    });
