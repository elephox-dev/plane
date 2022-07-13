<?php
declare(strict_types=1);

namespace Elephox\Plane\Commands;

use Elephox\Configuration\Contract\Environment;
use Elephox\Console\Command\CommandInvocation;
use Elephox\Console\Command\CommandTemplateBuilder;
use Elephox\Console\Command\Contract\CommandHandler;
use Elephox\Files\Directory;
use Elephox\Files\File;
use Elephox\Files\FileAlreadyExistsException;
use Elephox\Files\Path;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class PublishCommand implements CommandHandler
{
	public const AVAILABLE_PARTS = ['bin', 'docker'];
	public const DEFAULT_PARTS = ['docker'];

	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly Environment $env,
	) {
	}

	public function configure(CommandTemplateBuilder $builder): void
	{
		$builder->setName('plane:publish');
		$builder->addOption(
			'parts',
			default: implode(
				',',
				self::DEFAULT_PARTS,
			),
			description: 'The parts to publish (' .
			implode(
				', ',
				self::AVAILABLE_PARTS,
			) .
			')',
		);
		$builder->addOption(
			'dockerDest',
			default: 'docker',
			description: 'The directory to publish the docker files to.',
		);
		$builder->addOption(
			'binDest',
			default: 'bin',
			description: 'The directory to publish the Plane binary to.',
		);
		$builder->addOption(
			'overwrite',
			description: 'Overwrite existing files',
		);
	}

	public function handle(CommandInvocation $command): int|null
	{
		$parts =
			explode(
				',',
				$command->arguments->parts->value,
			);
		if (Path::isRooted($command->arguments->dockerDest->value)) {
			$dockerDestination = new Directory($command->arguments->dockerDest->value);
		} else {
			$dockerDestination =
				$this->env->root->getDirectory($command->arguments->dockerDest->value);
		}

		if (Path::isRooted($command->arguments->binDest->value)) {
			$binDestination = new Directory($command->arguments->binDest->value);
		} else {
			$binDestination =
				$this->env->root->getDirectory($command->arguments->binDest->value);
		}

		$overwrite =
			filter_var(
				$command->arguments->overwrite->value,
				FILTER_VALIDATE_BOOL,
			);

		foreach ($parts as $part) {
			match ($part) {
				'bin' => $this->publishBin(
					$binDestination,
					$overwrite,
				),
				'docker' => $this->publishDocker(
					$dockerDestination,
					$overwrite,
				),
				default => throw new InvalidArgumentException("Unknown part: $part"),
			};
		}

		return 0;
	}

	private function publishBin(Directory $destination, bool $overwrite): void
	{
		$destination->ensureExists();
		$destinationBin = $destination->getFile('plane');
		$sourceBin =
			new File(
				dirname(
					__DIR__,
					2,
				) . '/bin/plane',
			);

		try {
			$this->logger->info("Publishing Plane binary to $destinationBin");

			$sourceBin->copyTo(
				$destination,
				$overwrite,
			);

			$this->logger->warning(
				"Remember to update any tools referencing the Plane binary to use your binary at $destinationBin",
			);
		} catch (FileAlreadyExistsException $e) {
			throw new RuntimeException(
				'Destination binary already exists. Use --overwrite to force publishing the Plane binary.',
				previous: $e,
			);
		}
	}

	private function publishDocker(Directory $destination, bool $overwrite): void
	{
		$sourceDir =
			new Directory(
				dirname(
					__DIR__,
					2,
				) . '/runtimes/8.1',
			);

		try {
			$this->logger->info("Publishing Plane docker files to $destination");

			$sourceDir->copyTo(
				$destination,
				$overwrite,
			);
		} catch (FileAlreadyExistsException $e) {
			throw new RuntimeException(
				'Destination files are already present. Use --overwrite to force publishing the docker files.',
				previous: $e,
			);
		}

		try {
			$composeFile = $this->env->root->getFile('docker-compose.yml');
			$composeFile->putContents(
				preg_replace(
					'/^\.\/vendor\/elephox\/plane\/runtimes\/.+$/',
					$destination->getPathRelative($this->env->root),
					$composeFile->getContents(),
				),
			);
		} catch (Throwable) {
			$this->logger->error('Failed to update docker-compose.yml');
			$this->logger->error(
				"You will need to update the docker-compose.yml file manually to point to the new Plane docker files at $destination",
			);
		}
	}
}
