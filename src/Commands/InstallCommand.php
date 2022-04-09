<?php
declare(strict_types=1);

namespace Elephox\Plane\Commands;

use Elephox\Collection\Enumerable;
use Elephox\Configuration\Contract\Environment;
use Elephox\Console\Command\CommandInvocation;
use Elephox\Console\Command\CommandTemplateBuilder;
use Elephox\Console\Command\Contract\CommandHandler;
use Elephox\Logging\Contract\Logger;

class InstallCommand implements CommandHandler
{
	public const STUBBED_SERVICES = ['mailhog', 'postgres', 'redis'];
	public const VOLUMED_SERVICES = ['postgres', 'redis'];
	public const DEFAULT_SERVICES = ['mailhog', 'postgres', 'redis'];
	public const STUBS_DIR = __DIR__ . '/../../stubs';

	public function __construct(
		private readonly Logger $logger,
		private readonly Environment $environment,
	) {
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

		$dockerCompose = $this->buildDockerCompose($services);

		$dockerComposeFile = $this->environment->getRootDirectory()->getFile('docker-compose.yml');
		if ($dockerComposeFile->exists()) {
			$this->logger->error('Docker-compose file already exists. Please remove it before installing.');

			return 1;
		}

		$dockerComposeFile->putContents($dockerCompose);

		$this->logger->info('Plane installed successfully.');

		return 0;
	}

	/**
	 * @param list<string> $services
	 */
	protected function buildDockerCompose(array $services): string
	{
		$depends = Enumerable::from($services)
			->where(static fn (string $service) => in_array($service, self::STUBBED_SERVICES, true))
			->select(static fn (string $service) => "            - $service")
			->aggregate(static fn (string $acc, string $item) => $acc . "\n" . $item, '')
		;

		if (!empty($depends)) {
			$depends = "depends_on:\n" . $depends;
		}

		$stubs = rtrim(
			Enumerable::from($services)
					->select(static fn (string $service) => file_get_contents(self::STUBS_DIR . "/$service.stub"))
					->aggregate(static fn (string $acc, string $item) => $acc . $item, ''),
		);

		$volumes = Enumerable::from($services)
			->where(static fn (string $service) => in_array($service, self::VOLUMED_SERVICES, true))
			->select(static fn (string $service) => "    plane-$service:\n        driver: local")
			->aggregate(static fn (string $acc, string $item) => $acc . "\n" . $item, '')
		;

		if (!empty($volumes)) {
			$volumes = "volumes:\n" . $volumes;
		}

		$dockerCompose = file_get_contents(self::STUBS_DIR . '/docker-compose.stub');
		$dockerCompose = str_replace(
			[
				'{{depends}}',
				'{{services}}',
				'{{volumes}}',
			],
			[
				empty($depends) ? '' : '        ' . $depends,
				$stubs,
				$volumes,
			],
			$dockerCompose,
		);

		return preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $dockerCompose);
	}
}
