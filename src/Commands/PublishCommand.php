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
use Elephox\Logging\Contract\Logger;
use InvalidArgumentException;
use RuntimeException;

class PublishCommand implements CommandHandler
{
	public const AVAILABLE_PARTS = ['bin', 'docker'];
	public const DEFAULT_PARTS = ['docker'];

	public function __construct(
		private readonly Logger $logger,
		private readonly Environment $env,
	) {
	}

	public function configure(CommandTemplateBuilder $builder): void
	{
		$builder
			->name('plane:publish')
			->optional('parts', implode(',', self::DEFAULT_PARTS), 'The parts to publish (' . implode(', ', self::AVAILABLE_PARTS) . ')')
			->optional('dockerDest', $this->env->root->getDirectory('docker')->getPath(), 'The directory to publish the docker files to.')
			->optional('binDest', $this->env->root->getDirectory('bin')->getPath(), 'The directory to publish the Plane binary to.')
			->optional('overwrite', false, 'Overwrite existing files')
		;
	}

	public function handle(CommandInvocation $command): int|null
	{
		$parts = explode(',', $command->getArgument('parts')->value);
		$dockerDestination = new Directory($command->getArgument('dockerDest')->value);
		$binDestination = new Directory($command->getArgument('binDest')->value);
		$overwrite = filter_var($command->getArgument('overwrite'), FILTER_VALIDATE_BOOL);

		foreach ($parts as $part) {
			match ($part) {
				'bin' => $this->publishBin($binDestination, $overwrite),
				'docker' => $this->publishDocker($dockerDestination, $overwrite),
				default => throw new InvalidArgumentException("Unknown part: $part"),
			};
		}

		return 0;
	}

	private function publishBin(Directory $destination, bool $overwrite): void
	{
		$destination->ensureExists();
		$destinationBin = $destination->getFile('plane');
		$sourceBin = new File(dirname(__DIR__, 2) . '/bin/plane');

		try {
			$this->logger->info("Publishing Plane binary to $destinationBin");

			$sourceBin->copyTo($destination, $overwrite);

			$this->logger->warning("Remember to update any tools referencing the Plane binary to use your binary at $destinationBin");
		} catch (FileAlreadyExistsException $e) {
			throw new RuntimeException('Destination binary already exists. Use --overwrite to force publishing the Plane binary.', previous: $e);
		}
	}

	private function publishDocker(Directory $destination, bool $overwrite): void
	{
		$sourceDir = new Directory(dirname(__DIR__, 2) . '/runtimes/8.1');

		try {
			$this->logger->info("Publishing Plane docker files to $destination");

			$sourceDir->copyTo($destination, $overwrite);
		} catch (FileAlreadyExistsException $e) {
			throw new RuntimeException('Destination files are already present. Use --overwrite to force publishing the docker files.', previous: $e);
		}

		$composeFile = $this->env->root->getFile('docker-compose.yml');
		$composeFile->putContents(str_replace(['./vendor/elephox/plane/runtimes/8.1'], [$destination->getPath()], $composeFile->getContents()));
	}
}
