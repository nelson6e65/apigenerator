<?php

/**
 * Github ApiGen Hook
 * Copyright (C) 2013 Tristan Lins
 *
 * PHP version 5
 *
 * @copyright  bit3 UG 2013
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @package    gh-apigen-hook
 * @license    LGPL-3.0+
 * @filesource
 */

namespace GhApiGen;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class Hook
{
	/**
	 * @var string
	 */
	protected $root;

	/**
	 * @var HandlerInterface
	 */
	protected $handler;

	/**
	 * @var Logger
	 */
	protected $logger;

	/**
	 * @var Filesystem
	 */
	protected $fs;

	/**
	 * @var string
	 */
	protected $ownerName;

	/**
	 * @var string
	 */
	protected $repositoryName;

	/**
	 * @var string
	 */
	protected $masterBranch;

	/**
	 * @var string
	 */
	protected $commitBranch;

	/**
	 * @var string
	 */
	protected $commitMessage;

	/**
	 * @var array
	 */
	protected $repositories;

	/**
	 * @var array
	 */
	protected $defaultSettings;

	/**
	 * @var stdClass
	 */
	protected $payload;

	/**
	 * @var string
	 */
	protected $repository;

	/**
	 * @var array
	 */
	protected $settings;

	/**
	 * @var string
	 */
	protected $sourcesPath;

	/**
	 * @var string
	 */
	protected $docsPath;

	function __construct()
	{
		set_error_handler(array($this, 'handleError'));
		set_exception_handler(array($this, 'handleException'));

		$this->root = dirname(dirname(__DIR__));

		$this->handler = new RotatingFileHandler($this->root . '/log/hook.log', 7);
		$this->logger = new Logger('*/*', array($this->handler));

		$this->fs = new Filesystem();
	}

	/**
	 * @param $errno
	 * @param $errstr
	 * @param $errfile
	 * @param $errline
	 *
	 * @throws ErrorException
	 */
	public function handleError($errno, $errstr, $errfile, $errline ) {
		throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
	}

	/**
	 * @param Exception $exception
	 */
	public function handleException($exception) {
		$message = '';
		$e = $exception;
		do {
			$message .= sprintf(
				"%s:%d: [%s] (%d) %s\n",
				$e->getFile(),
				$e->getLine(),
				get_class($e),
				$e->getCode(),
				$e->getMessage()
			);
			$e = $e->getPrevious();
		} while ($e);

		$this->logger->error(rtrim($message));

		while (count(ob_list_handlers())) ob_end_clean();
		header("HTTP/1.0 500 Internal Server Error");
		echo '500 Internal Server Error';
		exit(1);
	}

	public function run($ownerName, $repositoryName, $masterBranch, $commitBranch, $commitMessage)
	{
		$this->logger = new Logger($ownerName . '/' . $repositoryName, array($this->handler));

		$this->logger->info(
			sprintf(
				'Run apigen for %s/%s, branch %s: %s',
				$ownerName,
				$repositoryName,
				$commitBranch,
				$commitMessage
			)
		);

		$this->ownerName = $ownerName;
		$this->repositoryName = $repositoryName;
		$this->masterBranch = $masterBranch;
		$this->commitBranch = $commitBranch;
		$this->commitMessage = $commitMessage;

		$this->repository = $this->ownerName . '/' . $this->repositoryName;

		$this->checkApiGenInstalled();
		$this->loadRepositories();
		$this->buildDefaultSettings();
		$this->buildSettings();
		$this->checkBranch();
		$this->initSourcePath();
		$this->initDocsPath();
		$this->checkoutSource();
		$this->prepareDocs();
		$this->generateDocs();
		$this->pushDocs();
	}

	protected function checkApiGenInstalled()
	{
		if (!file_exists($this->root . '/apigen/apigen.php')) {
			throw new \RuntimeException('apigen is not installed');
		}
	}

	protected function loadRepositories()
	{
		if (file_exists($this->root . '/config/repositories.yml')) {
			$this->repositories = \Symfony\Component\Yaml\Yaml::parse($this->root . '/config/repositories.yml');
		}
		else {
			throw new \RuntimeException('Please configure repositories in config/repositories.yml');
		}
	}

	protected function buildDefaultSettings()
	{
		// build defaults
		if (array_key_exists('defaults', $this->repositories)) {
			$this->defaultSettings = $this->repositories['defaults'];
		}
		else {
			$this->defaultSettings = array();
		}

		# build default base url
		if (!array_key_exists('base-url', $this->defaultSettings)) {
			$this->defaultSettings['base-url'] = sprintf(
				'http://%s.github.io/%s/',
				$this->ownerName,
				$this->repositoryName
			);
		}

		# set default title
		if (!array_key_exists('title', $this->defaultSettings)) {
			$this->defaultSettings['title'] = $this->repository;
		}
	}

	protected function buildSettings()
	{
		// get the repository documentation settings
		if (array_key_exists($this->repository, $this->repositories)) {
			$this->settings = $this->repositories[$this->repository];
		}
		else {
			$ownerWildcard = $this->ownerName . '/*';

			if (!array_key_exists($ownerWildcard, $this->repositories)) {
				throw new \RuntimeException('Repository ' . $this->repository . ' is not allowed');
			}

			$this->settings = $this->repositories[$ownerWildcard];
		}
		if ($this->settings === null) {
			$this->settings = array();
		}

		// use the github master branch
		if (empty($this->settings['branch'])) {
			$this->settings['branch'] = $this->masterBranch;
		}

		# merge with defaults
		$this->settings = array_merge(
			$this->defaultSettings,
			$this->settings
		);

		$this->logger->debug(
			sprintf('Build settings for %s/%s', $this->ownerName, $this->repositoryName),
			$this->settings
		);
	}

	protected function checkBranch()
	{
		if ($this->settings['branch'] != $this->commitBranch) {
			$this->logger->debug('Skip branch ' . $this->commitBranch . ', expect branch ' . $this->settings['branch']);
			exit;
		}
	}

	protected function initSourcePath()
	{
		# create sources path
		$this->sourcesPath = sprintf(
			$this->root . '/sources/%s/%s/',
			$this->ownerName,
			$this->repositoryName
		);

		$this->logger->debug(sprintf('Init sources directory %s', $this->sourcesPath));

		if (!$this->fs->exists($this->sourcesPath)) {
			$this->fs->mkdir($this->sourcesPath);
		}
	}

	protected function initDocsPath()
	{
		# create docs path
		$this->docsPath = sprintf(
			$this->root . '/docs/%s/%s/',
			$this->ownerName,
			$this->repositoryName
		);

		$this->logger->debug(sprintf('Init docs directory %s', $this->docsPath));

		if (!$this->fs->exists($this->docsPath)) {
			$this->fs->mkdir($this->docsPath);
		}
	}

	protected function checkoutSource()
	{
		$url = escapeshellarg('git://github.com/' . $this->repository . '.git');

		if ($this->fs->exists($this->sourcesPath . '.git')) {
			$this->logger->debug(sprintf('Update sources %s', $this->sourcesPath));

			$process = new Process('git remote set-url origin ' . $url, $this->sourcesPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}

			$process = new Process('git fetch origin', $this->sourcesPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}

			$process = new Process('git reset --hard ' . escapeshellarg('origin/' . $this->settings['branch']), $this->sourcesPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}
		}
		else {
			$this->logger->debug(sprintf('Checkout source %s', $url));

			$process = new Process('git clone -b ' . escapeshellarg($this->settings['branch']) . ' ' . $url . ' ' . escapeshellarg($this->sourcesPath));
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}
		}
	}

	protected function prepareDocs()
	{
		$url = escapeshellarg('git@github.com:' . $this->repository . '.git');

		if ($this->fs->exists($this->docsPath . '.git')) {
			$process = new Process('git remote set-url origin ' . $url, $this->docsPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}
		}
		else {
			$process = new Process('git init ' . escapeshellarg($this->docsPath));
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}

			$process = new Process('git remote add origin ' . $url, $this->docsPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}
		}

		$process = new Process('git fetch origin', $this->docsPath);
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
		}

		$process = new Process('git branch -a', $this->docsPath);
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
		}
		$branches = explode("\n", $process->getOutput());
		$branches = array_map(function($branch) { return ltrim($branch, '*'); }, $branches);
		$branches = array_map('trim', $branches);

		if (in_array('remotes/origin/gh-pages', $branches)) {
			$this->logger->debug(sprintf('Update docs %s', $url));

			$process = new Process('git checkout -B gh-pages', $this->docsPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}

			$process = new Process('git reset --hard origin/gh-pages', $this->docsPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}
		}
		else if (!in_array('gh-pages', $branches)) {
			$this->logger->debug(sprintf('Initialise empty docs %s', $url));

			$process = new Process('git checkout --orphan gh-pages', $this->docsPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}
		}
		else {
			$this->logger->debug(sprintf('Reuse local docs branch %s', $url));

			$process = new Process('git checkout -B gh-pages', $this->docsPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}
		}
	}

	protected function generateDocs()
	{
		$this->logger->debug('Generate docs');

		$args = array($this->root . '/apigen/apigen.php');
		foreach (array(
			'config',
			'extensions',
			'exclude',
			'skip-doc-path',
			'skip-doc-prefix',
			'charset',
			'main',
			'title',
			'base-url',
			'google-cse-id',
			'google-cse-label',
			'google-analytics',
			'template-config',
			'allowed-html',
			'groups',
			'autocomplete',
			'access-levels',
			'internal',
			'php',
			'tree',
			'deprecated',
			'todo',
			'source-code',
			'download',
			'report',
			'wipeout',
		) as $parameter) {
			if (array_key_exists($parameter, $this->settings)) {
				$args[] = '--' . $parameter;
				$args[] = is_bool($this->settings[$parameter]) ? ($this->settings[$parameter] ? 'yes' : 'no') : $this->settings[$parameter];
			}
		}
		$args[] = '--source';
		$args[] = $this->sourcesPath;
		$args[] = '--destination';
		$args[] = $this->docsPath;
		$args = array_map('escapeshellarg', $args);

		$cmd = 'php ' . implode(' ', $args);

		$process = new Process($cmd);
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
		}
	}

	protected function pushDocs()
	{
		$this->logger->debug('Push docs');

		$process = new Process('git status -s', $this->docsPath);
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
		}

		if ($process->getOutput()) {
			$process = new Process('git add .', $this->docsPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}

			$process = new Process('git commit -m ' . escapeshellarg($this->commitMessage), $this->docsPath);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
			}
		}

		$process = new Process('git push origin gh-pages', $this->docsPath);
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getCommandLine() . ': ' . $process->getErrorOutput());
		}
	}
}