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

    private $publicDir = null;
    private $publicURL = '';

    /**
     * @var array     Array of assets, where assets item is defined as an
     *                `array((string) 'glob() expression', string 'sprintf() template')`.
     */
    private $assets = array(
        array('*.css', '<link href="%s" media="all" rel="stylesheet" type="text/css" />'),
        array('*.js', '<script src="%s" type="text/javascript"></script>')
    );

    private $basename = 'template';
    private $extension = '.mustache';
    private $templates = array();

    private $enableFiltersPragma = true;

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
            throw new \Mustache_Exception_RuntimeException(sprintf('FilesystemLoader baseDir must be a directory: %s', $baseDir));
        }

        foreach ($options as $key => $option) {
            switch ($key) {
                case 'extension':
                    if (empty($options['extension'])) {
                        $this->extension = '';
                    } else {
                        $this->extension = '.' . ltrim($options['extension'], '.');
                    }
                break;
                case 'publicDir':
                    $options['publicDir'] = trim($options['publicDir']);

                    if (strlen($options['publicDir']) > 0 && realpath($options['publicDir'])) {
                        $this->publicDir = realpath($options['publicDir']);
                    }
                break;
                case 'assets':
                    if (!is_array($options['assets'])) {
                        break;
                    }

                    $this->assets = $options['assets'];
                break;
                case 'basename':
                    $options['basename'] = trim($options['basename']);

                    if (strlen($options['basename']) > 0) {
                        $this->basename = $options['basename'];
                    }
                break;
                case 'publicURL':
                    $options['publicURL'] = rtrim(trim($options['publicURL']), '/');

                    if (strlen($options['publicURL']) > 0) {
                        $this->publicURL = $options['publicURL'];
                    }
                break;
                case 'enableFiltersPragma':
                    $this->enableFiltersPragma = !! $options['enableFiltersPragma'];
                break;
                default:
                break;
            }
        }

        if ($this->publicDir===null) {
            $this->publicDir = $this->baseDir;
        }
    }

    /**
     * Helper function for loading a Mustache file by name.
     *
     * @throws Mustache_Exception_UnknownTemplateException If a template file is not found.
     *
     * @param string $name
     *
     * @return string Mustache Template source
     */
    protected function loadFile($name)
    {
        $fileName = $this->getFileName($name);

        if (!file_exists($fileName)) {
            throw new \Mustache_Exception_UnknownTemplateException($name);
        }

        // Enable filters pragma as if it was explicitly defined in the template
        $template = $this->enableFiltersPragma ? "{{%FILTERS}}\n" : "";
        $template.= trim(file_get_contents($fileName))."\n";

        // Loop asset types to include (external .css or .js)
        foreach ($this->assets as &$asset) {
            // Check integrity (not perfect)
            if (count($asset)===2 && isset($asset[0]) && isset($asset[1]) && is_string($asset[0]) && is_string($asset[1])) {
                $assetPattern = dirname($fileName).'/'.$asset[0];
                $assetFiles   = glob($assetPattern);

                foreach ($assetFiles as $assetFile) {
                    $template.= sprintf($asset[1], $this->publicURL.str_replace($this->publicDir, '', $assetFile))."\n";
                    $emitedAsset = true;
                }
            } else {
                throw new HTTPException(500, 'Atomic loader assets must be defined as an array(glob() expression, sprintf() template).');
            }
        }

        return $template;
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
