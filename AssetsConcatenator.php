<?php

namespace attitude\Mustache;

class AtomicLoader_AssetsConcatenator
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

    private function trimEachLine($str, $minify = true)
    {
        if (!$minify) {
            $str = explode("\n", $str);
            foreach ($str as $i => &$line) {
                // Trim spaces
                $line = trim($line);
            }

            return implode("\n", $str);
        }

        // Minification
        $new_str     = '';
        $open        = false;
        $type        = null;
        $new_str_len = 0;
        $last_ch     = "\n";

        $str_len = strlen($str);
        for ($i=0; $i<$str_len; $i++) {
            // Open block comment, but keep /*! comments
            if ($open===false && $str[$i]==='/' && $str[($i+1)]==='*' && $str[($i+2)]!=='!') {
                $open = $new_str_len;
                $type = 'blockcomment';
            }

            // Close block comment
            if ($open!==false && $type==='blockcomment' && $str[$i]==='*' && $str[($i+1)]==='/') {
                $open = false;
                $i++; // Jump after comment
                continue;
            }

            // Open line comment
            if ($open===false && $str[$i]==='/' && $str[($i+1)]==='/'
             && (
                 $i===0
                 ||
                 ($i>0 && $str[($i-1)]!==':' && $str[($i-1)]!=='\\'))
            ) {
                $open = $new_str_len;
                $type = 'inlinecomment';

                // Possibility of false match of `background: url(//somedomain.com/img.gif);
                if ($str[$i-1]==='(') {
                    $next_newline_pos     = strpos($str, "\n", $i);
                    $next_parenthesis_pos = strpos($str, ')', $i);

                    // EOF ?
                    $next_newline_pos = $next_newline_pos===false ? $str_len : $next_newline_pos;

                    // There is a closing parenthesis on the same line
                    if ($next_parenthesis_pos && $next_newline_pos > $next_parenthesis_pos) {
                        $open = false;
                    }
                }
            }

            // Close line comment on the end of the line
            if ($open!==false && $type==='inlinecomment' && $str[$i]==="\n") {
                $open = false;
                continue;
            }

            // Skip ' ' before ':;,{}()<>='
            if ($open===false && $str[$i]===' '
             && (
                    $str[($i+1)]===' ' // Skip multiple ' ' (space characters)
                 || $str[($i+1)]===':'
                 || $str[($i+1)]===';'
                 || $str[($i+1)]===','
                 || $str[($i+1)]==='('
                 || $str[($i+1)]===')'
                 || $str[($i+1)]==='}'
                 || $str[($i+1)]==='{'
                 || $str[($i+1)]==='>'
                 || $str[($i+1)]==='<'
                 || $str[($i+1)]==='='
                )
            ) {
                continue;
            }

            // Skip ' ' after ':;,{}()<>='
            if ($open===false && $str[$i]===' '
             && (   $last_ch==="\n"
                 || $last_ch===':'
                 || $last_ch===';'
                 || $last_ch===','
                 || $last_ch==='{'
                 || $last_ch==='}'
                 || $last_ch==='('
                 || $last_ch===')'
                 || $last_ch==='>'
                 || $last_ch==='<'
                 || $last_ch==='='
                )
            ) {
                continue;
            }

            // Remove new lines
            if ($open===false && $str[$i]==="\n") {
                if ($new_str_len < 32000) { // max 32768 still has 768 left
                    continue;
                } else {
                    $new_str_len = -1; // Will be set to 0 right away
                }
            }

            // Inside of a string...
            if ($open===false && $str[$i]==="\"") {
                $open = $new_str_len;
                $type = 'doublequotes';
            }
            if ($open!==false && $new_str_len > $open && $type==='doublequotes' && $str[$i]==="\"" && $str[($i-1)]!=="\\") {
                $open = false;
            }

            // Inside of a string...
            if ($open===false && $str[$i]==='\'') {
                $open = $new_str_len;
                $type = 'singlequotes';
            }
            if ($open!==false && $new_str_len > $open && $type==='singlequotes' && $str[$i]==='\'' && $str[($i-1)]!=="\\") {
                $open = false;
            }

            // Write only when closed
            if ($open===false || $open!==false && ($type==='doublequotes' || $type==='singlequotes')) {
                $new_str.= $str[$i];
                $last_ch = $str[$i];
                $new_str_len++;
            }
        }

        return $new_str;
    }

    public function defaultConcatenateAssets($html)
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
                                // Store tag wich cannot be concatenated:
                                $results[$match['type']] = $match[0];
                            }
                        } else {
                            // @TODO: Fetch remotes
                            // Store tag wich cannot be concatenated:
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

        // Concatenate
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

                if (!file_exists($assets['path'])) {
                    try {
                        $this->storeCombination($assets['path'], $assets['files']);
                        $results[$asset['type']][] = sprintf($asset['template'], $assets['url']);
                    } catch (\Exception $e) {
                        if (isset($assets['tags'])) {
                            $results[$asset['type']] = array_merge((array) $results[$asset['type']], $assets['tags']);
                        }

                        trigger_error($e->getMessage(), E_USER_WARNING);
                    }
                } else { // Already cached
                    $results[$asset['type']][] = sprintf($asset['template'], $assets['url']);
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
            if (strstr($html, '<!--concatenated-assets:'.$type.'-->')) {
                $html = str_replace('<!--concatenated-assets:'.$type.'-->', implode("\n", $tags), $html);
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
