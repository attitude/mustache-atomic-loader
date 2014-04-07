<?php

namespace attitude\Mustache;

class AtomicLoader_AssetsConcantenator
{
    public  $active  = true;

    private $sources = array();

    /**
     * @var array     Array of assets, where assets item is defined as an
     *                `array((string) 'glob() expression', string 'sprintf() template')`.
     */
    private $assets = array();

    private $publicDir = null;
    private $publicStaticDir = null;

    private $publicURL = '';
    private $publicStaticURL = '';

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
                case 'publicStaticDir':
                    $options['publicStaticDir'] = trim($options['publicStaticDir']);

                    if (strlen($options['publicStaticDir']) > 0 && realpath($options['publicStaticDir'])) {
                        $this->publicStaticDir = realpath($options['publicStaticDir']);
                    }
                break;
                case 'publicStaticURL':
                    $options['publicStaticURL'] = rtrim(trim($options['publicStaticURL']), '/');

                    if (strlen($options['publicStaticURL']) > 0) {
                        $this->publicStaticURL = $options['publicStaticURL'];
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
                         && isset($asset['regex']) // regex to match assets in HTML code
                         && is_string($asset['regex'])
                         && strstr($asset['regex'], '(?P<url>') // regex has url group
                         && strstr($asset['regex'], '(?P<type>') // regex has type group
                         && preg_match('/type="(?P<type>.+?)"/', $asset['template'], $typematch)
                         ) {
                            $asset['type']  = $typematch['type'];
                            $this->assets[] = $asset;
                        } else {
                            trigger_error('Atomic loader: Unexpected assets definition.', E_USER_WARNING);
                        }
                    }
                break;
                default:
                break;
            }
        }

        if ($this->publicDir===null) {
            $this->publicDir = $this->baseDir;
        }

        if ($this->publicStaticDir===null) {
            $this->publicStaticDir = $this->publicDir;
        }
    }

    private function hash($str) {
        return strtr(base64_encode(hash('sha256', $str, true)), array('+' => '-', '/' => '_', '=' => ''));
    }

    private function storeCombination($path, array $files)
    {
        $basedir = dirname($path);

        if (!file_exists($basedir)) {
            var_dump($basedir);

            if (! mkdir($basedir, 0777, true)) {
                throw new \Exception('Failed to create base dir for combination file.');
            }
        }

        $cat = '';
        foreach ($files as &$file) {
            if ($str = file_get_contents($file)) {
                $cat.= trim($str)."\n";
            } else {
                throw new \Exception('Failed to read one of source files.');
            }
        }

        if (file_put_contents($path, $cat)) {
            return $this;
        }

        throw new \Exception('Failed to write combination file.');
    }

    public function defaultConcantenateAssets($html)
    {
        $asset_types  = array();
        $results      = array();

        // Group matches
        foreach ($this->assets as &$asset) {
            if (preg_match_all($asset['regex'], $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as &$match) {
                    // Remove current site's public http base
                    $match['url'] = str_replace($this->publicURL, '', $match['url']);

                    // Is local?
                    if (!strstr($match['url'], '://')) {
                        if ($match['file'] = realpath($this->publicDir.'/'.ltrim($match['url'], '/'))) {
                            // Do not load more than one asset instance
                            if (! in_array($match['file'], $asset_types[$match['type']]['files'])) {
                                $asset_types[$match['type']]['files'][] = $match['file'];
                                $asset_types[$match['type']]['tags'][]  = trim($match[0]);

                                if (!isset($asset_types[$match['type']]['last_mod_time'])) {
                                    $asset_types[$match['type']]['last_mod_time'] = 0;
                                }

                                if (!isset($asset_types[$match['type']]['ext'])) {
                                    $asset_types[$match['type']]['ext'] = substr(strrchr($asset['glob'],'.'),1);
                                }

                                $filemtime = filemtime($match['file']);
                                if ($asset_types[$match['type']]['last_mod_time'] < $filemtime) {
                                    $asset_types[$match['type']]['last_mod_time'] = $filemtime;
                                }
                            }
                        } else {
                            // Store tag wich cannot be concantenated:
                            $results[$match['type']] = $match[0];
                        }
                    } else {
                        // @TODO: Fetch remotes
                        // Store tag wich cannot be concantenated:
                        $results[$match['type']] = $match[0];
                    }

                    $html = str_replace($match[0], '', $html);
                }
            }
        }

        // Concantenate
        foreach ($this->assets as &$asset) {
            $assets = &$asset_types[$asset['type']];

            if ($this->active && isset($assets['files'])) {
                $assets['hash'] = $this->hash(implode("::", $assets['files']));

                $assets['file'] = '/'.$assets['ext'].'/'.$assets['hash'].'.'.$assets['last_mod_time'].'.'.$assets['ext'];
                $assets['path'] = $this->publicStaticDir.$assets['file'];
                $assets['url']  = $this->publicStaticURL.$assets['file'];

                if (!file_exists($assets['file'])) {
                    try {
                        $this->storeCombination($assets['path'], $assets['files']);
                        $results[$asset['type']][] = sprintf($asset['template'], $assets['url']);
                    } catch (\Exception $e) {
                        if (isset($assets['tags'])) {
                            $results[$asset['type']] = array_merge($results[$asset['type']], $assets['tags']);
                        }

                        trigger_error($e->getMessage(), E_USER_WARNING);
                    }
                }
            } elseif (isset($assets['tags'])) {
                if (! isset($results[$asset['type']])) {
                    $results[$asset['type']] = $assets['tags'];
                } else {
                    $results[$asset['type']] = array_merge($results[$asset['type']], $assets['tags']);
                }
            }
        }

        foreach ($results as $type => &$tags) {
            if (strstr($html, '<!--concantenated-assets:'.$type.'-->')) {
                $html = str_replace('<!--concantenated-assets:'.$type.'-->', implode("\n", $tags), $html);
            } elseif ($type==='css' && strstr($html, '</head>')) {
                $html = str_replace('</head>', implode("\n", $tags).'</head>', $html);
            } elseif ($type==='css' && strstr($html, '</body>')) {
                $html = str_replace('</body>', implode("\n", $tags).'</body>', $html);
            } else {
                $html.= implode("\n", $tags);
            }
        }

        return $html;
    }
}
