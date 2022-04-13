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
			->description('Installs Plane\'s docker-compose file')
			->optional('services', implode(',', self::DEFAULT_SERVICES), 'Services to install (\'none\' to skip)')
			->optional('overwrite', false, 'Overwrite existing docker-compose.yml file')
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

		$dockerComposeFile = $this->environment->getRoot()->getFile('docker-compose.yml');

		$overwrite = (bool) $command->overwrite;
		if (!$overwrite && $dockerComposeFile->exists()) {
			$this->logger->error('docker-compose.yml already exists. Please remove it before installing or pass --overwrite to the install command.');

			return 1;
		}

		$dockerComposeFile->putContents($dockerCompose);

		$this->logger->info('Plane installed successfully.');
		$this->logger->warning('If you need to connect to your database, remember to update <grayBack>DB_HOST</grayBack> to your database\'s service name (e.g. <grayBack>\'postgres\'</grayBack>).');

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
			$depends = "        depends_on:\n" . $depends;
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
				$depends,
				$stubs,
				$volumes,
			],
			$dockerCompose,
		);

		return preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $dockerCompose);
	}
}