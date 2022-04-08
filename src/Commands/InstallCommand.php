<?php
declare(strict_types=1);

namespace Elephox\Plane\Commands;

use Elephox\Collection\Enumerable;
use Elephox\Configuration\Contract\Environment;
use Elephox\Console\Command\CommandInvocation;
use Elephox\Console\Command\CommandTemplateBuilder;
use Elephox\Console\Command\Contract\CommandHandler;
use Elephox\Logging\Contract\Logger;
use Elephox\Stream\StringStream;

class InstallCommand implements CommandHandler
{
	public const STUBBED_SERVICES = ['mailhog'];
	public const DEFAULT_SERVICES = ['mailhog'];
	public const STUBS_DIR = __DIR__ . '/../../stubs';

	public function __construct(
		private readonly Logger $logger,
		private readonly Environment $environment,
	)
	{
	}

	public function configure(CommandTemplateBuilder $builder): void
	{
		$builder
			->name('plane:install')
			->description('Install the Plane\'s docker-compose file')
			->argument('services', 'Services to install (\'none\' to skip)', implode(',', self::DEFAULT_SERVICES), false)
		;
	}

	public function handle(CommandInvocation $command): int|null
	{
		$services = $command->services;

		if ($services) {
			$services = $services === 'none' ? [] : explode(',', $services);
		} else {
			$services = self::DEFAULT_SERVICES;
		}

		$this->logger->debug('Installing services: ' . implode(', ', $services));

		$this->buildDockerCompose($services);

		$this->logger->info('Plane installed successfully.');

		return 0;
	}

	/**
	 * @param list<string> $services
	 */
	protected function buildDockerCompose(array $services): void
	{
		$depends = Enumerable::from($services)
			->where(fn(string $service) => in_array($service, self::STUBBED_SERVICES, true))
			->select(fn(string $service) => "            - $service")
			->aggregate(fn (string $acc, string $item) => $acc . PHP_EOL . $item, "");

		if (!empty($depends)) {
			$depends = 'depends_on:' . PHP_EOL . $depends;
		}

		$stubs = rtrim(
				Enumerable::from($services)
					->select(function (string $service) {
						return file_get_contents(self::STUBS_DIR . "/$service.stub");
					})
					->aggregate(fn (string $acc, string $item) => $acc . $item, "")
		);

		$dockerCompose = file_get_contents(self::STUBS_DIR . '/docker-compose.stub');
		$dockerCompose = str_replace(
			[
				'{{depends}}',
				'{{services}}'
			],
			[
				empty($depends) ? '' : '        ' . $depends,
				$stubs
			],
			$dockerCompose
		);

		// Remove empty lines...
		$dockerCompose = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $dockerCompose);

		$this->environment->getRootDirectory()
			->getFile('docker-compose.yml')
			->putContents(new StringStream($dockerCompose))
		;
	}
}
