<?php
declare(strict_types=1);

namespace Elephox\Plane\Commands;

use Elephox\Collection\Enumerable;
use Elephox\Configuration\Contract\Environment;
use Elephox\Console\Command\CommandInvocation;
use Elephox\Console\Command\CommandTemplateBuilder;
use Elephox\Console\Command\Contract\CommandHandler;
use Psr\Log\LoggerInterface;

class InstallCommand implements CommandHandler
{
	public const STUBBED_SERVICES = ['mailhog', 'postgres', 'redis'];
	public const VOLUMED_SERVICES = ['postgres', 'redis'];
	public const DEFAULT_SERVICES = ['mailhog', 'postgres', 'redis'];
	public const STUBS_DIR = __DIR__ . '/../../stubs';
	public const AVAILABLE_RUNTIMES = ['8.1'];
	public const DEFAULT_RUNTIME = '8.1';

	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly Environment $environment,
	) {
	}

	public function configure(CommandTemplateBuilder $builder): void
	{
		$builder
			->setName('plane:install')
			->setDescription('Installs Plane\'s docker-compose file')
		;
		$builder->addOption('runtime', default: self::DEFAULT_RUNTIME, description: 'The runtime to use', validator: static fn ($value) => in_array($value, self::AVAILABLE_RUNTIMES, true));
		$builder->addOption('services', default: implode(',', self::DEFAULT_SERVICES), description: 'Services to install (\'none\' to skip)');
		$builder->addOption('overwrite', description: 'Overwrite existing docker-compose.yml file');
	}

	public function handle(CommandInvocation $command): int|null
	{
		$services = $command->options->services->value;
		if ($services) {
			$services = $services === 'none' ? [] : explode(',', $services);
		} else {
			$services = self::DEFAULT_SERVICES;
		}

		$this->logger->debug('Installing services: ' . implode(', ', $services));

		$runtime = $command->options->runtime->value;
		if (!in_array($runtime, self::AVAILABLE_RUNTIMES, true)) {
			$this->logger->error('Runtime ' . $runtime . ' is not available');

			return 1;
		}

		$dockerCompose = $this->buildDockerCompose($runtime, $services);

		$dockerComposeFile = $this->environment->getRoot()->getFile('docker-compose.yml');

		$overwrite = (bool) $command->options->overwrite->value;
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
	protected function buildDockerCompose(string $runtime, array $services): string
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
				'{{runtime}}',
				'{{depends}}',
				'{{services}}',
				'{{volumes}}',
			],
			[
				$runtime,
				$depends,
				$stubs,
				$volumes,
			],
			$dockerCompose,
		);

		return preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $dockerCompose);
	}
}
