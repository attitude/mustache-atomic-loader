<?php

namespace attitude\Mustache;

use \Mustache_Loader_FilesystemLoader;

/*
 * This file is not part of Mustache.php
 *
 * (c) 2014 Martin Adamko
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Mustache Atomic Template filesystem Loader implementation.
 *
 * A FilesystemLoader instance loads Mustache Template source from the filesystem by name:
 *
 *     $loader = new Mustache_Loader_FilesystemLoader(dirname(__FILE__).'/views');
 *     $tpl = $loader->load('foo-bar'); // equivalent to `file_get_contents(dirname(__FILE__).'/views/foo/bar/template.mustache');
 *
 */
class AtomicLoader_FilesystemLoader extends Mustache_Loader_FilesystemLoader
{
    private $baseDir;
    private $basename = 'template';
    private $extension = '.mustache';
    private $templates = array();

    /**
     * Mustache filesystem Loader constructor (Change: Added basename option).
     *
     * @see https://github.com/bobthecow/mustache.php/blob/master/src/Mustache/Loader/FilesystemLoader.php
     *
     */
    public function __construct($baseDir, array $options = array())
    {
        $this->baseDir = $baseDir;

        if (strpos($this->baseDir, '://') === -1) {
            $this->baseDir = realpath($this->baseDir);
        }

        if (!is_dir($this->baseDir)) {
            throw new Mustache_Exception_RuntimeException(sprintf('FilesystemLoader baseDir must be a directory: %s', $baseDir));
        }

        if (array_key_exists('extension', $options)) {
            if (empty($options['extension'])) {
                $this->extension = '';
            } else {
                $this->extension = '.' . ltrim($options['extension'], '.');
            }
        }

        if (array_key_exists('basename', $options) && strlen(trim($options['basename'])) > 0) {
            $this->basename = trim($options['basename']);
        }
    }

    /**
     * Helper function for getting a Mustache template file name.
     *
     * @param string $name
     *
     * @return string Template file name
     */
    protected function getFileName($name)
    {
        $name = str_replace('-', '/', trim($name, '/'));
        $fileName = $this->baseDir . '/' . $name;


        if (substr($fileName, 0 - strlen($this->extension)) !== $this->extension) {
            $fileName .= '/'.$this->basename.$this->extension;
        } else {
            $fileName.= substr($fileName, 0, 0 - strlen($this->extension)).'/'.$this->basename.$this->extension;
        }

        return $fileName;
    }
}
