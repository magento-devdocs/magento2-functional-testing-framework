<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types = 1);

namespace Magento\FunctionalTestingFramework\Console;

use Magento\FunctionalTestingFramework\Suite\Handlers\SuiteObjectHandler;
use Magento\FunctionalTestingFramework\Config\MftfApplicationConfig;
use Magento\FunctionalTestingFramework\Test\Handlers\TestObjectHandler;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Magento\FunctionalTestingFramework\Exceptions\TestFrameworkException;

class RunTestGroupCommand extends BaseGenerateCommand
{
    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('run:group')
            ->setDescription('Execute a set of tests referenced via group annotations')
            ->addOption(
                'skip-generate',
                'k',
                InputOption::VALUE_NONE,
                "only execute a group of tests without generating from source xml"
            )->addArgument(
                'groups',
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'group names to be executed via codeception'
            );

        parent::configure();
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return integer
     * @throws \Exception
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $skipGeneration = $input->getOption('skip-generate');
        $force = $input->getOption('force');
        $groups = $input->getArgument('groups');
        $remove = $input->getOption('remove');
        $debug = $input->getOption('debug') ?? MftfApplicationConfig::LEVEL_DEVELOPER; // for backward compatibility
        $allowSkipped = $input->getOption('allow-skipped');
        $verbose = $output->isVerbose();

        if ($skipGeneration and $remove) {
            // "skip-generate" and "remove" options cannot be used at the same time
            throw new TestFrameworkException(
                "\"skip-generate\" and \"remove\" options can not be used at the same time."
            );
        }

        // Create Mftf Configuration
        MftfApplicationConfig::create(
            $force,
            MftfApplicationConfig::EXECUTION_PHASE,
            $verbose,
            $debug,
            $allowSkipped
        );

        if (!$skipGeneration) {
            $testConfiguration = $this->getGroupAndSuiteConfiguration($groups);
            $command = $this->getApplication()->find('generate:tests');
            $args = [
                '--tests' => $testConfiguration,
                '--force' => $force,
                '--remove' => $remove,
                '--debug' => $debug,
                '--allow-skipped' => $allowSkipped,
                '-v' => $verbose
            ];

            $command->run(new ArrayInput($args), $output);
        }

        $codeceptionCommand = realpath(PROJECT_ROOT . '/vendor/bin/codecept') . ' run functional --verbose --steps';

        foreach ($groups as $group) {
            $codeceptionCommand .= " -g {$group}";
        }

        $process = new Process($codeceptionCommand);
        $process->setWorkingDirectory(TESTS_BP);
        $process->setIdleTimeout(600);
        $process->setTimeout(0);

        return $process->run(
            function ($type, $buffer) use ($output) {
                $output->write($buffer);
            }
        );
    }

    /**
     * Returns a json string to be used as an argument for generation of a group or suite
     *
     * @param array $groups
     * @return string
     * @throws \Magento\FunctionalTestingFramework\Exceptions\XmlException
     */
    private function OLDgetGroupAndSuiteConfiguration(array $groups)
    {
        $testConfiguration['tests'] = [];
        $testConfiguration['suites'] = null;
        $availableSuites = SuiteObjectHandler::getInstance()->getAllObjects();

        foreach ($groups as $group) {
            if (array_key_exists($group, $availableSuites)) {
                $testConfiguration['suites'][$group] = [];
            }

            $testConfiguration['tests'] = array_merge(
                $testConfiguration['tests'],
                array_keys(TestObjectHandler::getInstance()->getTestsByGroup($group))
            );
        }

        $testConfigurationJson = json_encode($testConfiguration);
        return $testConfigurationJson;
    }

    /** first attempt at an implementation, needs tested */
    private function first_attempt_getGroupAndSuiteConfiguration(array $groups)
    {
        $testConfiguration['tests'] = [];
        $testConfiguration['suites'] = null;
        $availableSuites = SuiteObjectHandler::getInstance()->getAllObjects();

        // iterate through all group names passed into the command
        foreach ($groups as $group) {
            if (array_key_exists($group, $availableSuites)) {
                // group is actually a suite, so add it to the suites array
                $testConfiguration['suites'][$group] = [];
            } else {
                // group is a group, so find and add all tests from that group to the tests array
                $testConfiguration['tests'] = array_merge(
                    $testConfiguration['tests'],
                    array_keys(TestObjectHandler::getInstance()->getTestsByGroup($group))
                );
            }
        }

        // find all tests that are in suites and build pairs
        $testsInSuites = SuiteObjectHandler::getInstance()->getAllTestReferences();
        $suiteToTestPair = [];
        foreach ($testConfiguration['tests'] as $test) {
            if (array_key_exists($test, $testsInSuites)) {
                $suites = $testsInSuites[$test];
                foreach ($suites as $suite) {
                    $suiteToTestPair[] = "$suite:$test";
                }
            }
        }

        // add tests to suites array
        $diff = [];
        foreach ($suiteToTestPair as $pair) {
            list($suite, $test) = explode(":", $pair);
            $testConfiguration['suites'][$suite][] = $test;
            $diff[] = $test;
        }

        // remove tests in suites from the tests array
        $testConfiguration['tests'] = array_diff($testConfiguration['tests'], $diff);

        // encode and return the result
        $testConfigurationJson = json_encode($testConfiguration);
        return $testConfigurationJson;
    }

    /** second attempt at a cleaner implementation, needs work */
    private function getGroupAndSuiteConfiguration(array $groupOrSuiteNames)
    {
        $result['tests'] = [];
        $result['suites'] = null;

        $groups = [];
        $suites = [];

        $allSuites = SuiteObjectHandler::getInstance()->getAllObjects();
        $testsInSuites = SuiteObjectHandler::getInstance()->getAllTestReferences();

        foreach ($groupOrSuiteNames as $groupOrSuiteName) {
            if (array_key_exists($groupOrSuiteName, $allSuites)) {
                $suites[] = $groupOrSuiteName;
            } else {
                $groups[] = $groupOrSuiteName;
            }
        }

        foreach ($suites as $suite) {
            $result['suites'][$suite] = [];
        }

        foreach ($groups as $group) {
            $testsInGroup = TestObjectHandler::getInstance()->getTestsByGroup($group);

            $testsInGroupAndNotInAnySuite = array_diff(
                array_keys($testsInGroup),
                array_keys($testsInSuites)
            );

            $testsInGroupAndInAnySuite = array_diff(
                array_keys($testsInGroup),
                $testsInGroupAndNotInAnySuite
            );

            foreach ($testsInGroupAndInAnySuite as $testInGroupAndInAnySuite) {
                $cat = $testsInSuites[$testInGroupAndInAnySuite][0];
                $dog[$cat][] = $testInGroupAndInAnySuite;

                /*
                 * todo -- I left off here. Code works so far.
                 * I need to take this $dog array and put into the $result['suites'] array
                 * and then test it thoroughly
                 */

            }

            $result['tests'] = array_merge(
                $result['tests'],
                $testsInGroupAndNotInAnySuite
            );
        }

        $json = json_encode($result);
        return $json;
    }
}
