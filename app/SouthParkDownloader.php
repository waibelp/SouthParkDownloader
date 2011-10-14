<?php

require_once(__DIR__.'/Config.php');
require_once(__DIR__.'/EpisodeDatabase.php');
require_once(__DIR__.'/PlayerDatabase.php');
require_once(__DIR__.'/ChecksumMismatchException.php');
require_once(__DIR__.'/FileAlreadyExistsException.php');
require_once(__DIR__.'/FileDoesNotExistException.php');

/**
 * Implementation of control flow and logic.
 * This file is part of the South Park Downloader package.
 *
 * @author Christian Raue <christian.raue@gmail.com>
 * @copyright 2011 Christian Raue
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPLv3 License
 */
class SouthParkDownloader {

	const EXITCODE_SUCCESS = 0;

	const EXITCODE_RTMPDUMP_FAILED = 1;
	const EXITCODE_RTMPDUMP_INCOMPLETE = 2; // e.g. "Download may be incomplete (downloaded about 71.10%), try resuming"

	const EXITCODE_MKVMERGE_WARNINGS = 1; // at least one warning
	const EXITCODE_MKVMERGE_ERROR = 2;

	protected $episodeDb;
	protected $config;
	protected $player;

	protected $tempFiles = array();
	protected $downloadedFiles = array();

	public function setConfig(Config $config) {
		$this->config = $config;
	}

	public function run() {
		$playerDb = new PlayerDatabase(__DIR__.'/../data/players.xml');
		$this->player = $playerDb->findPlayer($this->config->getPlayerUrl());

		$this->episodeDb = new EpisodeDatabase(__DIR__.'/../data/episodes.xml');

		$episodesToProcess = array();

		if ($this->config->getEpisode() > 0) {
			$episodesToProcess[] = $this->config->getEpisode();
		} else {
			$episodesToProcess = $this->episodeDb->getEpisodeIds($this->config->getSeason(), $this->config->getMainLanguage());
		}

		foreach ($episodesToProcess as $episode) {
			$this->config->setEpisode($episode);
			$this->download();
			$this->merge();
			$this->rename();
			$this->cleanUp();
		}
	}

	public function download() {
		foreach ($this->config->getLanguages() as $language) {
			foreach ($this->episodeDb->getActs($this->config->getSeason(), $this->config->getEpisode(), $language) as $act) {
				$actId = $act->attributes()->id;
				$this->downloadPart($language, $actId);
			}
		}
	}

	protected function downloadPart($language, $actId) {
		$targetFile = $this->config->getDownloadFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), $language, 'mp4', $actId);
		if (file_exists($targetFile)) {
			throw new FileAlreadyExistsException($targetFile);
		}

		$this->downloadedFiles[] = $targetFile;

		$exitCode = null;
		do {
			$exitCode = $this->call(sprintf('%s -o %s -r %s --swfUrl %s --swfsize %s --swfhash %s%s',
					escapeshellcmd($this->config->getRtmpdump()),
					escapeshellarg($targetFile),
					escapeshellarg($this->episodeDb->getUrl($this->config->getSeason(), $this->config->getEpisode(), $language, $actId, $this->config->getResolution())),
					escapeshellarg($this->player->swfurl),
					escapeshellarg($this->player->swfsize),
					escapeshellarg($this->player->swfhash),
					$exitCode === null ? '' : ' --resume'));
		} while ($exitCode === self::EXITCODE_RTMPDUMP_INCOMPLETE);

		if ($exitCode !== self::EXITCODE_SUCCESS) {
			$this->abort($exitCode);
		}

		$this->verifyChecksum($targetFile, $this->episodeDb->getSha1($this->config->getSeason(), $this->config->getEpisode(), $language, $actId, $this->config->getResolution()));
	}

	protected function verifyChecksum($file, $expectedChecksum) {
		if ($this->config->getVerifyChecksums() === true && !empty($expectedChecksum)) {
			$actualChecksum = sha1_file($file);
			if ($actualChecksum !== $expectedChecksum) {
				throw new ChecksumMismatchException($file, $expectedChecksum, $actualChecksum);
			}
		}
	}

	public function merge() {
		$this->extractVideoParts();
		$this->extractAudioParts();
		$this->mergeVideoWithAudioParts();
		$this->mergeAllFinalParts();
	}

	/**
	 * Renames an episode's final file to contain the titles of all desired languages.
	 */
	public function rename() {
		$sourceFile = $this->config->getOutputFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), $this->config->getLanguages(), 'mkv');
		if (!file_exists($sourceFile)) {
			throw new FileDoesNotExistException($sourceFile);
		}

		$title = '';
		$languages = $this->config->getLanguages();
		for ($i = 0; $i < count($languages); $i++) {
			$currentLanguage = $languages[$i];
			if ($i > 0) {
				$title .= ' (';
			}
			$title .= $this->episodeDb->getTitle($this->config->getSeason(), $this->config->getEpisode(), $currentLanguage);
			if ($i > 0) {
				$title .= ')';
			}
		}

		$targetFile = $this->config->getOutputFolder() . 'South Park ' . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), $this->config->getLanguages(), 'mkv', null, $title);
		if (file_exists($targetFile)) {
			throw new FileAlreadyExistsException($targetFile);
		}

		rename($sourceFile, $targetFile);
	}

	public function cleanUp() {
		if ($this->config->getRemoveTempFiles() === true) {
			foreach ($this->tempFiles as $tempFile) {
				unlink($tempFile);
			}
		}
		$this->tempFiles = array();

		if ($this->config->getRemoveDownloadedFiles() === true) {
			foreach ($this->downloadedFiles as $downloadedFile) {
				unlink($downloadedFile);
			}
		}
		$this->downloadedFiles = array();
	}

	public function getCommandLineArguments(array $argv) {
		$config = new Config();

		for ($i = 1; $i < count($argv); $i++) {
			$param = explode('=', $argv[$i]);
			if (count($param) === 2) {
				list($key, $value) = $param;
				switch (strtolower($key)) {
					case 's':
						if ((int) $value < 1) {
							throw new RuntimeException(sprintf('"%s" is not a valid season number.', $value));
						}
						$config->setSeason((int) $value);
						break;
					case 'e':
						if ((int) $value < 1) {
							throw new RuntimeException(sprintf('"%s" is not a valid episode number.', $value));
						}
						$config->setEpisode((int) $value);
						break;
					case 'l':
						$config->setLanguages(explode('+', $value));
						break;
					default:
						throw new RuntimeException(sprintf('Unknown parameter "%s" used.', $key));
				}
			} else {
				throw new RuntimeException(sprintf('Invalid parameter format used for "%s".', $argv[$i]));
			}
		}

		if ($config->getSeason() < 1) {
			throw new RuntimeException('No season parameter given.');
		}
		if (count($config->getLanguages()) < 1) {
			throw new RuntimeException('No language parameter given.');
		}

		return $config;
	}

	/**
	 * Extracts video from parts with first language.
	 */
	protected function extractVideoParts() {
		foreach ($this->episodeDb->getActs($this->config->getSeason(), $this->config->getEpisode(), $this->config->getMainLanguage()) as $act) {
			$actId = $act->attributes()->id;

			$sourceFile = $this->config->getDownloadFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), $this->config->getMainLanguage(), 'mp4', $actId);
			$targetFile = $this->config->getTmpFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), null, 'mkv', $actId);
			if (!file_exists($sourceFile)) {
				throw new FileDoesNotExistException($sourceFile);
			}
			if (file_exists($targetFile)) {
				throw new FileAlreadyExistsException($targetFile);
			}

			$this->tempFiles[] = $targetFile;
			$exitCode = $this->call(sprintf('%s -loglevel quiet -i %s -vcodec copy -an %s',
					escapeshellcmd($this->config->getFfmpeg()),
					escapeshellarg($sourceFile),
					escapeshellarg($targetFile)));

			if ($exitCode !== self::EXITCODE_SUCCESS) {
				$this->abort($exitCode);
			}
		}
	}

	/**
	 * Extracts audio from all parts.
	 */
	protected function extractAudioParts() {
		foreach ($this->config->getLanguages() as $currentLanguage) {
			foreach ($this->episodeDb->getActs($this->config->getSeason(), $this->config->getEpisode(), $currentLanguage) as $act) {
				$actId = $act->attributes()->id;

				$sourceFile = $this->config->getDownloadFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), $currentLanguage, 'mp4', $actId);
				$targetFile = $this->config->getTmpFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), $currentLanguage, 'aac', $actId);
				if (!file_exists($sourceFile)) {
					throw new FileDoesNotExistException($sourceFile);
				}
				if (file_exists($targetFile)) {
					throw new FileAlreadyExistsException($targetFile);
				}

				$this->tempFiles[] = $targetFile;
				$exitCode = $this->call(sprintf('%s -loglevel quiet -i %s -vn -acodec copy %s',
						escapeshellcmd($this->config->getFfmpeg()),
						escapeshellarg($sourceFile),
						escapeshellarg($targetFile)));

				if ($exitCode !== self::EXITCODE_SUCCESS) {
					$this->abort($exitCode);
				}
			}
		}
	}

	/**
	 * Merges all audio parts into video parts.
	 */
	// 3x mkvmerge -o SXXEYYAZZDE+EN.mkv SXXEYYAZZ.mkv SXXEYYAZZDE.aac --sync 0:870 SXXEYYAZZEN.aac
	protected function mergeVideoWithAudioParts() {
		foreach ($this->episodeDb->getActs($this->config->getSeason(), $this->config->getEpisode(), $this->config->getMainLanguage()) as $act) {
			$actId = $act->attributes()->id;

			$actVideoSourceFile = $this->config->getTmpFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), null, 'mkv', $actId);
			if (!file_exists($actVideoSourceFile)) {
				throw new FileDoesNotExistException($actVideoSourceFile);
			}

			$targetFile = $this->config->getTmpFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), $this->config->getLanguages(), 'mkv', $actId);
			if (file_exists($targetFile)) {
				throw new FileAlreadyExistsException($targetFile);
			}

			$audioParts = '';
			$languages = $this->config->getLanguages();
			for ($i = 0; $i < count($languages); $i++) {
				$currentLanguage = $languages[$i];

				if (strlen($audioParts) > 0) {
					$audioParts .= ' ';
				}

				$actAudioDelay = $this->episodeDb->getActAudioDelay($this->config->getSeason(), $this->config->getEpisode(), $currentLanguage, $actId);
				if ($actAudioDelay > 0) {
					$audioParts .= sprintf('--sync 0:%u', $actAudioDelay);
					$audioParts .= ' ';
				}

				$actAudioSourceFile = $this->config->getTmpFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), $currentLanguage, 'aac', $actId);
				if (!file_exists($actAudioSourceFile)) {
					throw new FileDoesNotExistException($actAudioSourceFile);
				}
				$audioParts .= escapeshellarg($actAudioSourceFile);
			}

			$this->tempFiles[] = $targetFile;
			$exitCode = $this->call(sprintf('%s -o %s %s %s',
					escapeshellcmd($this->config->getMkvmerge()),
					escapeshellarg($targetFile),
					escapeshellarg($actVideoSourceFile),
					$audioParts));

			if ($exitCode !== self::EXITCODE_SUCCESS && $exitCode !== self::EXITCODE_MKVMERGE_WARNINGS) {
				$this->abort($exitCode);
			}
		}
	}

	/**
	 * Merges all final parts.
	 */
	// mkvmerge -o complete.mkv --default-track 2 --language 2:ger --language 3:eng part1.mkv +part2.mkv +part3.mkv
	protected function mergeAllFinalParts() {
		$targetFile = $this->config->getOutputFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), $this->config->getLanguages(), 'mkv');
		if (file_exists($targetFile)) {
			throw new FileAlreadyExistsException($targetFile);
		}

		$audioParts = '';
		$languages = $this->config->getLanguages();
		for ($i = 0; $i < count($languages); $i++) {
			$currentLanguage = $languages[$i];

			if (strlen($audioParts) > 0) {
				$audioParts .= ' ';
			}
			if (in_array(strtolower($currentLanguage), array('de', 'en'))) {
				$audioParts .= sprintf('--language %u:', $i + 2);
				switch (strtolower($currentLanguage)) {
					case 'de':
						$audioParts .= 'ger';
						break;
					case 'en':
						$audioParts .= 'eng';
						break;
				}
			}
		}

		$mkvParts = '';
		foreach ($this->episodeDb->getActs($this->config->getSeason(), $this->config->getEpisode(), $this->config->getMainLanguage()) as $act) {
			$actId = $act->attributes()->id;

			if (strlen($mkvParts) > 0) {
				$mkvParts .= ' +';
			}

			$actSourceFile = $this->config->getTmpFolder() . $this->getFilename($this->config->getSeason(), $this->config->getEpisode(), $this->config->getLanguages(), 'mkv', $actId);
			if (!file_exists($actSourceFile)) {
				throw new FileDoesNotExistException($actSourceFile);
			}

			$mkvParts .= escapeshellarg($actSourceFile);
		}

		$exitCode = $this->call(sprintf('%s -o %s --default-track 2 %s %s',
				escapeshellcmd($this->config->getMkvmerge()),
				escapeshellarg($targetFile),
				$audioParts,
				$mkvParts));

		if ($exitCode !== self::EXITCODE_SUCCESS) {
			$this->abort($exitCode);
		}
	}

	protected function getFilename($season, $episode, $language = null, $extension = null, $act = null, $title = null) {
		if (!is_array($language)) {
			$language = array($language);
		}
		return sprintf('S%02uE%02u%s%s%s%s',
				$season,
				$episode,
				!empty($act) ? 'A' . $act : '',
				!empty($title) ? ' ' . $title . ' ' : '',
				!empty($language) ? strtoupper(implode('+', $language)) : '',
				!empty($extension) ? '.' . $extension : '');
	}

	protected function call($command) {
		$exitCode = 0;

//		echo $command, "\n";
		passthru($command, $exitCode);

		return $exitCode;
	}

	protected function abort() {
		throw new RuntimeException(sprintf(
				'External program aborted with an exit code of "%d" which seems to be an error. Will abort now.',
				$exitCode));
	}

}
