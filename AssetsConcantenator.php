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

    private $minify = true;

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
                        if (isset($asset['template'])
                         && is_string($asset['template'])
                         && preg_match('/type="(?P<type>.+?)"/', $asset['template'], $typematch)
                            // Regex is required...
                         && isset($asset['regex']) // regex to match assets in HTML code
                         && (isset($asset['glob']) && is_string($asset['glob']) || (isset($asset['ext']) && is_string($asset['ext'])))
                        ) {
                            foreach ((array) $asset['regex'] as $regex) {
                                if (
                                    // ...and must be a string (just to be sure)
                                    is_string($regex)
                                 && strstr($regex, '(?P<type>') // regex has type group
                                 && (
                                    // Regex for linked assets:
                                    strstr($regex, '(?P<url>') // regex has url group
                                    ||
                                    // or inline assets
                                    strstr($regex, '(?P<content>') // regex has content group
                                    )
                                ) {
                                    $_asset = $asset;
                                    $_asset['type']  = $typematch['type'];
                                    $_asset['regex'] = $regex;

                                    $this->assets[] = $_asset;
                                } else {
                                    trigger_error('Atomic loader: Unexpected assets definition (#1).', E_USER_WARNING);
                                }
                            }
                        } else {
                            trigger_error('Atomic loader: Unexpected assets definition (#2).', E_USER_WARNING);
                        }
                    }
                break;
                case 'minify':
                    $this->minify = !! $options['minify'];
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
        return strtr(base64_encode($this->hashStr($str)), array('+' => '-', '/' => '_', '=' => ''));
    }

    private function hashStr($str, $as_hex = false) {
        return $as_hex ? hash('sha256', $str) : hash('sha256', $str, true);
    }

    private function storeCombination($path, array $files)
    {
        $basedir = dirname($path);

        if (!file_exists($basedir)) {
            if (! mkdir($basedir, 0777, true)) {
                throw new \Exception('Failed to create base dir for combination file.');
            }
        }

        $cat = '';
        foreach ($files as &$file) {
            // Already a content
            if (strstr($file, "\n")) {
                $cat.= $file;
            } else {
                if ($str = file_get_contents($file)) {
                    $cat.= $this->trimEachLine($str, $this->minify)."\n";
                } elseif ($str===false) {
                    throw new \Exception('Failed to read one of source files: '.$file);
                }
            }
        }

        if (file_put_contents($path, $cat)) {
            return $this;
        }

        throw new \Exception('Failed to write combination file.');
    }

    private function trimEachLine($str, $minify = true) {
        $str     = explode("\n", $str);
        $new_str = array('');
        $new_str_line = 0;
        $new_str_length = 0;
        $max_line_length = 32768;

        foreach ($str as $i => &$line) {
            // Trim spaces
            $line = trim($line);

            if (!$minify) {
                continue;
            }

            // Remove inline comments
            $pos = strpos($line, '//');

            if ($pos===0) {
                $line = '';
            } elseif (
                $pos > 0
             && $line[($pos-1)]!=='\\' // Is not escaped
             && $line[($pos-1)]!==':' // Is not ://
            ) {
                $line = trim(substr($line, 0, $pos));
            }

            // If there's nothing left
            if ($line==='') {
                continue;
            }

            if (substr($line, -2)==='*/') {
                $line.="\n";
            }

            // Calculate line length
            $this_str_len    = strlen($line);
            $new_str_length += $this_str_len;

            if ($new_str_length + 1 /* the new line */ > $max_line_length) {
                // New line + reset
                $new_str_line++;
                $new_str_length = $this_str_len;
                $new_str[$new_str_line] = '';
            }

            $new_str[$new_str_line].= $line;
        }

        return implode("\n", $new_str);
    }

    public function defaultConcantenateAssets($html)
    {
        $asset_types  = array();
        $results      = array();

        // Group matches
        foreach ($this->assets as &$asset) {
            if (preg_match_all($asset['regex'], $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as &$match) {
                    if (isset($match['url'])) {
                        // Remove current site's public http base
                        $match['url'] = str_replace($this->publicURL, '', $match['url']);

                        // Is local?
                        if (!strstr($match['url'], '://')) {
                            if ($match['file'] = realpath($this->publicDir.'/'.ltrim($match['url'], '/'))) {
                                // Do not load more than one asset instance
                                if (!isset($asset_types[$match['type']]) || !in_array($match['file'], $asset_types[$match['type']]['files'])) {
                                    $asset_types[$match['type']]['files'][] = $match['file'];
                                    $asset_types[$match['type']]['tags'][]  = trim($match[0]);

                                    if (!isset($asset_types[$match['type']]['last_mod_time'])) {
                                        $asset_types[$match['type']]['last_mod_time'] = 0;
                                    }

                                    if (!isset($asset_types[$match['type']]['ext'])) {
                                        $asset_types[$match['type']]['ext'] = isset($asset['ext']) ? $asset['ext'] : substr(strrchr($asset['glob'],'.'),1);
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
                    } elseif (isset($match['content'])) {
                        // storeCombination relies on "\n" check, otherwise it considers it a path
                        $match['content'] = $this->trimEachLine($match['content'], $this->minify)."\n";

                        // Do not load more than one asset instance
                        if (!isset($asset_types[$match['type']]) || !in_array($match['content'], $asset_types[$match['type']]['files'])) {
                            $asset_types[$match['type']]['files'][] = $match['content'];
                            $asset_types[$match['type']]['tags'][]  = trim($match[0]);

                            if (!isset($asset_types[$match['type']]['ext'])) {
                                $asset_types[$match['type']]['ext'] = isset($asset['ext']) ? $asset['ext'] : substr(strrchr($asset['glob'],'.'),1);
                            }
                        }
                    }

                    $html = str_replace($match[0], '', $html);
                }
            }
        }

        // Concantenate
        foreach ($this->assets as &$asset) {
            $assets = &$asset_types[$asset['type']];

            if ($this->active && isset($assets['files'])) {
                if (isset($assets['is_processed'])) {
                    continue;
                }

                $assets['is_processed'] = true;
                $assets['hash'] = $this->hash(implode("::", $assets['files']) .'::'.$assets['last_mod_time'] );

                $assets['file'] = '/'.$assets['ext'].'/'.$assets['hash'].'.'.$assets['ext'];
                $assets['path'] = $this->publicStaticDir.$assets['file'];
                $assets['url']  = $this->publicStaticURL.$assets['file'];

                if (!file_exists($assets['file'])) {
                    try {
                        $this->storeCombination($assets['path'], $assets['files']);
                        $results[$asset['type']][] = sprintf($asset['template'], $assets['url']);
                    } catch (\Exception $e) {
                        if (isset($assets['tags'])) {
                            $results[$asset['type']] = array_merge((array) $results[$asset['type']], $assets['tags']);
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
