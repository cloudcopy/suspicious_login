<?php

declare(strict_types=1);

/**
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\SuspiciousLogin\Command;

use OCA\SuspiciousLogin\Exception\InsufficientDataException;
use OCA\SuspiciousLogin\Exception\ServiceException;
use OCA\SuspiciousLogin\Service\IClassificationStrategy;
use OCA\SuspiciousLogin\Service\Ipv4Strategy;
use OCA\SuspiciousLogin\Service\IpV6Strategy;
use OCA\SuspiciousLogin\Service\MLP\Config;
use OCA\SuspiciousLogin\Service\MLP\Trainer;
use OCA\SuspiciousLogin\Service\TrainingDataConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function time;

class Train extends Command {

	use ModelStatistics;

	/** @var Trainer */
	private $trainer;

	public function __construct(Trainer $optimizer) {
		parent::__construct("suspiciouslogin:train");
		$this->trainer = $optimizer;

		$this->addOption(
			'epochs',
			'e',
			InputOption::VALUE_OPTIONAL,
			"number of epochs to train"
		);
		$this->addOption(
			'layers',
			'l',
			InputOption::VALUE_OPTIONAL,
			"number of hidden layers"
		);
		$this->addOption(
			'shuffled',
			null,
			InputOption::VALUE_OPTIONAL,
			"ratio of shuffled negative samples"
		);
		$this->addOption(
			'random',
			null,
			InputOption::VALUE_OPTIONAL,
			"ratio of random negative samples"
		);
		$this->addOption(
			'learn-rate',
			null,
			InputOption::VALUE_OPTIONAL,
			"learning rate"
		);
		$this->addOption(
			'validation-threshold',
			null,
			InputOption::VALUE_OPTIONAL,
			"determines how much of the most recent data is used for validation. the default is one week"
		);
		$this->addOption(
			'max-age',
			null,
			InputOption::VALUE_OPTIONAL,
			"determines the maximum age of test data"
		);
		$this->addOption(
			'now',
			null,
			InputOption::VALUE_OPTIONAL,
			"overwrite the current time",
			time()
		);
		$this->addOption(
			'v6',
			null,
			InputOption::VALUE_NONE,
			"train with IPv6 data"
		);
		$this->registerStatsOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$strategy = $input->getOption('v6') ? new IpV6Strategy() : new Ipv4Strategy();
		$config = $strategy->getDefaultMlpConfig();
		if ($input->getOption('epochs') !== null) {
			$config = $config->setEpochs((int)$input->getOption('epochs'));
		}
		if ($input->getOption('layers') !== null) {
			$config = $config->setLayers((int)$input->getOption('layers'));
		}
		if ($input->getOption('shuffled') !== null) {
			$config = $config->setShuffledNegativeRate((float)$input->getOption('shuffled'));
		}
		if ($input->getOption('random') !== null) {
			$config = $config->setRandomNegativeRate((float)$input->getOption('random'));
		}
		if ($input->getOption('learn-rate') !== null) {
			$config = $config->setLearningRate((float)$input->getOption('learn-rate'));
		}

		$trainingDataConfig = TrainingDataConfig::default();
		if ($input->getOption('validation-threshold') !== null) {
			$trainingDataConfig = $trainingDataConfig->setThreshold((int)$input->getOption('validation-threshold'));
		}
		if ($input->getOption('max-age') !== null) {
			$trainingDataConfig = $trainingDataConfig->setMaxAge((int)$input->getOption('max-age'));
		}
		if ($input->getOption('now') !== null) {
			$trainingDataConfig = $trainingDataConfig->setNow((int)$input->getOption('now'));
		}

		try {
			$output->writeln('Using ' . $strategy::getTypeName() . ' strategy');

			$model = $this->trainer->train(
				$config,
				$trainingDataConfig,
				$strategy
			);
			$this->printModelStatistics($model, $input, $output);
		} catch (InsufficientDataException $ex) {
			$output->writeln("<info>Not enough data, try again later (<error>" . $ex->getMessage() . "</error>)</info>");
			return 1;
		} catch (ServiceException $ex) {
			$output->writeln("<error>Could not train a model: " . $ex->getMessage() . "</error>");
			return 1;
		}
		return 0;
	}

}
