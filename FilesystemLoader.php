<?php

namespace attitude\Mustache;

use \Mustache_Loader_FilesystemLoader;
use \attitude\Elements\HTTPException;

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
    protected $baseDir;

    protected $publicDir = null;
    protected $publicURL = '';

    /**
     * @var array     Array of assets, where assets item is defined as an
     *                `array('glob' => (string) 'relative glob() expression', 'template' => (string) 'sprintf() template')`.
     */
    protected $assets = array();

    /**
     * @var array      Hashes of assets that have been already loaded within this loader.
     */
    protected $assetsIndex = array();

    protected $basename = 'template';
    protected $extension = '.mustache';
    protected $templates = array();

    protected $enableFiltersPragma = true;
    protected $expandTranslationMarkup = true;

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
            // A little help: try to create if subdirectory is missing
            if (!mkdir($this->baseDir, 0755, true)) {
                throw new \Mustache_Exception_RuntimeException(sprintf('Failed to create deep structure. FilesystemLoader baseDir must be a directory: %s', $baseDir));
            }

            // Test again
            if (!is_dir($this->baseDir)) {
                throw new \Mustache_Exception_RuntimeException(sprintf('FilesystemLoader baseDir must be a directory: %s', $baseDir));
            }
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
                case 'publicURL':
                    $options['publicURL'] = rtrim(trim($options['publicURL']), '/');

                    if (strlen($options['publicURL']) > 0) {
                        $this->publicURL = $options['publicURL'];
                    }
                break;
                case 'assets':
                    if (!is_array($options['assets'])) {
                        break;
                    }

                    foreach ($options['assets'] as &$asset) {
                        // Check integrity
                        if (isset($asset['glob'])
                         && is_string($asset['glob'])
                         && isset($asset['template'])
                         && is_string($asset['template'])
                         && preg_match('/type="(?P<type>.+?)"/', $asset['template'], $typematch)
                         ) {
                            $asset['type'] = $typematch['type'];
                            $this->assets[] = $asset;
                        } elseif (!isset($asset['ext'])) {
                            trigger_error('Atomic loader: Unexpected assets definition.', E_USER_WARNING);
                        }
                    }
                break;
                case 'basename':
                    $options['basename'] = trim($options['basename']);

                    if (strlen($options['basename']) > 0) {
                        $this->basename = $options['basename'];
                    }
                break;
                case 'enableFiltersPragma':
                    $this->enableFiltersPragma = !! $options['enableFiltersPragma'];
                break;
                case 'expandTranslationMarkup':
                    $this->expandTranslationMarkup = !! $options['expandTranslationMarkup'];
                break;
                default:
                break;
            }
        }

        if ($this->publicDir===null) {
            $this->publicDir = $this->baseDir;
        }
    }

    public static function getAssetDefaults()
    {
        return array(
            array(
                'glob'     => '*.css',
                'template' => '<link concatenate href="%s" media="all" rel="stylesheet" type="text/css" />',
                'regex'    => array (
                    '/ *?<[^>]+?concatenate[^>]*(?:href|src)="(?P<url>[^>]+?)?".*?type="(?P<type>.+?)"[^>]*?\/>\n?/',
                    '/ *?<style[^>]*?concatenate[^>]*?type="(?P<type>.+?)"[^>]*?>(?P<content>.+?)<\/style>\n?/s'
                )
            ),
            array(
                'glob'     => '*.js',
                'template' => '<script concatenate src="%s" type="text/javascript"></script>',
                'regex'    => array (
                    '/ *?<[^>]+?concatenate[^>]*(?:href|src)="(?P<url>[^>]+?)?".*?type="(?P<type>.+?)"[^>]*?><\/script>\n?/',
                    '/ *?<script[^>]*?concatenate[^>]*?type="(?P<type>.+?)"[^>]*?>(?P<content>.+?)<\/script>\n?/s'
                )
            )
        );
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
            trigger_error('Failed to load mustache: '.str_replace($this->baseDir, '', $fileName), E_USER_WARNING);
            throw new \Mustache_Exception_UnknownTemplateException($name);
        }

        // Enable filters pragma as if it was explicitly defined in the template
        $template = $this->enableFiltersPragma ? "{{%FILTERS}}\n" : "";

        $template.= trim(file_get_contents($fileName))."\n";

        if ($this->expandTranslationMarkup) {
            $template = $this->expandTranslationMarkup($template);
        }

        // Loop asset types to include (external .css or .js)
        foreach ($this->assets as &$asset) {
            $assetPattern = dirname($fileName).'/'.trim($asset['glob'], '/');
            $assetFiles   = glob($assetPattern);

            foreach ($assetFiles as $assetFile) {
                $hash = md5($assetFile);

                // Included previously?
                if (isset($this->assetsIndex[$asset['type']]['assets'][$hash])) {
                    continue; // with next asset
                }

                $template.= sprintf(trim($asset['template'])."\n", $this->publicURL.str_replace($this->publicDir, '', $assetFile));

                $this->assetsIndex[$asset['type']]['assets'][$hash] = $assetFile;
            }
        }

        return $template;
    }

    /**
      * Expands simplified translation markup to Mustache helper specifics
      *
      * Examples:
      *
      * General use: {{ 'Event' | translate }}
      * Mustache:    {{#__}}Event{{/__}}
      *
      * Pluralize:   {{ people ? 'One person is attending' : '{} people are attending' | translate }}
      * Mustache:    {{#_n}}{"var": "people", "one": "One person is attending", "other": "{} are attending"}{{/_n}}
      *
      */
    protected function expandTranslationMarkup($str)
    {
        return preg_replace_callback(
            '/(\{\{\{?)(.+?)(\}?\}\})/m',
            function($matches) {
                $original = $matches[0];
                $str = trim($matches[2]);

                if (! preg_match('/(.+)\s*\|\s*translate/', $str, $matches)) {
                    return $original;
                }

                // Set the new match
                $str = trim($matches[1]);

                // Expect just one string
                if ($str[0]==="'" || $str[0]==='"') {
                    $quotechr = $str[0];

                    if (! preg_match('/'.$quotechr.'[^'.$quotechr.']+'.$quotechr.'/', $str, $submatches)) {
                        throw new HTTPException(500, 'Parse error near `'.$str.'`');
                    }

                    return '{{#__}}'.trim($str, $quotechr).'{{/__}}';
                }

                // Expect ternary operator
                if (! preg_match('/([\.\w]+)\s*?\?\s*([\'"])(.+?)([\'"])\s*:\s*([\'"])(.+?)([\'"])/', $str, $submatches)) {
                    throw new HTTPException(500, 'Parse error near `'.$str.'`: expecting ternary operator.');
                }

                $quotechr = $submatches[2];

                // Not the same quotes
                if (strlen(trim($submatches[2].$submatches[4].$submatches[5].$submatches[7], $quotechr)) !== 0) {
                    throw new HTTPException(500, 'Parse error near `'.$str.'`: expecting `'.$quotechr.'` qotes.');
                }

                // Build language arguments
                return '{{#_n}}'.json_encode(array(
                    'var'   => $submatches[1],
                    'one'   => str_replace('\\'.$quotechr, $quotechr, $submatches[3]),
                    'other' => str_replace('\\'.$quotechr, $quotechr, $submatches[6])
                )).'{{/_n}}';
            },
            $str
        );
    }

    /**
     * Helper function for getting a Mustache template file name.
     *
     * Forces partials/templates structure up to 1 level deep
     * for improved maintanance.
     *
     * @param string $name
     *
     * @return string Template file name
     */
    protected function getFileName($name)
    {
        // Exploder words
        $name = explode('-', str_replace('/', '-', trim($name, '/')));
        // Take first word as group of partials
        $partials_group = array_shift($name);

        // Build the filename back
        $fileName = $this->baseDir . '/' . $partials_group. '/' . implode('-', $name);

        // This loader assumes each template is a directory with
        // a `template.mustache` filename (can be customized).
        if (substr($fileName, 0 - strlen($this->extension)) !== $this->extension) {
            $fileName .= '/'.$this->basename.$this->extension;
        } else {
            $fileName.= substr($fileName, 0, 0 - strlen($this->extension)).'/'.$this->basename.$this->extension;
        }

        return $fileName;
    }
}
