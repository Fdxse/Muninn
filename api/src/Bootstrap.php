<?php

declare(strict_types=1);

namespace Muninn\Api;

use Muninn\Api\Config\Config;
use Muninn\Api\Database\ConnectionFactory;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\Response;
use Muninn\Api\Http\SecurityHeaders;
use Muninn\Api\Logging\AppLogger;
use PDO;
use Throwable;

/**
 * Builds the Application from a config file and handles one request.
 *
 * A fresh install without config/config.php answers 503 "not_set_up", so a first deploy shows
 * plainly that setup is unfinished. Other startup failures (invalid config, database
 * unreachable) are reported to PHP's own error log and answered with a generic 500 JSON body.
 * Neither response ever leaks paths or credentials to clients.
 */
final class Bootstrap
{
    /** Production entry point used by public/index.php. */
    public static function run(string $configFilePath): void
    {
        // Never print PHP warnings into responses; they go to the server's error log.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        self::handle($configFilePath, Request::fromGlobals())->send();
    }

    /** Builds the application and handles one request. Separate from run() so tests can call it. */
    public static function handle(string $configFilePath, Request $request): Response
    {
        // No config file at all means the server has not been set up yet. That is an expected
        // state on a first deploy, not a crash, so say so instead of a bare 500.
        if (!is_file($configFilePath)) {
            error_log('Muninn API is not set up: create config/config.php from config/config.example.php.');
            $notSetUpResponse = Response::error(503, 'not_set_up', 'The service has not been set up yet.');

            return SecurityHeaders::apply($notSetUpResponse);
        }

        try {
            $config = Config::fromFile($configFilePath);
            $database = self::connect($config);
            $logger = new AppLogger($config->getString('logging.file_path'));
        } catch (Throwable $startupFailure) {
            error_log('Muninn API startup failed: ' . $startupFailure::class . ': ' . $startupFailure->getMessage());
            $startupResponse = Response::error(500, 'internal_error', 'The service is temporarily unavailable.');

            return SecurityHeaders::apply($startupResponse);
        }

        return (new Application($config, $database, $logger))->handle($request);
    }

    /** Opens the runtime database connection using the 'database' config section. */
    public static function connect(Config $config): PDO
    {
        return ConnectionFactory::create(
            $config->getString('database.host'),
            $config->getInt('database.port'),
            $config->getString('database.name'),
            $config->getString('database.username'),
            $config->getString('database.password'),
        );
    }
}
