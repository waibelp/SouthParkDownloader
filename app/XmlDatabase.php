<?php

/**
 * Base class for reading/writing an XML database.
 * This file is part of the South Park Downloader package.
 *
 * @author Christian Raue <christian.raue@gmail.com>
 * @copyright 2011-2014 Christian Raue
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPLv3 License
 */
class XmlDatabase {

	protected $source;
	protected $data;

	public function __construct($source) {
		$this->source = $source;
		$this->data = simplexml_load_file($this->source);
	}

	public function getData() {
		return $this->data;
	}

	public function save() {
		$this->data->asXML($this->source);
	}

	protected function addXPathNamespace(SimpleXMLElement $element, $prefix) {
		$element->registerXPathNamespace($prefix, 'https://github.com/craue/SouthParkDownloader');
		return $element;
	}

}
