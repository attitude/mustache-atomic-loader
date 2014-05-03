<?php

namespace attitude\Mustache;

use \attitude\Elements\HTTPException;
use \attitude\Elements\DependencyContainer;

/**
 * Macaw template loader class
 *
 * Allow use of Macaw exports as mustache templates.
 *
 * Includes [mustache sections](http://mustache.github.io/mustache.5.html#Sections)
 *
 * - variables, e.g.: Welcome [user.name]
 * - false values via tag class, e.g.: `<img class=".only-if-value" >`
 * - repeatable sections (non-empty lists) via tag class, e.g.:
 *   ```
 *   <ul class=".repeat-person-in-event-attendants">
 *     <li>[firstName] [lastName]</li>
 *   </ul>
 *   or
 *   <ul class=".repeat-event-attendants">
 *     <li>[firstName] [lastName]</li>
 *   </ul>
 *   ```
 *
 * Non-mustache extras:
 *
 * - optional wrap with section: `{{#hasItems}}{{#items}}...{{/items}}{{/hasItems}}`
 *   which can be disabled by `\attitude\Elements\DependencyContainer::set('Macaw::useHasSections', false);`
 * - template translations (i18n & l10n); see `transcriptTranlations()` method
 *
 * Note on using classes in Macaw
 *
 * Macaw allows classes to be ONLY [a-z-_]. To support at least camelCase naming
 * convention, mark any variable context using '_' as prefix and suffix. Example:
 *
 * `p.text.only-if-event-_is_marked__marked_as_going_` is converted into
 * `p.text.only-if-event-isMarked_markedAsGoing`
 *
 * Anytime you use double `__` it is preserved as single '_'.
 *
 * Tip: By default Publisher supports Markdown, therefore you can use
 *      [variable | markdown] in your syntax. Just make sure, it is no inside
 *      a `<p>` tag using the Macaw's outline panel.
 *
 * @author Martin Adamko <http://twitter.com/martin_adamko>
 *
 */
class AtomicLoader_MacawLoader extends AtomicLoader_FilesystemLoader
{
    protected function getFileName($name)
    {
        static $allowed_folders = array('views', 'sections');

        if (strstr($name, '/')) {
            $name = explode('/', $name);
            $folder = array_shift($name);

            if (!in_array($folder, $allowed_folders)) {
                // Skip: Macaw Loader can load either full page HTML (view) or contents of <body> element (section)
                return;
            }

            $name = implode('-', $name);
        }

        // Find all mcw files
        static $mcw_files = null;

        // Where to look for .mcw files
        static $mcw_baseDir = null;

        // Search just once
        if ($mcw_files===null) {
            $mcw_baseDir = dirname($this->baseDir);
            $mcw_files = array();

            // Lookup published exports
            if ($mcw_source_files = glob($mcw_baseDir.'/*.mcw')) {
                foreach ($mcw_source_files as $mcw_file) {
                    $export = pathinfo($mcw_file, PATHINFO_FILENAME);

                    if ($html_files = glob($mcw_baseDir."/{$export}/*.html")) {
                        foreach ($html_files as $html_file) {
                            // Add to array since more version could be available (more .mcw exports)
                            $mcw_files['html'][pathinfo($html_file, PATHINFO_FILENAME)][] = $html_file;
                        }
                    }

                    // Lookup published assets
                    foreach ($this->assets as &$asset) {
                        $ext = pathinfo($asset['glob'], PATHINFO_EXTENSION);

                        if ($asset_files = glob($mcw_baseDir."/{$export}/{$ext}/{$asset['glob']}")) {
                            foreach ($asset_files as $asset_file) {
                                $absrel_url = str_replace($this->publicDir, $this->publicURL, $asset_file);

                                // Fix the relative URLS in CSS
                                if ($ext==='css'
                                 && pathinfo($asset_file, PATHINFO_FILENAME)!=='standardize'
                                ) {
                                    $asset_lock      = $mcw_baseDir.'/'.$export.'.swatches.less';
                                    $asset_lock_html = $mcw_baseDir.'/'.$export.'.swatches.html';
                                    $asset_lock_time = (int) @filemtime($asset_lock);

                                    if ($asset_lock_time < filemtime($asset_file)) {
                                        $css = str_replace("url('../", "url('".dirname(dirname($absrel_url))."/", file_get_contents($asset_file));
                                        file_put_contents($asset_file, $css);

                                        // Store swatches in the lock file
                                        if($swatches = file_get_contents($asset_lock)) {
                                            if (preg_match_all('/@(.+?):\s*(rgb\([0-9 ]+,[0-9 ]+,[0-9 ]+\))\s*/', $swatches, $matches)) {
                                                $swatches = array_combine($matches[1], $matches[2]);
                                            } else {
                                                $swatches = array();
                                            }
                                        } else {
                                            $swatches = array();
                                        }

                                        if(preg_match_all('/(#[0-9abcdef]{3}|#[0-9abcdef]{6}|rgba?\([0-9 ]+,[0-9 ]+,[0-9 ]+)/', $css, $matches)) {
                                            foreach($matches[0] as $swatch) {
                                                if(strstr($swatch, '#')) {
                                                    $swatch = trim($swatch,'#');
                                                    $swatch_len = strlen($swatch);
                                                    if($swatch_len===3) {
                                                        $swatch = 'rgb('.hexdec($swatch[0]).','.hexdec($swatch[1]).','.hexdec($swatch[2]).')';
                                                    } else {
                                                        $swatch = 'rgb('.hexdec($swatch[0]).hexdec($swatch[1]).', '.hexdec($swatch[2]).hexdec($swatch[3]).', '.hexdec($swatch[4]).hexdec($swatch[5]).')';
                                                    }
                                                } else {
                                                    $swatch = str_replace(array('rgba', ' '), array('rgb', ''), $swatch).')';
                                                }

                                                $swatch = trim($swatch);

                                                if (!in_array($swatch, $swatches)) {
                                                    $swatch_index = 1;
                                                    while(array_key_exists("swatch{$swatch_index}", $swatches)) {
                                                        $swatch_index++;
                                                    }
                                                    $swatches["swatch{$swatch_index}"] = $swatch;
                                                }
                                            }
                                        }

                                        uasort($swatches, function($a, $b) {
                                            $a = explode(',', str_replace(' ', '', trim($a, ' rgb()')));
                                            $b = explode(',', str_replace(' ', '', trim($b, ' rgb()')));

                                            return (array_sum($a) / 3) < (array_sum($b) / 3);
                                        });

                                        $swatches_less = array();
                                        foreach ($swatches as $k => $swatch) {
                                            $swatches_less[] = '@'.$k.': '.$swatch.';';
                                        }

                                        $swatches_less =
                                             "/**\n"
                                            ." * AUTOGENERATED SWATCHES FILE\n"
                                            ." *\n"
                                            ." * Macaw project: {$export}\n"
                                            ." * Last updated: ".date('r')."\n"
                                            ." * \n"
                                            ." * You can safely edit names of the swatches.\n"
                                            ." * Please try to maintain the form.\n"
                                            ." * All comments will be removed.\n"
                                            ." * \n"
                                            ." */\n\n"
                                            .implode("\n", $swatches_less);

                                        $swatches_html = <<<HTML
<!doctype html>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title>Swatches of {$export}</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
        * { box-sizing: border-box; }
        body { background: #f2f2f2; color: #666; font-family: sans-serif; font-size: 16px; margin: 0; padding: 0; }
        .page { background: white; margin: 0 auto; max-width: 480px; padding: 1em; }
        .headline { font-weight: normal; text-align: center; }
        .about { color: #999; font-style: italic; font-size: .75em; text-align: center; }
        a { color: #666; text-decoration: none; } a:hover { text-decoration: underline; }
        ul { border-collapse: collapse; margin: 0; list-style: none; padding: 0; width: 100%;}
        li { border: 0; padding: 1em 0; margin: 0 0 .5em; }
        .swatch { border: 1px solid #f2f2f2; height: 4em; width: 100%; }
        .swatch-info { font-size: 1em; font-weight: normal; overflow: hidden; text-align: left; }
        .swatch-name { margin: 0; }
        .swatch-value { color: #ccc; font-size: .8125em; }
        input { border: none; color: inherit; font-size: inherit; outline: none; width: 100%; }
        input:focus { color: black }
        @media (min-width: 480px) {
            body { padding: 2em 0; }
            .swatch { float: left; margin-right: 1em; width: 8em; }
            .page { padding: 2em 4em; }
        }
        </style>
    </head>
    <body>
        <div class="page">
            <h1 class="headline">Swatches</h1>
            <ul>
HTML;
                                        foreach ($swatches as $swatch_name => $color) {
                                            $swatches_html.= <<<HTML
                <li>
                    <div class="swatch" style="background: {$color}"></div>
                    <div class="swatch-info">
                        <h3 class="swatch-name"><input value="@{$swatch_name}" onClick="event.preventDefault();this.setSelectionRange(0, this.value.length);" readonly="readonly" /></h3>
                        <input class="swatch-value" value="{$color}" onClick="event.preventDefault();this.setSelectionRange(0, this.value.length);" readonly="readonly" />
                    </div>
                </li>
HTML;
                                        }
                                        $swatches_html.= <<<HTML
            </ul>
        </div>
        <p class="about">Automatic Macaw Swatches by generated by <a href="https://github.com/attitude/publisher">Publisher</a>.</p>
    </body>
</html>
HTML;

                                        file_put_contents($asset_lock, $swatches_less);
                                        file_put_contents($asset_lock_html, $swatches_html);
                                    }
                                }

                                $mcw_files['assets'][$ext][] = sprintf($asset['template'], $absrel_url);
                            }
                        }
                    }
                }
            }
        }

        // Partial matches Macaw .html export filename
        if (isset($mcw_files['html'][$name])) {
            foreach ($mcw_files['html'][$name] as $html_file) {
                $html_file_mtime = filemtime($html_file);
                $mustache_file = "{$this->baseDir}/{$folder}/{$name}/{$this->basename}{$this->extension}";

                // Mustache already cached and newer
                if (file_exists($mustache_file) && filemtime($mustache_file) > $html_file_mtime) {
                    continue;
                }

                $html = $this->enhanceMacaw($html_file)."\n";

                if ($folder==='sections') {
                    $body_start = strpos($html, '<body');
                    if ($body_start!==false) {
                        if ($body_start = strpos($html, '>', $body_start)) {
                            if ($body_end = strpos($html, '</body>')) {
                                $l = $body_end - strlen($html);
                            } else {
                                $l = false;
                            }

                            $html = trim($l ? substr($html, ($body_start+1), $l) : substr($html, ($body_start+1)), "\n");
                        } else {
                            throw new HTTPException(500, 'The <body> content of Macaw export is zero length.');
                        }
                    }
                }

                // Append assets (Concatenate class takes care for duplicates)
                foreach ($mcw_files['assets'] as $type => $asset_files) {
                    $html.= implode("\n", $asset_files);
                }

                // Crete structure if not exists
                @mkdir(dirname($mustache_file), 0755, true);
                file_put_contents($mustache_file, $html);
            }
        }

        return parent::getFileName("{$folder}/{$name}");
    }

    /**
     * Replaces any variables in node text or in node attributes
     *
     * Examples:
     * - <input type="text" placeholder="[value]" />
     * - Some text node with [variable]
     *
     * Note: Supporting `_` is already covered by '\w', which is a word
     *       character, same as writin [_a-zA-Z0-9]
     *
     */
    protected function transcriptToMustache($str)
    {
        return preg_replace_callback('/\[(\[?[\.\w&;\/# -]+(?:\s*\|\s*[-_\w]+)?\]?)\]/', function($matches) {
            // Support for unescaped HTML as mustache {{{ variable }}} via [[ variable ]] in Macaw
            if ($matches[1][0]==='[' && $matches[1][(strlen($matches[1])-1)]===']') {
                $matches[1] = '{'.trim($matches[1], '[]').'}';
            }
            // Decode any `&entity;` by default
            $matches[1] = html_entity_decode($matches[1]);

            return '{{'. $matches[1] .'}}';
        }, $str);
    }

    /**
     * Helper to check array needles against the haystack
     *
     * @param $haystack string The input string
     */
    function strarray($haystack, array $needles, $match_any=false)
    {
        foreach ($needles as &$needle) {
            $found = !! strstr($haystack, $needle);

            // Logical AND
            if ($match_any && $found===true) {
                return true;
            }

            // Logical OR
            if (!$match_any && $found===false) {
                return false;
            }
        }

        return ! $match_any;
    }

    /**
     * Replace visually nice markup from Macaw
     *
     * Note: This not Mustache supported format, but the parent class
     * `AtomicLoader_FilesystemLoader` is able to generate translation
     * `{{#__}}...{{/__}}` and `{{#_n}}...{{/_n}}` landas/helpers which
     * are further processed by automatically registered helpers of
     * `DAtaPreprocessor_Component` (Mustache wrapper).
     *
     * Usage in Macaw:
     *
     * - simple string translation: ^String to translate^
     * - pluralisation: ^counter ? One string : {} strings^
     *
     * This method understands most of the possibilities of multiple `?` and `:`
     * in the string. However, if it fails, escape any `:` as `\:`. Only first
     * '?' is considered important.
     *
     * If you are ever in need of using `^` character, just use `\^` instead.
     *
     * @param  string $str HTML string
     * @return string      Modified HTML string
     *
     */
    protected function transcriptTranlations($str)
    {
        // Being benevolent here to allow designers to "write it pretty" untill necessary
        return str_replace(
            array('&#63;', '&#58;'),
            array('?', ':'),
            preg_replace_callback('/\^[^\^]+?\^/', function($match){
                $match = trim($match[0], ' ^');

                // Pluralisation: Fix multiple '?'
                if (substr_count($match, '?') && (substr_count($match, ':') - substr_count($match, '\:') > 0)) {
                    // Only the first '?' is vital
                    $origpos = strpos($match, '?');
                    $match[$origpos] = '^';
                    $match = str_replace('?', '&#63;', $match);
                    $match[$origpos] = '?';

                    list($match, $expression) = explode('?', $match);
                    $expression = trim($expression);

                    $expressions = array('', '');

                    // Tried to use quoted string
                    if ($expression[0]==='\'' || $expression[0]==="\"") {
                        $q = $expression[0];
                        $expression_len = strlen($expression);
                        $next = 1;

                        // Find next unescaped quote
                        while(($expression[$next]!==$q && $next!==false)) {
                            $next = strpos($expression, $q, $next);

                            if ($expression[($next-1)]==='\\') {
                                $next++;
                            }
                        }

                        $expressions[0] = substr($expression, 1, ($next-1));
                        $expression = trim(substr($expression, ($next+1)));

                        // Ignore the rest if missing ':'
                        if ($expression[0]!==':') {
                            $expressions[1] = $expressions[0];
                        } else {
                                          // Escape back
                            $expression = str_replace($q, "\\{$q}",
                                // Unescape all
                                str_replace(
                                    array('\\\'', "\\\""),
                                    array('\'', "\""),
                                    // Must use same quotes, so trim anyway
                                    trim($expression, ' :'.$q)
                                )
                            );

                            $expressions[1] = $expression;
                        }
                    } else {
                        if (strstr($expression, ' : ')) {
                            $expression = explode(' : ', $expression);
                        } else {
                            // Fix multiple ':'
                            if (substr_count($expression, ':') > 1) {
                                // Escaped
                                $expression = str_replace('\\:', '&#58;', $expression);

                                if (substr_count($expression, ':') > 1) {
                                    // Imediately after word
                                    $expression = preg_replace('/([^ ]):/', '$1&#58;', $expression);
                                }

                                if (substr_count($expression, ':') > 1) {
                                    // Keep just the first one
                                    $origpos = strpos($expression, ':');
                                    $expression[$origpos] = '^';
                                    $expression = str_replace(':', '&#58;', $expression);
                                    $expression[$origpos] = ':';
                                }
                            }

                            $expression = explode(':', $expression);
                        }

                        if (count($expression)===1) {
                            $expression[1] = $expression[0];
                        } else {
                            // Tried to escape second string?
                            if ($expression[1][0]==='\'' || $expression[1][0]==="\"") {
                                $q = $expression[1][0];
                            } else {
                                $q = '\'';
                            }

                                        // Escape back
                            $expression[1] = str_replace($q, "\\{$q}",
                              // Unescape all
                              str_replace(
                                  array('\\\'', "\\\""),
                                  array('\'', "\""),
                                  // Must use same quotes, so trim anyway
                                  trim($expression[1], ' :'.$q)
                              )
                            );
                        }
                        $expressions = $expression;
                    }

                    if (!isset($q)) { $q = '\''; }

                    $expression = $q.$expressions[0].$q.' : '.$q.$expressions[1].$q;

                    return "{{{$match} ? {$expression}}} | translate";
                }

                // Tried to escape this string?
                if ($match[0]==='\'' || $match[0]==="\"") {
                    $q = $match[0];
                } else {
                    $q = '\'';
                }

                            // Escape back
                $match = str_replace($q, "\\{$q}",
                  // Unescape all
                  str_replace(
                      array('\\\'', "\\\""),
                      array('\'', "\""),
                      // Must use same quotes, so trim anyway
                      trim($match, ' :'.$q)
                  )
                );

                return '{{'.$q.$match.$q.' | translate}}';
            }, str_replace('\\^', '&#94;', $str)
        ));
    }

    /**
     * Quick and dirty way to enhance exported Macaw HTML
     */
    protected function enhanceMacaw($html_file)
    {
        // Split HTML string: tag / text node as array item
        $html = explode("))((", str_replace(array(
                '>', '<'
            ), array(
                '>))((', '))((<',
            ), file_get_contents($html_file))
        );

        /**
         * Looking for any:
         *
         * - `class="events.repeat-event-in-events"`
         * - `class="events.repeat-event-in-parent-events"`
         * - `class="title.only-if-title"`
         * - `class="title.only-if-parent-title"`
         */
        $lookups = array('repeat', 'only-if');
        $lookup_class_regex = '('.implode('|', $lookups).')-([^\s]+)';

        $out       = array();
        $feature   = null;
        $open      = array();
        $last      = array();

        // Source: http://www.w3.org/TR/html5/syntax.html#void-elements
        $void_tags = array("area", "base", "br", "col", "embed", "hr", "img", "input", "keygen", "link", "meta", "param", "source", "track", "wbr");
        foreach ($html as $i => &$fragment) {
            if ($this->strarray($fragment, array('<link rel="stylesheet" href="css/standardize.css">', '<link rel="stylesheet" href="css/styles.css">'), true)) {
                // Handle previous white space;
                if (strlen(trim($html[($i-1)]))===0) {
                    array_pop($out);
                }

                // Skip writing CSS to $out
                continue;
            }

            // echo "\n\n".str_pad($i, 5, ' ', STR_PAD_LEFT). $fragment;
            // echo "\nOpen ";print_r($open);

            if ($this->strarray($fragment, array('<img','"images/'))) {
                $fragment = str_replace('"images/', '"'.str_replace($this->publicDir, $this->publicURL, dirname($html_file)).'/images/', $fragment);
            }

            // Open tag; Using strstr() which is faster than regex first
            if ($this->strarray($fragment, $lookups, true) && preg_match('/class=[\'"].*?'.$lookup_class_regex.'.*?[\'"]/', $fragment, $class)) {
                $feature = array(
                    'tag'      => null,
                    'name'     => $class[1],
                    // 'original' => $class[1].'-'.$class[2],
                    'item'     => '',
                    'context'  => ''
                );

                $lookup = $class[1];
                $class = trim(str_replace($lookups, '', $class[2]), '-');

                if (strstr($class, '-in-')) {
                    list($feature['item'], $feature['context']) = explode('-in-', $class);
                } else {
                    $feature['context'] = $class;
                }

                // Context stack

                // Marked as camelCase by class prefixed an suffixed with '_'
                // If you ever need to mix camelCase_byAnyChance, note, that
                // double`__` are kept as single `_`
                $feature['context'] = explode('-', $feature['context']);
                foreach ($feature['context'] as &$v) {
                    if ($v[0]==='_' && $v[(strlen($v)-1)]==='_') {
                        $v = explode('  ', str_replace('_', ' ', $v));
                        foreach ($v as &$_v) {
                            $_v = str_replace(' ', '', lcfirst(ucwords(trim($_v))));
                        }
                        $v = implode('_', $v);
                    }
                }

                $feature['context'] = implode('.', $feature['context']);
                // print_r($feature);

                // Tag to look for to close
                $feature['tag'] = explode(' ', trim($fragment, " \n\r\t</>"));
                // Cannot be in ine line
                // PHP Strict Standards:  Only variables should be passed by reference
                $feature['tag'] = array_shift($feature['tag']);


                // Pretty spaces
                $feature['spaces'] = $i===0 ? '' : preg_replace('/[^ ]/', '', $html[($i-1)]);

                if ($lookup === 'repeat') {
                    $hasSection = explode('.', $feature['context']);
                    $hasSection[(count($hasSection)-1)] = 'has'.ucfirst($hasSection[(count($hasSection)-1)]);
                    $feature['hasSection'] = implode('.', $hasSection);
                }

                // Write buffer with opening tag
                if (!isset($feature['hasSection'])) {
                    $out[] = "{{#{$feature['context']}}}\n{$feature['spaces']}";
                } elseif(!! DependencyContainer::get('Macaw::useHasSections', true)) {
                    $out[] = "{{#{$feature['hasSection']}}}\n{$feature['spaces']}";
                }

                if (in_array($feature['tag'], $void_tags)) {
                    exit('@TODO: Self-closing tags.');
                }

                $open[] = $feature;
                // echo "\n".'Open: ';print_r($feature);

                // Write buffer
                $out[] = $this->transcriptToMustache($fragment, $feature);

                if (isset($feature['hasSection'])) {
                    $out[] = "\n{$feature['spaces']}  {{#{$feature['context']}}}";
                }
            } elseif(count($open)) {
                if (strstr($fragment, '</')) {
                    // Level up
                    $feature = array_pop($open);
                    // echo "\n".'Close: ';print_r($feature);

                    // Found the closing tag match
                    if (trim($fragment, '</>') !== $feature['tag']) {
                        throw new HTTPException(500, 'Closing tags mismatch near tag `'.$fragment.'` vs '.$feature['tag']);
                    }

                    if (isset($feature['context'])) {
                        if (isset($feature['hasSection'])) {
                            $out[] = "  {{/{$feature['context']}}}\n{$feature['spaces']}";
                        }

                        $out[] = $this->transcriptToMustache($fragment, $feature);

                        // Write buffer with closing tag
                        if (!isset($feature['hasSection'])) {
                            $out[] = "\n{$feature['spaces']}{{/{$feature['context']}}}";
                        } elseif(!! DependencyContainer::get('Macaw::useHasSections', true)) {
                            $out[] = "\n{$feature['spaces']}{{/{$feature['hasSection']}}}";
                        }
                    } else {
                        $out[] = $this->transcriptToMustache($fragment, $feature);
                    }
                } elseif (strstr($fragment, '<')) {
                    // New child tag creates empty feature
                    $tag = explode(' ', trim($fragment, " \n\r\t</>"));
                    // Cannot be in ine line
                    // PHP Strict Standards:  Only variables should be passed by reference
                    $tag = array_shift($tag);

                    // Skip autoclosing tags
                    if (! in_array($tag, $void_tags)) {
                        $feature = array('tag' => $tag);
                        $open[] = $feature;

                        // echo "\n".'Open: ';print_r($feature);
                    }

                    // Write buffer
                    $out[] = $this->transcriptToMustache($fragment, $feature);
                } else {
                    // Write buffer
                    $out[] = $this->transcriptToMustache($fragment, $feature);
                }
            } else {
                // Write buffer
                $out[] = $this->transcriptToMustache($fragment, $feature);
            }
        }

        return $this->transcriptTranlations(implode('', $out));
    }
}
