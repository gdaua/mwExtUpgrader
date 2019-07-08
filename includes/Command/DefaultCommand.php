<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace RazeSoldier\MWExtUpgrader\Command;

use RazeSoldier\MWExtUpgrader\{
	MediaWikiInstance,
	MWBranch,
	Services,
	UpgradeTarget,
	UpgradeTask
};
use Symfony\Component\Console\{
	Command\Command,
	Input\InputArgument,
	Input\InputDefinition,
	Input\InputOption,
	Input\InputInterface,
	Output\OutputInterface,
	Helper\QuestionHelper,
	Question\ChoiceQuestion,
	Question\ConfirmationQuestion,
	Question\Question
};

class DefaultCommand extends Command {
	protected static $defaultName = 'exec:update';
	// protected $mode;
	private  $mode;

	protected function execute_mode($mode){

		$this->_exec_mode = $mode;
	}

	protected function configure()
	{
		$this
			->setDefinition(
				new InputDefinition([
					new InputOption('batch', 'b', InputOption::VALUE_NONE, "Force 'batch' execution mode"),
                    new InputOption('interactive', 'i', InputOption::VALUE_NONE, "Force 'interactive' execution mode"),
                    new InputOption('mw-path', 'p', InputOption::VALUE_OPTIONAL, "Absolute path to the MediaWiki directory, like: mediawiki/w/ "),
                    new InputOption('mw-version', '', InputOption::VALUE_OPTIONAL, "Set correct MediaWiki version number"),
                ])
            );
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		
		$exec_batch = $input->getOption('batch');
		$exec_interactive = $input->getOption('interactive');
		$exec_interactive_n = $input->getOption('no-interaction');
		
		if( $exec_batch | $exec_interactive_n ){
			$this->mode = "batch_execute";
		} else {
			$this->mode = "interactive_execute";
		}
		
		#$res = $this->interactive_execute($input,$output);
		$mode = $this->mode;
		
		return ($this->$mode($input,$output));
	}

	protected function batch_execute(InputInterface $input, OutputInterface $output) {
		$exec_batch = $input->getOption('batch');
		$exec_interactive = $input->getOption('interactive');

		$path = $input->getOption('mw-path');

		if (!$path){
			return 1;
		}

		MediaWikiInstance::checkPath($path);
		$abs_path = realpath($path);
		$mw = new MediaWikiInstance($abs_path);
		$version = $input->getOption('mw-version');

		if ( $version === null | $version === FALSE ) {
			$formatVersion = $mw->getVersion()->getFormatVersion();
			foreach ($formatVersion as $key => $value) {
				if ( $key == "small" ){
					if ( strval($value) === strval(0) ){
						$targetVersion = $mw->getVersion()->getMainPart();
						break;
					} else {
						$targetVersion = $mw->getVersion()->getRawVersion();
						break;
					}
				}
			}

		} else {
			$targetVersion = $version;
		}

		// var_dump($version);
		/** @var UpgradeTarget[] $targets */

		$targets = $this->getUpgradeTarget($mw->getExtDir(), $mw->getSkinDir());
		if ($targets === []) {
			$output->writeln('Nothing needs to be upgraded');
			return 0;
		}

		// $targetVersion = $this->askTargetVersion($asker, $input, $output, $mw->getVersion()->getMainPart());

		$this->handleTarget($targets, $targetVersion, $output);
		if ($targets === []) {
			$output->writeln('Nothing needs to be upgraded');
			return 0;
		}

		$this->doUpgrade($targets, $output);
		return 0;
	}

	protected function interactive_execute(InputInterface $input, OutputInterface $output) {
		/** @var QuestionHelper $asker */
		$asker = $this->getHelper('question');

		$output->writeln('<comment>Note that this release of code is not stable. Do not use for production.</comment>');
		$output->writeln('Welcome to use mwExtUpgrader. This script can help you bulk upgrade MediaWiki extensions.');
		$resp = $this->askContinue($asker, $input, $output);
		if (!$resp) {
			return 0;
		}

		$path = $this->askMWPath($asker, $input, $output);
		$mw = new MediaWikiInstance($path);
		$mw->getVersion();
		$output->writeln("<comment>mwExtUpgrader detected your MediaWiki version is {$mw->getVersion()}</comment>");

		/** @var UpgradeTarget[] $targets */
		$targets = $this->getUpgradeTarget($mw->getExtDir(), $mw->getSkinDir());
		if ($targets === []) {
			$output->writeln('Nothing needs to be upgraded');
			return 0;
		}

		$targetVersion = $this->askTargetVersion($asker, $input, $output, $mw->getVersion()->getMainPart());

		$this->handleTarget($targets, $targetVersion, $output);
		if ($targets === []) {
			$output->writeln('Nothing needs to be upgraded');
			return 0;
		}

		$this->doUpgrade($targets, $output);
		return 0;
	}

	/**
	 * Ask user if they want to continue
	 * @param QuestionHelper $asker
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return bool
	 * @throws \Symfony\Component\Console\Exception\RuntimeException
	 */
	private function askContinue(QuestionHelper $asker, InputInterface $input, OutputInterface $output) : bool {
		$question = new ConfirmationQuestion('Continue with this action? (y/n) ', false);
		return $asker->ask($input, $output, $question);
	}

	/**
	 * Ask user where the MW is
	 * @param QuestionHelper $asker
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return string The MediaWiki directory path
	 * @throws \Symfony\Component\Console\Exception\RuntimeException
	 */
	private function askMWPath(QuestionHelper $asker, InputInterface $input, OutputInterface $output) : string {
		$question = new Question('Please type the absolute path to the MediaWiki directory: ');
		$question->setValidator(function (string $answer) {
			MediaWikiInstance::checkPath($answer);
			return realpath($answer);
		});
		return $asker->ask($input, $output, $question);
	}

	/**
	 * Ask user which version to upgrade to
	 * @param QuestionHelper $asker
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param string $mwVersion
	 * @return string
	 * @throws \Symfony\Component\Console\Exception\RuntimeException
	 */
	private function askTargetVersion(QuestionHelper $asker, InputInterface $input, OutputInterface $output, string $mwVersion) : string {
		$question = new ChoiceQuestion("Confirm version to be upgraded (default: $mwVersion)",
			Services::getInstance()->getExtensionRepo()->getSupportVersionRange(), $mwVersion);
		return $asker->ask($input, $output, $question);
	}

	private function getUpgradeTarget(string $extDir, string $skinDir) : array {
		/** @var UpgradeTarget[] $targets */
		$targets = [];

		$dir = new \DirectoryIterator($extDir);
		foreach ($dir as $iterator) {
			if ($iterator->isDot() || $iterator->isFile()) {
				continue;
			}
			$targets[] = UpgradeTarget::newExtTarget($iterator->getRealPath());
		}

		$dir = new \DirectoryIterator($skinDir);
		foreach ($dir as $iterator) {
			if ($iterator->isDot() || $iterator->isFile()) {
				continue;
			}
			$targets[] = UpgradeTarget::newSkinTarget($iterator->getRealPath());
		}

		return $targets;
	}

	/**
	 * @param UpgradeTarget[] $targets
	 * @param string $targetVersion
	 * @param OutputInterface $output
	 * @throws \UnexpectedValueException
	 */
	private function handleTarget(array &$targets, string $targetVersion, OutputInterface $output) {
		$branch = MWBranch::parseVersion($targetVersion)->getBranchText();

		$extRepo = Services::getInstance()->getExtensionRepo();
		$links = $extRepo->getDownloadLink($targets);
		if ($links === []) {
			$targets = [];
			return;
		}

		$tmpTargets = $targets;
		$targets = [];
		foreach ($tmpTargets as $target) {
			$name = $target->getName();
			if (isset($links[$name])) {
				if (isset($links[$name][$branch])) {
					$target->setSrc($links[$name][$branch]);
					$targets[] = $target;
				} else {
					$output->writeln("<error>$name unsupported $branch</error>");
				}
			} else {
				$output->writeln("<error>ExtensionDistributor unsupported $name</error>");
			}
		}
	}

	/**
	 * @param UpgradeTarget[] $targets
	 * @param OutputInterface $output
	 */
	private function doUpgrade(array $targets, OutputInterface $output) {
		/** @var UpgradeTask[] $tasks */
		$tasks = [];
		foreach ($targets as $target) {
			$tasks[$target->getName()] = new UpgradeTask($target->getName(), $target->getSrc(), $target->getDst());
		}

		foreach ($tasks as $name => $task) {
			$task->run($output);
			$output->writeln("<info>$name upgrade successed</info>");
		}
	}
}
